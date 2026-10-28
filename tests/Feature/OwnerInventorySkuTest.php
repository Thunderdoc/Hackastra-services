<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Inventory items carry an optional SKU / item code (owner's
 * "SKU" note on Wave 2): admin-typed, shown in the list, never
 * required, never invented by the system.
 */
class OwnerInventorySkuTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_admin_can_create_item_with_sku_and_sees_it_in_list()
    {
        $response = $this->actingAs($this->admin())->post(route('inventory.store'), [
            'name' => 'SKU Probe Notebook',
            'sku' => 'NB-2026-001',
            'category' => 'stationery',
            'unit' => 'pcs',
            'low_stock_threshold' => 5,
        ]);
        $response->assertRedirect();
        $this->assertSame('NB-2026-001', InventoryItem::where('name', 'SKU Probe Notebook')->firstOrFail()->sku);

        $list = $this->actingAs($this->admin())->get(route('inventory.index'));
        $list->assertOk();
        $list->assertSee('NB-2026-001');
    }

    public function test_sku_is_optional()
    {
        $response = $this->actingAs($this->admin())->post(route('inventory.store'), [
            'name' => 'SKU-less Probe Item',
            'category' => 'other',
            'unit' => 'pcs',
            'low_stock_threshold' => 0,
        ]);
        $response->assertRedirect();
        $this->assertNull(InventoryItem::where('name', 'SKU-less Probe Item')->firstOrFail()->sku);
    }
}
