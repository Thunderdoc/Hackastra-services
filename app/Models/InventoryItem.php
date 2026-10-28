<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wave 2 feature 6 — Inventory/store item (admin-managed).
 *
 * Stock is COMPUTED ONLY, never stored on this row: stock equals the
 * sum of 'in' movements minus the sum of 'out' movements. A stored
 * counter would drift from the movement log, so there is no stock
 * column on this table by design.
 */
class InventoryItem extends Model
{
    public const CATEGORIES = ['uniform', 'books', 'stationery', 'other'];

    protected $fillable = ['name', 'sku', 'category', 'unit', 'low_stock_threshold'];

    protected $casts = ['low_stock_threshold' => 'integer'];

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_item_id');
    }

    /** Current stock, computed from the movement log only. */
    public function currentStock(): int
    {
        $in = (int) $this->movements()->where('type', 'in')->sum('quantity');
        $out = (int) $this->movements()->where('type', 'out')->sum('quantity');

        return $in - $out;
    }

    /** Low stock when computed stock is at or below the threshold. */
    public function isLowStock(): bool
    {
        return $this->currentStock() <= (int) $this->low_stock_threshold;
    }

    public function categoryLabel(): string
    {
        return ucfirst($this->category);
    }
}
