<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\StudentRecord;
use App\Models\TransportAssignment;
use App\Models\TransportFee;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Wave 2 feature 1: Transport (admin-managed).
 *
 * Reads are teamSA (admin + SA), writes are teamSAWrite (admin only),
 * enforced by constructor middleware — the same split as DormController.
 * The parent view (mine) carries NO team middleware: it is guarded in
 * the controller (parent-only, own children only, like IdCardController@mine).
 * Teacher gets no access: team middleware redirects staff reads/writes
 * away, and mine() aborts 403 for non-parents.
 *
 * Route params are deliberately NOT {id} (RouteServiceProvider hash-binds
 * {id} only), so every param here is resolved with Qs::resolveId(),
 * which accepts a raw id or a Qs::hash() value.
 */
class TransportController extends Controller
{
    public function __construct()
    {
        // Limited principal role (owner order 2026-10-28): the
        // overview read is school-wide monitoring for the
        // principal; assignments/fees stay teamSA.
        $this->middleware('teamSA', ['only' => ['assignments', 'fees']]);
        $this->middleware('teamOrPrincipal:SA', ['only' => ['index']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'assignments', 'fees', 'mine']]);
    }

    /** Admin/SA overview: routes, vehicles, current assignments. */
    public function index()
    {
        $d['routes'] = TransportRoute::withCount(['stops', 'vehicles'])->orderBy('name')->get();
        $d['vehicles'] = TransportVehicle::with('route')->orderBy('vehicle_no')->get();
        $d['assignments'] = TransportAssignment::with(['studentRecord.user', 'studentRecord.my_class', 'vehicle.route', 'stop'])->orderBy('id')->get();

        return view('pages.support_team.transport.index', $d);
    }

    /* ---------------- Routes ---------------- */

    public function routeCreate()
    {
        return view('pages.support_team.transport.route_form', ['route' => null]);
    }

    public function routeStore(Request $req)
    {
        $data = $req->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);
        TransportRoute::create($data);

        return Qs::storeOk('transport.index');
    }

    public function routeEdit($transport_route)
    {
        $d['route'] = TransportRoute::with(['stops', 'vehicles'])->find(Qs::resolveId($transport_route));
        if (!$d['route']) {
            return Qs::goWithDanger('transport.index');
        }

        return view('pages.support_team.transport.route_form', $d);
    }

    public function routeUpdate(Request $req, $transport_route)
    {
        $route = TransportRoute::find(Qs::resolveId($transport_route));
        if (!$route) {
            return Qs::goWithDanger('transport.index');
        }
        $route->update($req->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]));

        return Qs::updateOk('transport.index');
    }

    public function routeDestroy($transport_route)
    {
        $route = TransportRoute::find(Qs::resolveId($transport_route));
        if ($route) {
            $route->delete();
        }

        return Qs::deleteOk('transport.index');
    }

    /* ---------------- Stops (nested under a route) ---------------- */

    public function stopStore(Request $req, $transport_route)
    {
        $route = TransportRoute::find(Qs::resolveId($transport_route));
        if (!$route) {
            return Qs::goWithDanger('transport.index');
        }
        $data = $req->validate([
            'name' => 'required|string|max:255',
            'sequence' => 'nullable|integer|min:0',
        ]);
        $data['transport_route_id'] = $route->id;
        $data['sequence'] = $data['sequence'] ?? 0;
        TransportStop::create($data);

        return Qs::storeOk(['transport.routes.edit', $route->id]);
    }

    public function stopDestroy($transport_stop)
    {
        $stop = TransportStop::find(Qs::resolveId($transport_stop));
        $routeId = $stop ? $stop->transport_route_id : null;
        if ($stop) {
            $stop->delete();
        }

        return $routeId
            ? Qs::deleteOk(['transport.routes.edit', $routeId])
            : Qs::goWithDanger('transport.index');
    }

    /* ---------------- Vehicles ---------------- */

    public function vehicleCreate()
    {
        $d['vehicle'] = null;
        $d['routes'] = TransportRoute::orderBy('name')->get();

        return view('pages.support_team.transport.vehicle_form', $d);
    }

    public function vehicleStore(Request $req)
    {
        $data = $req->validate([
            'vehicle_no' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
            'transport_route_id' => 'nullable|exists:transport_routes,id',
        ]);
        TransportVehicle::create($data);

        return Qs::storeOk('transport.index');
    }

    public function vehicleEdit($transport_vehicle)
    {
        $d['vehicle'] = TransportVehicle::find(Qs::resolveId($transport_vehicle));
        if (!$d['vehicle']) {
            return Qs::goWithDanger('transport.index');
        }
        $d['routes'] = TransportRoute::orderBy('name')->get();

        return view('pages.support_team.transport.vehicle_form', $d);
    }

    public function vehicleUpdate(Request $req, $transport_vehicle)
    {
        $vehicle = TransportVehicle::find(Qs::resolveId($transport_vehicle));
        if (!$vehicle) {
            return Qs::goWithDanger('transport.index');
        }
        $vehicle->update($req->validate([
            'vehicle_no' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
            'transport_route_id' => 'nullable|exists:transport_routes,id',
        ]));

        return Qs::updateOk('transport.index');
    }

    public function vehicleDestroy($transport_vehicle)
    {
        $vehicle = TransportVehicle::find(Qs::resolveId($transport_vehicle));
        if ($vehicle) {
            $vehicle->delete();
        }

        return Qs::deleteOk('transport.index');
    }

    /* ---------------- Assignments ---------------- */

    public function assignments()
    {
        $d['assignments'] = TransportAssignment::with(['studentRecord.user', 'studentRecord.my_class', 'vehicle.route', 'stop'])->orderBy('id')->get();
        $d['students'] = StudentRecord::with(['user', 'my_class'])->orderBy('id')->get();
        $d['vehicles'] = TransportVehicle::with('route')->orderBy('vehicle_no')->get();
        $d['stops'] = TransportStop::with('route')->orderBy('transport_route_id')->orderBy('sequence')->get();

        return view('pages.support_team.transport.assignments', $d);
    }

    public function assignmentStore(Request $req)
    {
        $data = $req->validate([
            'student_record_id' => 'required|exists:student_records,id',
            'transport_vehicle_id' => 'required|exists:transport_vehicles,id',
            'transport_stop_id' => 'required|exists:transport_stops,id',
        ]);

        $vehicle = TransportVehicle::find($data['transport_vehicle_id']);
        $stop = TransportStop::find($data['transport_stop_id']);

        // A stop is only usable on the route its vehicle runs.
        if (!$vehicle || !$stop || $vehicle->transport_route_id === null
            || (int) $stop->transport_route_id !== (int) $vehicle->transport_route_id) {
            return back()->withErrors(['transport_stop_id' => 'The selected stop does not belong to the vehicle\'s route.'])->withInput();
        }

        // At most one active assignment per student: reassign replaces.
        TransportAssignment::where('student_record_id', $data['student_record_id'])->delete();
        $assignment = TransportAssignment::create($data);
        Audit::log('transport.assigned', $assignment, 'Student '.$data['student_record_id'].' assigned to vehicle '.$data['transport_vehicle_id'].' stop '.$data['transport_stop_id']);

        return Qs::storeOk('transport.assignments');
    }

    public function assignmentDestroy($transport_assignment)
    {
        $assignment = TransportAssignment::find(Qs::resolveId($transport_assignment));
        if ($assignment) {
            Audit::log('transport.unassigned', $assignment, 'Student '.$assignment->student_record_id.' unassigned');
            $assignment->delete();
        }

        return Qs::deleteOk('transport.assignments');
    }

    /* ---------------- Fees ---------------- */

    public function fees(Request $req)
    {
        $d['fees'] = TransportFee::with(['assignment.studentRecord.user', 'assignment.vehicle', 'assignment.stop'])
            ->when($req->query('month'), function ($q, $month) {
                $q->where('month', $month);
            })
            ->orderBy('month')->orderBy('id')->get();
        $d['assignments'] = TransportAssignment::with(['studentRecord.user', 'vehicle', 'stop'])->orderBy('id')->get();
        $d['month'] = $req->query('month');

        return view('pages.support_team.transport.fees', $d);
    }

    public function feeStore(Request $req)
    {
        $data = $req->validate([
            'transport_assignment_id' => 'required|exists:transport_assignments,id',
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => 'required|numeric|min:0',
        ]);
        // One fee row per assignment per month: a repeat create is refused.
        $fee = TransportFee::firstOrCreate(
            ['transport_assignment_id' => $data['transport_assignment_id'], 'month' => $data['month']],
            ['amount' => $data['amount'], 'paid' => false]
        );
        if (!$fee->wasRecentlyCreated) {
            return back()->withErrors(['month' => 'A fee for this student and month already exists.'])->withInput();
        }
        Audit::log('transport.fee.created', $fee, 'Month '.$data['month'].' amount '.$data['amount']);

        return Qs::storeOk('transport.fees');
    }

    public function feeMarkPaid($transport_fee)
    {
        $fee = TransportFee::find(Qs::resolveId($transport_fee));
        if (!$fee) {
            return Qs::goWithDanger('transport.fees');
        }
        $fee->update(['paid' => true, 'paid_at' => now()]);
        Audit::log('transport.fee.paid', $fee, 'Month '.$fee->month.' amount '.$fee->amount);

        return Qs::updateOk('transport.fees');
    }

    /* ---------------- Parent view ---------------- */

    public function mine()
    {
        if (!Qs::userIsParent()) {
            abort(403);
        }
        $children = Qs::findMyChildren(Auth::id());
        $childIds = $children->pluck('id');
        $d['children'] = $children;
        $d['assignments'] = TransportAssignment::with(['studentRecord.user', 'studentRecord.my_class', 'vehicle.route', 'stop', 'fees'])
            ->whereIn('student_record_id', $childIds)
            ->orderBy('id')->get();

        return view('pages.support_team.transport.mine', $d);
    }
}
