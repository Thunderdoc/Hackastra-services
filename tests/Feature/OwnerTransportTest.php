<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportTeam\TransportController;
use App\Models\StudentRecord;
use App\Models\TransportAssignment;
use App\Models\TransportFee;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Wave 2 feature 1: Transport (admin-managed).
 *
 * NOTE: routes/web.php integration is the coordinator's job (this
 * workstream must not edit web.php), so these tests register the exact
 * proposed route lines in setUp() — same URIs, names, controller
 * methods and middleware — and exercise them through route() helpers.
 * When the coordinator pastes the lines into web.php, the test's
 * registration is redundant but harmless (same names/URIs).
 */
class OwnerTransportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // 'web' mirrors the real web.php stack (RouteServiceProvider
        // applies it to the whole file): it supplies session + $errors
        // that layouts.master expects; without it every view 500s.
        Route::group(['middleware' => ['web', 'auth'], 'prefix' => 'transport'], function () {
            Route::get('/', [TransportController::class, 'index'])->name('transport.index')->middleware('teamSA');
            Route::get('routes/create', [TransportController::class, 'routeCreate'])->name('transport.routes.create')->middleware('teamSAWrite');
            Route::post('routes', [TransportController::class, 'routeStore'])->name('transport.routes.store')->middleware('teamSAWrite');
            Route::get('routes/{transport_route}/edit', [TransportController::class, 'routeEdit'])->name('transport.routes.edit')->middleware('teamSAWrite');
            Route::put('routes/{transport_route}', [TransportController::class, 'routeUpdate'])->name('transport.routes.update')->middleware('teamSAWrite');
            Route::delete('routes/{transport_route}', [TransportController::class, 'routeDestroy'])->name('transport.routes.destroy')->middleware('teamSAWrite');
            Route::post('routes/{transport_route}/stops', [TransportController::class, 'stopStore'])->name('transport.stops.store')->middleware('teamSAWrite');
            Route::delete('stops/{transport_stop}', [TransportController::class, 'stopDestroy'])->name('transport.stops.destroy')->middleware('teamSAWrite');
            Route::get('vehicles/create', [TransportController::class, 'vehicleCreate'])->name('transport.vehicles.create')->middleware('teamSAWrite');
            Route::post('vehicles', [TransportController::class, 'vehicleStore'])->name('transport.vehicles.store')->middleware('teamSAWrite');
            Route::get('vehicles/{transport_vehicle}/edit', [TransportController::class, 'vehicleEdit'])->name('transport.vehicles.edit')->middleware('teamSAWrite');
            Route::put('vehicles/{transport_vehicle}', [TransportController::class, 'vehicleUpdate'])->name('transport.vehicles.update')->middleware('teamSAWrite');
            Route::delete('vehicles/{transport_vehicle}', [TransportController::class, 'vehicleDestroy'])->name('transport.vehicles.destroy')->middleware('teamSAWrite');
            Route::get('assignments', [TransportController::class, 'assignments'])->name('transport.assignments')->middleware('teamSA');
            Route::post('assignments', [TransportController::class, 'assignmentStore'])->name('transport.assignments.store')->middleware('teamSAWrite');
            Route::delete('assignments/{transport_assignment}', [TransportController::class, 'assignmentDestroy'])->name('transport.assignments.destroy')->middleware('teamSAWrite');
            Route::get('fees', [TransportController::class, 'fees'])->name('transport.fees')->middleware('teamSA');
            Route::post('fees', [TransportController::class, 'feeStore'])->name('transport.fees.store')->middleware('teamSAWrite');
            Route::post('fees/{transport_fee}/mark-paid', [TransportController::class, 'feeMarkPaid'])->name('transport.fees.mark_paid')->middleware('teamSAWrite');
            Route::get('mine', [TransportController::class, 'mine'])->name('transport.mine');
        });
        // Names are set fluently AFTER the route is added to the
        // collection, so refresh the name look-up table (the same
        // refresh Laravel runs after loading route files).
        Route::getRoutes()->refreshNameLookups();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->first();
    }

    protected function makeRoute($name = 'North Route')
    {
        return TransportRoute::create(['name' => $name, 'description' => 'Test route']);
    }

    protected function makeVehicle(TransportRoute $route, $no = 'TN-59-TEST-1')
    {
        return TransportVehicle::create(['vehicle_no' => $no, 'capacity' => 40, 'transport_route_id' => $route->id]);
    }

    protected function makeStop(TransportRoute $route, $name = 'Main Gate', $seq = 1)
    {
        return TransportStop::create(['transport_route_id' => $route->id, 'name' => $name, 'sequence' => $seq]);
    }


    public function test_admin_route_stop_vehicle_crud()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('transport.routes.store'), ['name' => 'East Route', 'description' => 'East side']);
        $this->assertDatabaseHas('transport_routes', ['name' => 'East Route']);
        $route = TransportRoute::where('name', 'East Route')->first();

        $this->actingAs($admin)->put(route('transport.routes.update', ['transport_route' => $route->id]), ['name' => 'East Route Renamed', 'description' => 'East side']);
        $this->assertDatabaseHas('transport_routes', ['name' => 'East Route Renamed']);

        $this->actingAs($admin)->post(route('transport.stops.store', ['transport_route' => $route->id]), ['name' => 'Depot', 'sequence' => 1]);
        $this->assertDatabaseHas('transport_stops', ['transport_route_id' => $route->id, 'name' => 'Depot']);
        $stop = TransportStop::where('transport_route_id', $route->id)->first();
        $this->actingAs($admin)->delete(route('transport.stops.destroy', ['transport_stop' => $stop->id]));
        $this->assertDatabaseMissing('transport_stops', ['id' => $stop->id]);

        $this->actingAs($admin)->post(route('transport.vehicles.store'), ['vehicle_no' => 'TN-59-V-1', 'capacity' => 30, 'transport_route_id' => $route->id]);
        $this->assertDatabaseHas('transport_vehicles', ['vehicle_no' => 'TN-59-V-1']);
        $vehicle = TransportVehicle::where('vehicle_no', 'TN-59-V-1')->first();
        $this->actingAs($admin)->put(route('transport.vehicles.update', ['transport_vehicle' => $vehicle->id]), ['vehicle_no' => 'TN-59-V-1', 'capacity' => 35, 'transport_route_id' => $route->id]);
        $this->assertDatabaseHas('transport_vehicles', ['vehicle_no' => 'TN-59-V-1', 'capacity' => 35]);

        $this->actingAs($admin)->get(route('transport.index'))->assertOk();
        $this->actingAs($admin)->get(route('transport.routes.edit', ['transport_route' => $route->id]))->assertOk();
        $this->actingAs($admin)->get(route('transport.vehicles.edit', ['transport_vehicle' => $vehicle->id]))->assertOk();
        $this->actingAs($admin)->delete(route('transport.vehicles.destroy', ['transport_vehicle' => $vehicle->id]));
        $this->assertDatabaseMissing('transport_vehicles', ['id' => $vehicle->id]);
        $this->actingAs($admin)->delete(route('transport.routes.destroy', ['transport_route' => $route->id]));
        $this->assertDatabaseMissing('transport_routes', ['id' => $route->id]);
    }

    public function test_assignment_wrong_route_stop_refused()
    {
        $routeA = $this->makeRoute('Route A');
        $routeB = $this->makeRoute('Route B');
        $vehicle = $this->makeVehicle($routeA);
        $foreignStop = $this->makeStop($routeB, 'Other Side');
        $student = StudentRecord::where('my_parent_id', $this->parent()->id)->first();

        $response = $this->actingAs($this->admin())->post(route('transport.assignments.store'), [
            'student_record_id' => $student->id,
            'transport_vehicle_id' => $vehicle->id,
            'transport_stop_id' => $foreignStop->id,
        ]);
        $response->assertSessionHasErrors('transport_stop_id');
        $this->assertDatabaseMissing('transport_assignments', ['student_record_id' => $student->id]);
    }

    public function test_assignment_reassign_replaces_not_duplicates()
    {
        $route = $this->makeRoute('Loop Route');
        $vehicle1 = $this->makeVehicle($route, 'TN-59-V-1');
        $vehicle2 = $this->makeVehicle($route, 'TN-59-V-2');
        $stop1 = $this->makeStop($route, 'Stop One', 1);
        $stop2 = $this->makeStop($route, 'Stop Two', 2);
        $student = StudentRecord::where('my_parent_id', $this->parent()->id)->first();

        $this->actingAs($this->admin())->post(route('transport.assignments.store'), [
            'student_record_id' => $student->id,
            'transport_vehicle_id' => $vehicle1->id,
            'transport_stop_id' => $stop1->id,
        ]);
        $this->actingAs($this->admin())->post(route('transport.assignments.store'), [
            'student_record_id' => $student->id,
            'transport_vehicle_id' => $vehicle2->id,
            'transport_stop_id' => $stop2->id,
        ]);

        $this->actingAs($this->admin())->get(route('transport.assignments'))->assertOk();
        $this->assertEquals(1, TransportAssignment::where('student_record_id', $student->id)->count());
        $this->assertDatabaseHas('transport_assignments', [
            'student_record_id' => $student->id,
            'transport_vehicle_id' => $vehicle2->id,
            'transport_stop_id' => $stop2->id,
        ]);
    }

    public function test_fee_create_and_mark_paid()
    {
        $route = $this->makeRoute('Fee Route');
        $vehicle = $this->makeVehicle($route);
        $stop = $this->makeStop($route);
        $student = StudentRecord::where('my_parent_id', $this->parent()->id)->first();
        $assignment = TransportAssignment::create([
            'student_record_id' => $student->id,
            'transport_vehicle_id' => $vehicle->id,
            'transport_stop_id' => $stop->id,
        ]);

        $this->actingAs($this->admin())->post(route('transport.fees.store'), [
            'transport_assignment_id' => $assignment->id,
            'month' => '2026-10',
            'amount' => 1500,
        ]);
        $this->assertDatabaseHas('transport_fees', ['transport_assignment_id' => $assignment->id, 'month' => '2026-10', 'paid' => false]);
        $fee = TransportFee::where('transport_assignment_id', $assignment->id)->first();

        $this->actingAs($this->admin())->post(route('transport.fees.mark_paid', ['transport_fee' => $fee->id]));
        $fee->refresh();
        $this->assertTrue((bool) $fee->paid);
        $this->assertNotNull($fee->paid_at);
        $this->actingAs($this->admin())->get(route('transport.fees'))->assertOk();
        $this->actingAs($this->admin())->get(route('transport.fees', ['month' => '2026-10']))->assertOk();
    }

    public function test_parent_sees_own_child_only()
    {
        $parent = $this->parent();
        $route = $this->makeRoute('Parent Route');
        $vehicle = $this->makeVehicle($route);
        $stop = $this->makeStop($route);
        $ownChild = StudentRecord::with('user')->where('my_parent_id', $parent->id)->first();
        $this->assertNotNull($ownChild);
        // Seed links children only to parent 5, so build a genuine
        // other-parent child (same pattern as OwnerWave9Test).
        $otherParent = User::create([
            'name' => 'Transport Other Parent', 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'parent', 'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'password' => \Illuminate\Support\Facades\Hash::make('parent'),
            'username' => 'TEST/TRANS/PARENT',
        ]);
        $otherStudent = User::create([
            'name' => 'Transport Other Child', 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Male', 'address' => 'Test Address',
            'user_type' => 'student', 'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'password' => \Illuminate\Support\Facades\Hash::make('student'),
            'username' => 'TEST/TRANS/CHILD',
        ]);
        $otherChild = StudentRecord::create([
            'user_id' => $otherStudent->id, 'my_class_id' => 1, 'section_id' => 1,
            'my_parent_id' => $otherParent->id, 'adm_no' => 'TEST/TRANS/CHILD',
            'session' => '2026-2027', 'year_admitted' => '2026',
        ]);
        $otherChild->load('user');
        TransportAssignment::create(['student_record_id' => $ownChild->id, 'transport_vehicle_id' => $vehicle->id, 'transport_stop_id' => $stop->id]);
        TransportAssignment::create(['student_record_id' => $otherChild->id, 'transport_vehicle_id' => $vehicle->id, 'transport_stop_id' => $stop->id]);

        $response = $this->actingAs($parent)->get(route('transport.mine'));
        $response->assertOk();
        $response->assertSee($ownChild->user->name);
        $response->assertDontSee($otherChild->user->name);
    }

    public function test_teacher_denied()
    {
        // Parent view is controller-guarded: non-parent gets a hard 403.
        $this->actingAs($this->teacher())->get(route('transport.mine'))->assertForbidden();
        // Admin reads are teamSA-gated: a teacher is bounced to login.
        $this->actingAs($this->teacher())->get(route('transport.index'))->assertRedirect(route('login'));
        $this->actingAs($this->teacher())->post(route('transport.routes.store'), ['name' => 'Nope'])->assertRedirect(route('login'));
    }
}
