<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Wave 2 feature 6 (Inventory/store): admin-managed items with
 * COMPUTED stock (in minus out from the movement log, never a stored
 * counter). Reads are teamSA, writes are teamSAWrite (admin only).
 *
 * Routes are normally registered by the central integration in
 * routes/web.php. Until that lands, this test registers the exact
 * same routes itself — but only when the names are absent — so the
 * suite passes both before and after integration, with no duplicate
 * registration.
 */
class OwnerInventoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Always register (never guarded on Route::has: across tests in
        // one process the facade can still see the previous test's
        // router, so the guard wrongly skips registration on test 2+).
        // If central integration already registered these names, this
        // registers identical duplicates — same URIs, same controller,
        // same middleware — so behaviour is unchanged either way.
        // 'web' mirrors real web.php routes (session + $errors sharing);
        // without it the master layout's $errors block is undefined.
        Route::group(['prefix' => 'inventory', 'middleware' => ['web', 'auth']], function () {
                $c = 'App\Http\Controllers\SupportTeam\InventoryController';
                Route::get('/', $c . '@index')->name('inventory.index')->middleware('teamSA');
                Route::get('/create', $c . '@create')->name('inventory.create')->middleware('teamSAWrite');
                Route::post('/', $c . '@store')->name('inventory.store')->middleware('teamSAWrite');
                Route::get('/{inventory_item}', $c . '@show')->name('inventory.show')->middleware('teamSA');
                Route::get('/{inventory_item}/edit', $c . '@edit')->name('inventory.edit')->middleware('teamSAWrite');
                Route::put('/{inventory_item}', $c . '@update')->name('inventory.update')->middleware('teamSAWrite');
                Route::delete('/{inventory_item}', $c . '@destroy')->name('inventory.destroy')->middleware('teamSAWrite');
                Route::post('/{inventory_item}/movements', $c . '@storeMovement')->name('inventory.movements.store')->middleware('teamSAWrite');
            });
        // The URL generator keeps the boot-time route collection, so
        // runtime-registered names are invisible to route() and to the
        // controller's redirect()->route() until it is rebound here.
        Route::getRoutes()->refreshNameLookups();
        app('url')->setRoutes(Route::getRoutes());
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function makeItem(array $overrides = [])
    {
        return InventoryItem::create(array_merge([
            'name' => 'School Shirt',
            'category' => 'uniform',
            'unit' => 'pcs',
            'low_stock_threshold' => 5,
        ], $overrides));
    }

    protected function move(InventoryItem $item, string $type, int $qty)
    {
        return InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type' => $type,
            'quantity' => $qty,
            'note' => null,
            'recorded_by' => $this->admin()->id,
            'movement_date' => now()->toDateString(),
        ]);
    }

    public function test_admin_creates_inventory_item()
    {
        $response = $this->actingAs($this->admin())->post(route('inventory.store'), [
            'name' => 'Maths Textbook',
            'category' => 'books',
            'unit' => 'pcs',
            'low_stock_threshold' => 10,
        ]);

        $response->assertRedirect(route('inventory.index'));
        $this->assertDatabaseHas('inventory_items', [
            'name' => 'Maths Textbook',
            'category' => 'books',
            'unit' => 'pcs',
            'low_stock_threshold' => 10,
        ]);
    }

    public function test_admin_updates_inventory_item()
    {
        $item = $this->makeItem();

        $response = $this->actingAs($this->admin())->put(
            route('inventory.update', ['inventory_item' => $item->id]),
            ['name' => 'School Shirt XL', 'category' => 'uniform', 'unit' => 'pcs', 'low_stock_threshold' => 3]
        );

        $response->assertRedirect(route('inventory.index'));
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'name' => 'School Shirt XL', 'low_stock_threshold' => 3]);
    }

    public function test_admin_deletes_item_without_movements()
    {
        $item = $this->makeItem();

        $response = $this->actingAs($this->admin())->delete(
            route('inventory.destroy', ['inventory_item' => $item->id])
        );

        $response->assertRedirect(route('inventory.index'));
        $this->assertDatabaseMissing('inventory_items', ['id' => $item->id]);
    }

    public function test_item_with_movements_cannot_be_deleted()
    {
        $item = $this->makeItem();
        $this->move($item, 'in', 5);

        $this->actingAs($this->admin())->delete(
            route('inventory.destroy', ['inventory_item' => $item->id])
        );

        $this->assertDatabaseHas('inventory_items', ['id' => $item->id]);
    }

    public function test_stock_in_and_out_change_computed_stock()
    {
        $item = $this->makeItem();

        $this->actingAs($this->admin())->post(
            route('inventory.movements.store', ['inventory_item' => $item->id]),
            ['type' => 'in', 'quantity' => 20, 'movement_date' => now()->toDateString()]
        )->assertRedirect(route('inventory.show', ['inventory_item' => $item->id]));
        $this->assertSame(20, $item->fresh()->currentStock());

        $this->actingAs($this->admin())->post(
            route('inventory.movements.store', ['inventory_item' => $item->id]),
            ['type' => 'out', 'quantity' => 7, 'movement_date' => now()->toDateString()]
        )->assertRedirect(route('inventory.show', ['inventory_item' => $item->id]));
        $this->assertSame(13, $item->fresh()->currentStock());

        // No stock column exists on the item row: the log is the truth.
        $this->assertArrayNotHasKey('stock', $item->fresh()->getAttributes());
    }

    public function test_stock_out_beyond_current_stock_is_refused()
    {
        $item = $this->makeItem();
        $this->move($item, 'in', 5);

        $response = $this->actingAs($this->admin())->post(
            route('inventory.movements.store', ['inventory_item' => $item->id]),
            ['type' => 'out', 'quantity' => 6, 'movement_date' => now()->toDateString()]
        );

        $response->assertSessionHasErrors('quantity');
        $this->assertDatabaseMissing('inventory_movements', [
            'inventory_item_id' => $item->id, 'type' => 'out', 'quantity' => 6,
        ]);
        $this->assertSame(5, $item->fresh()->currentStock());
    }

    public function test_zero_quantity_movement_is_refused()
    {
        $item = $this->makeItem();

        $this->actingAs($this->admin())->post(
            route('inventory.movements.store', ['inventory_item' => $item->id]),
            ['type' => 'in', 'quantity' => 0, 'movement_date' => now()->toDateString()]
        )->assertSessionHasErrors('quantity');
        $this->assertSame(0, $item->fresh()->currentStock());
    }

    public function test_low_stock_flag_triggers_at_and_below_threshold()
    {
        $atThreshold = $this->makeItem(['name' => 'At Threshold', 'low_stock_threshold' => 5]);
        $this->move($atThreshold, 'in', 5);
        $this->assertTrue($atThreshold->fresh()->isLowStock());
        $this->actingAs($this->admin())
            ->get(route('inventory.show', ['inventory_item' => $atThreshold->id]))
            ->assertOk()->assertSee('Low Stock');

        $above = $this->makeItem(['name' => 'Above Threshold', 'low_stock_threshold' => 5]);
        $this->move($above, 'in', 6);
        $this->assertFalse($above->fresh()->isLowStock());
        $this->actingAs($this->admin())
            ->get(route('inventory.show', ['inventory_item' => $above->id]))
            ->assertOk()->assertDontSee('Low Stock');

        // The index low-stock filter keeps only the at-threshold item.
        $this->actingAs($this->admin())
            ->get(route('inventory.index', ['low_stock' => '1']))
            ->assertOk()->assertSee('At Threshold')->assertDontSee('Above Threshold');
    }

    public function test_movement_history_lists_per_item()
    {
        $item = $this->makeItem();
        $other = $this->makeItem(['name' => 'Other Item']);
        $this->move($item, 'in', 10);
        $this->move($other, 'in', 99);

        $response = $this->actingAs($this->admin())
            ->get(route('inventory.show', ['inventory_item' => $item->id]));

        // The other item's name (and its 99-unit movement) must not leak
        // into this item's history page.
        $response->assertOk()->assertSee('Stock In')->assertDontSee('Other Item');
    }

    public function test_create_and_edit_pages_render()
    {
        $item = $this->makeItem();

        $this->actingAs($this->admin())->get(route('inventory.create'))
            ->assertOk()->assertSee('Add Inventory Item')->assertSee('Low-stock threshold');
        $this->actingAs($this->admin())
            ->get(route('inventory.edit', ['inventory_item' => $item->id]))
            ->assertOk()->assertSee('Edit Inventory Item')->assertSee('School Shirt');
    }

    public function test_teacher_is_denied_read_and_write()
    {
        $item = $this->makeItem();

        $this->actingAs($this->teacher())->get(route('inventory.index'))->assertRedirect(route('login'));
        $this->actingAs($this->teacher())->post(route('inventory.store'), [
            'name' => 'Nope', 'category' => 'other', 'unit' => 'pcs', 'low_stock_threshold' => 0,
        ])->assertRedirect(route('login'));
        $this->actingAs($this->teacher())->post(
            route('inventory.movements.store', ['inventory_item' => $item->id]),
            ['type' => 'in', 'quantity' => 1]
        )->assertRedirect(route('login'));
        $this->assertDatabaseMissing('inventory_items', ['name' => 'Nope']);
    }

    public function test_super_admin_is_blocked_by_platform_boundary()
    {
        // PlatformOnly (web group, allowlist untouched): the Super Admin
        // is a platform account and never needs school routes — GETs
        // redirect to the platform dashboard, non-GETs get a hard 403.
        $item = $this->makeItem();

        $this->actingAs($this->superAdmin())->get(route('inventory.index'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())->post(route('inventory.store'), [
            'name' => 'SA Write', 'category' => 'other', 'unit' => 'pcs', 'low_stock_threshold' => 0,
        ])->assertForbidden();
        $this->actingAs($this->superAdmin())->post(
            route('inventory.movements.store', ['inventory_item' => $item->id]),
            ['type' => 'in', 'quantity' => 1]
        )->assertForbidden();
        $this->assertDatabaseMissing('inventory_items', ['name' => 'SA Write']);
    }
}
