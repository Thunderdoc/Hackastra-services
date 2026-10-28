<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * Wave 2 feature 6 — Inventory/store (admin-managed).
 * Reads: teamSA (admin + super admin). Writes: teamSAWrite (admin
 * only). Stock is computed from movements only; stock-out beyond
 * current computed stock is refused, so stock can never go negative.
 * Route params use {inventory_item}, never {id}, so the hash-binding
 * on {id} params does not apply; raw ids are resolved here.
 */
class InventoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA')->only(['index', 'show']);
        $this->middleware('teamSAWrite')->only([
            'create', 'store', 'edit', 'update', 'destroy', 'storeMovement',
        ]);
    }

    public function index(Request $request)
    {
        $items = InventoryItem::orderBy('name')->get();

        // Attach computed stock once per item, then filter in memory:
        // low-stock is a computed property, so it cannot be a SQL
        // WHERE clause without duplicating the stock formula.
        $rows = $items->map(function (InventoryItem $item) {
            $item->computed_stock = $item->currentStock();
            $item->low_stock = $item->computed_stock <= (int) $item->low_stock_threshold;

            return $item;
        });

        if ($request->filled('q')) {
            $q = mb_strtolower($request->input('q'));
            $rows = $rows->filter(function (InventoryItem $item) use ($q) {
                return mb_strpos(mb_strtolower($item->name), $q) !== false
                    || mb_strpos(mb_strtolower($item->unit), $q) !== false;
            });
        }
        if (in_array($request->input('category'), InventoryItem::CATEGORIES, true)) {
            $rows = $rows->where('category', $request->input('category'));
        }
        if ($request->input('low_stock') === '1') {
            $rows = $rows->where('low_stock', true);
        }

        $rows = $rows->values();
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 15;
        $paginated = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('pages.support_team.inventory.index', [
            'items' => $paginated,
            'q' => $request->input('q'),
            'category' => $request->input('category', 'all'),
            'low_stock' => $request->input('low_stock') === '1',
            'categories' => InventoryItem::CATEGORIES,
            'low_stock_count' => $items->filter(function (InventoryItem $item) {
                return $item->currentStock() <= (int) $item->low_stock_threshold;
            })->count(),
        ]);
    }

    public function create()
    {
        return view('pages.support_team.inventory.create', [
            'categories' => InventoryItem::CATEGORIES,
        ]);
    }

    public function store(Request $request)
    {
        $item = InventoryItem::create($this->validateItem($request));

        Audit::log('inventory.item_created', $item, 'Inventory item "' . $item->name . '" created');

        return redirect()->route('inventory.index')->with('flash_success', 'Inventory item added.');
    }

    public function show($inventory_item)
    {
        $item = InventoryItem::findOrFail($inventory_item);
        $item->computed_stock = $item->currentStock();
        $item->low_stock = $item->computed_stock <= (int) $item->low_stock_threshold;

        return view('pages.support_team.inventory.show', [
            'item' => $item,
            'movements' => $item->movements()->with('recorder')->orderByDesc('movement_date')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function edit($inventory_item)
    {
        return view('pages.support_team.inventory.edit', [
            'item' => InventoryItem::findOrFail($inventory_item),
            'categories' => InventoryItem::CATEGORIES,
        ]);
    }

    public function update(Request $request, $inventory_item)
    {
        $item = InventoryItem::findOrFail($inventory_item);
        $item->update($this->validateItem($request));

        Audit::log('inventory.item_updated', $item, 'Inventory item "' . $item->name . '" updated');

        return redirect()->route('inventory.index')->with('flash_success', 'Inventory item updated.');
    }

    public function destroy($inventory_item)
    {
        $item = InventoryItem::findOrFail($inventory_item);

        // The movement log is the audit trail: an item with movements
        // keeps its history and cannot be deleted.
        if ($item->movements()->exists()) {
            return back()->with('flash_danger', 'This item has stock movements on record and cannot be deleted.');
        }

        $name = $item->name;
        $item->delete();

        Audit::log('inventory.item_deleted', null, 'Inventory item "' . $name . '" deleted');

        return redirect()->route('inventory.index')->with('flash_success', 'Inventory item deleted.');
    }

    public function storeMovement(Request $request, $inventory_item)
    {
        $item = InventoryItem::findOrFail($inventory_item);

        $data = $request->validate([
            'type' => 'required|in:' . implode(',', InventoryMovement::TYPES),
            'quantity' => 'required|integer|min:1',
            'note' => 'nullable|string|max:1000',
            'movement_date' => 'nullable|date',
        ]);

        // Never negative stock: refuse an out-movement larger than the
        // current computed stock with a validation error.
        if ($data['type'] === 'out' && $data['quantity'] > $item->currentStock()) {
            return back()->withInput()->withErrors([
                'quantity' => 'Stock-out of ' . $data['quantity'] . ' exceeds current stock of ' . $item->currentStock() . ' ' . $item->unit . '.',
            ]);
        }

        $movement = InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type' => $data['type'],
            'quantity' => $data['quantity'],
            'note' => $data['note'] ?? null,
            'recorded_by' => Auth::id(),
            'movement_date' => $data['movement_date'] ?? now()->toDateString(),
        ]);

        Audit::log(
            $data['type'] === 'in' ? 'inventory.stock_in' : 'inventory.stock_out',
            $movement,
            'Stock ' . $data['type'] . ': ' . $data['quantity'] . ' ' . $item->unit . ' of "' . $item->name . '" (new stock ' . $item->currentStock() . ')'
        );

        return redirect()->route('inventory.show', ['inventory_item' => $item->id])
            ->with('flash_success', 'Stock movement recorded.');
    }

    protected function validateItem(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100',
            'category' => 'required|in:' . implode(',', InventoryItem::CATEGORIES),
            'unit' => 'required|string|max:50',
            'low_stock_threshold' => 'required|integer|min:0',
        ]);
    }
}
