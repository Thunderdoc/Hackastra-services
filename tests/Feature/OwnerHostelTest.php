<?php

namespace Tests\Feature;

use App\Models\Dorm;
use App\Models\HostelRoom;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Hostel (Wave 2 feature 2): dorm + room admin CRUD, allocation onto
 * the EXISTING StudentRecord dorm_id / dorm_room_no fields, computed
 * occupancy, over-capacity refusal, deallocation, and the guarded
 * parent/student self view.
 *
 * Routes are NOT in routes/web.php yet (coordinator integrates them
 * centrally); this test registers the exact proposed route lines in
 * setUp so the controller + blades are verified as-is. Route names,
 * URIs, params and middleware below mirror the proposed lines
 * one-for-one. Users are resolved by email, never hardcoded ids.
 */
class OwnerHostelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Route::group(['namespace' => 'App\Http\Controllers\SupportTeam', 'middleware' => ['web', 'auth']], function () {
            Route::group(['prefix' => 'hostel'], function () {
                Route::get('dorms', 'HostelController@dormsIndex')->name('hostel.dorms.index')->middleware('teamSA');
                Route::post('dorms', 'HostelController@dormsStore')->name('hostel.dorms.store')->middleware('teamSAWrite');
                Route::get('dorms/{hostel_dorm}/edit', 'HostelController@dormsEdit')->name('hostel.dorms.edit')->middleware('teamSAWrite');
                Route::put('dorms/{hostel_dorm}', 'HostelController@dormsUpdate')->name('hostel.dorms.update')->middleware('teamSAWrite');
                Route::delete('dorms/{hostel_dorm}', 'HostelController@dormsDestroy')->name('hostel.dorms.destroy')->middleware('teamSAWrite');
                Route::get('rooms', 'HostelController@roomsIndex')->name('hostel.rooms.index')->middleware('teamSA');
                Route::post('rooms', 'HostelController@roomsStore')->name('hostel.rooms.store')->middleware('teamSAWrite');
                Route::get('rooms/{hostel_room}/edit', 'HostelController@roomsEdit')->name('hostel.rooms.edit')->middleware('teamSAWrite');
                Route::put('rooms/{hostel_room}', 'HostelController@roomsUpdate')->name('hostel.rooms.update')->middleware('teamSAWrite');
                Route::delete('rooms/{hostel_room}', 'HostelController@roomsDestroy')->name('hostel.rooms.destroy')->middleware('teamSAWrite');
                Route::get('allocations', 'HostelController@allocationsIndex')->name('hostel.allocations.index')->middleware('teamSA');
                Route::get('allocations/create', 'HostelController@allocationCreate')->name('hostel.allocations.create')->middleware('teamSA');
                Route::post('allocations', 'HostelController@allocationStore')->name('hostel.allocations.store')->middleware('teamSAWrite');
                Route::delete('allocations/{hostel_record}', 'HostelController@allocationDestroy')->name('hostel.allocations.destroy')->middleware('teamSAWrite');
                Route::get('my', 'HostelController@myAllocation')->name('hostel.my');
            });
        });

        // Route names are only indexed by refreshNameLookups, which the
        // provider runs once at boot — runtime routes need it re-run.
        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function parentUser()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function studentUser()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function childRecord()
    {
        return StudentRecord::where('my_parent_id', $this->parentUser()->id)->firstOrFail();
    }

    protected function nonChildRecord()
    {
        return StudentRecord::where(function ($q) {
            $q->whereNull('my_parent_id')->orWhere('my_parent_id', '!=', $this->parentUser()->id);
        })->firstOrFail();
    }

    protected function makeRoom(string $roomNo, int $capacity, ?Dorm $dorm = null): HostelRoom
    {
        return HostelRoom::create([
            'dorm_id' => ($dorm ?: Dorm::create(['name' => 'W2 Hostel Dorm ' . $roomNo]))->id,
            'room_no' => $roomNo,
            'capacity' => $capacity,
        ]);
    }

    protected function allocate(StudentRecord $record, HostelRoom $room)
    {
        return $this->actingAs($this->admin())->post(route('hostel.allocations.store'), [
            'student_record_id' => $record->id,
            'dorm_id' => $room->dorm_id,
            'room_no' => $room->room_no,
        ]);
    }

    public function test_admin_dorm_crud()
    {
        $this->actingAs($this->admin())
            ->post(route('hostel.dorms.store'), ['name' => 'W2 Test Dorm', 'description' => 'probe'])
            ->assertRedirect(route('hostel.dorms.index'));
        $dorm = Dorm::where('name', 'W2 Test Dorm')->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('hostel.dorms.index'))->assertOk()->assertSee('W2 Test Dorm');
        $this->actingAs($this->admin())
            ->get(route('hostel.dorms.edit', ['hostel_dorm' => $dorm->id]))->assertOk()->assertSee('W2 Test Dorm');
        $this->actingAs($this->admin())
            ->put(route('hostel.dorms.update', ['hostel_dorm' => $dorm->id]), ['name' => 'W2 Test Dorm Renamed', 'description' => 'probe'])
            ->assertRedirect(route('hostel.dorms.index'));
        $this->assertEquals('W2 Test Dorm Renamed', $dorm->fresh()->name);

        $this->actingAs($this->admin())
            ->delete(route('hostel.dorms.destroy', ['hostel_dorm' => $dorm->id]))
            ->assertRedirect();
        $this->assertNull(Dorm::where('name', 'W2 Test Dorm Renamed')->first());
    }

    public function test_admin_room_crud_and_empty_occupancy_display()
    {
        $dorm = Dorm::create(['name' => 'W2 Room Dorm']);
        $this->actingAs($this->admin())
            ->post(route('hostel.rooms.store'), ['dorm_id' => $dorm->id, 'room_no' => 'W2-R1', 'capacity' => 3])
            ->assertRedirect(route('hostel.rooms.index'));
        $room = HostelRoom::where('room_no', 'W2-R1')->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('hostel.rooms.index'))->assertOk()->assertSee('W2-R1')->assertSee('0 / 3');
        $this->actingAs($this->admin())
            ->get(route('hostel.rooms.edit', ['hostel_room' => $room->id]))->assertOk()->assertSee('W2-R1');
        $this->actingAs($this->admin())
            ->get(route('hostel.allocations.create'))->assertOk()->assertSee('Allocate Hostel Room');
        $this->actingAs($this->admin())
            ->put(route('hostel.rooms.update', ['hostel_room' => $room->id]), ['dorm_id' => $dorm->id, 'room_no' => 'W2-R1', 'capacity' => 4])
            ->assertRedirect(route('hostel.rooms.index'));
        $this->assertEquals(4, $room->fresh()->capacity);

        $this->actingAs($this->admin())
            ->delete(route('hostel.rooms.destroy', ['hostel_room' => $room->id]))
            ->assertRedirect();
        $this->assertNull(HostelRoom::where('room_no', 'W2-R1')->first());
    }

    public function test_allocation_sets_existing_student_record_fields()
    {
        $room = $this->makeRoom('W2-A1', 3);
        $record = $this->nonChildRecord();
        $this->assertNull($record->dorm_id);

        $this->allocate($record, $room)->assertRedirect(route('hostel.allocations.index'));

        $record->refresh();
        $this->assertEquals($room->dorm_id, $record->dorm_id);
        $this->assertEquals('W2-A1', $record->dorm_room_no);
        $this->actingAs($this->admin())
            ->get(route('hostel.allocations.index'))->assertOk()->assertSee('W2-A1');
    }

    public function test_occupancy_counts_correctly()
    {
        $room = $this->makeRoom('W2-O1', 3);
        $first = $this->nonChildRecord();
        $second = StudentRecord::where('id', '!=', $first->id)->firstOrFail();
        $this->allocate($first, $room)->assertRedirect();
        $this->allocate($second, $room)->assertRedirect();

        $this->assertEquals(2, $room->fresh()->occupancy());
        $this->actingAs($this->admin())
            ->get(route('hostel.rooms.index'))->assertOk()->assertSee('2 / 3');
    }

    public function test_over_capacity_allocation_refused()
    {
        $room = $this->makeRoom('W2-F1', 1);
        $first = $this->nonChildRecord();
        $second = StudentRecord::where('id', '!=', $first->id)->firstOrFail();
        $this->allocate($first, $room)->assertRedirect();

        $this->actingAs($this->admin())->post(route('hostel.allocations.store'), [
            'student_record_id' => $second->id,
            'dorm_id' => $room->dorm_id,
            'room_no' => $room->room_no,
        ])->assertSessionHasErrors('room_no');

        $this->assertNull($second->fresh()->dorm_id);
        $this->assertNull($second->fresh()->dorm_room_no);
        $this->assertEquals(1, $room->fresh()->occupancy());
    }

    public function test_allocation_to_room_of_another_dorm_refused()
    {
        $room = $this->makeRoom('W2-X1', 3);
        $otherDorm = Dorm::create(['name' => 'W2 Other Dorm']);
        $record = $this->nonChildRecord();

        $this->actingAs($this->admin())->post(route('hostel.allocations.store'), [
            'student_record_id' => $record->id,
            'dorm_id' => $otherDorm->id,
            'room_no' => $room->room_no,
        ])->assertSessionHasErrors('room_no');

        $this->assertNull($record->fresh()->dorm_id);
    }

    public function test_deallocation_clears_both_fields()
    {
        $room = $this->makeRoom('W2-D1', 2);
        $record = $this->nonChildRecord();
        $this->allocate($record, $room)->assertRedirect();
        $this->assertNotNull($record->fresh()->dorm_id);

        $this->actingAs($this->admin())
            ->delete(route('hostel.allocations.destroy', ['hostel_record' => $record->id]))
            ->assertRedirect();

        $record->refresh();
        $this->assertNull($record->dorm_id);
        $this->assertNull($record->dorm_room_no);
        $this->assertEquals(0, $room->fresh()->occupancy());
    }

    public function test_occupied_room_delete_refused_and_capacity_cut_refused()
    {
        $room = $this->makeRoom('W2-K1', 2);
        $first = $this->nonChildRecord();
        $second = StudentRecord::where('id', '!=', $first->id)->firstOrFail();
        $this->allocate($first, $room)->assertRedirect();
        $this->allocate($second, $room)->assertRedirect();

        $this->actingAs($this->admin())
            ->delete(route('hostel.rooms.destroy', ['hostel_room' => $room->id]))
            ->assertRedirect();
        $this->assertNotNull(HostelRoom::find($room->id));

        $this->actingAs($this->admin())
            ->put(route('hostel.rooms.update', ['hostel_room' => $room->id]), [
                'dorm_id' => $room->dorm_id, 'room_no' => $room->room_no, 'capacity' => 1,
            ])->assertSessionHasErrors('capacity');
        $this->assertEquals(2, $room->fresh()->capacity);
    }

    public function test_parent_sees_own_child_only()
    {
        $childRoom = $this->makeRoom('W2-PARENT-1', 3);
        $otherRoom = $this->makeRoom('W2-OTHER-1', 3);
        $this->allocate($this->childRecord(), $childRoom)->assertRedirect();
        $this->allocate($this->nonChildRecord(), $otherRoom)->assertRedirect();

        $this->actingAs($this->parentUser())
            ->get(route('hostel.my'))->assertOk()
            ->assertSee('W2-PARENT-1')
            ->assertDontSee('W2-OTHER-1');
    }

    public function test_student_sees_own_allocation_only()
    {
        $ownRoom = $this->makeRoom('W2-STUDENT-1', 3);
        $otherRoom = $this->makeRoom('W2-STRANGER-1', 3);
        $ownRecord = StudentRecord::where('user_id', $this->studentUser()->id)->firstOrFail();
        $this->allocate($ownRecord, $ownRoom)->assertRedirect();
        $this->allocate($this->nonChildRecord(), $otherRoom)->assertRedirect();

        $this->actingAs($this->studentUser())
            ->get(route('hostel.my'))->assertOk()
            ->assertSee('W2-STUDENT-1')
            ->assertDontSee('W2-STRANGER-1');
    }

    public function test_teacher_denied()
    {
        $this->actingAs($this->teacher())
            ->get(route('hostel.dorms.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())
            ->get(route('hostel.my'))
            ->assertForbidden();
    }
}
