<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Dorm;
use App\Models\HostelRoom;
use App\Models\StudentRecord;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HostelController extends Controller
{
    /**
     * Overnight menus 2026-10-29, item 20: the Hostel Warden runs
     * the hostel — the Admin is OUT (no menu link, and this gate
     * refuses admin server-side too). The routes carry no team
     * middleware any more (see web.php), so this is the gate:
     * reads allow the warden (+ super_admin, platform parity —
     * PlatformOnly redirects SA from school pages regardless);
     * writes are warden-only. The parent/student self view
     * (myAllocation) guards itself separately and is untouched.
     * The full warden role (user_types row, TeamHostel middleware,
     * warden dashboard landing) is still owed outside this
     * stream's file scope — see the stream report.
     */
    protected function guardHostel($write = false)
    {
        $type = auth()->user() ? auth()->user()->user_type : null;
        if ($write) {
            if ($type !== 'hostel') {
                abort(403, 'Hostel is managed by the Hostel Warden.');
            }
        } elseif (!in_array($type, ['hostel', 'super_admin'], true)) {
            abort(403, 'Hostel is managed by the Hostel Warden.');
        }
    }

    /* ---------------- Dorms (reuses the existing Dorm model) ---------------- */

    public function dormsIndex()
    {
        $this->guardHostel();
        $d['dorms'] = Dorm::orderBy('name')->get();
        return view('pages.support_team.hostel.dorms.index', $d);
    }

    public function dormsStore(Request $req)
    {
        $this->guardHostel(true);
        $data = $req->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);
        $dorm = Dorm::create($data);
        Audit::log('hostel.dorm_created', $dorm, 'Hostel dorm "' . $dorm->name . '" created');

        return Qs::storeOk('hostel.dorms.index');
    }

    public function dormsEdit($hostel_dorm)
    {
        $this->guardHostel(true);
        $d['dorm'] = $dorm = Dorm::find(Qs::resolveId($hostel_dorm));

        return !is_null($dorm) ? view('pages.support_team.hostel.dorms.edit', $d)
            : Qs::goWithDanger('hostel.dorms.index');
    }

    public function dormsUpdate(Request $req, $hostel_dorm)
    {
        $this->guardHostel(true);
        $data = $req->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);
        $dorm = Dorm::findOrFail(Qs::resolveId($hostel_dorm));
        $dorm->update($data);
        Audit::log('hostel.dorm_updated', $dorm, 'Hostel dorm "' . $dorm->name . '" updated');

        return Qs::updateOk('hostel.dorms.index');
    }

    public function dormsDestroy($hostel_dorm)
    {
        $this->guardHostel(true);
        $dorm = Dorm::findOrFail(Qs::resolveId($hostel_dorm));
        if (HostelRoom::where('dorm_id', $dorm->id)->exists()) {
            return back()->with('flash_danger', 'Cannot delete a dorm that still has rooms. Delete its rooms first.');
        }
        $name = $dorm->name;
        $dorm->delete();
        Audit::log('hostel.dorm_deleted', null, 'Hostel dorm "' . $name . '" deleted');

        return back()->with('flash_success', __('msg.delete_ok'));
    }

    /* ---------------- Rooms (occupancy computed, never stored) ---------------- */

    public function roomsIndex()
    {
        $this->guardHostel();
        $d['dorms'] = Dorm::orderBy('name')->get();
        $d['rooms'] = HostelRoom::with('dorm')->orderBy('dorm_id')->orderBy('room_no')->get();
        return view('pages.support_team.hostel.rooms.index', $d);
    }

    public function roomsStore(Request $req)
    {
        $this->guardHostel(true);
        $data = $req->validate([
            'dorm_id' => 'required|exists:dorms,id',
            'room_no' => [
                'required', 'string', 'max:50',
                Rule::unique('hostel_rooms')->where('dorm_id', $req->input('dorm_id')),
            ],
            'capacity' => 'required|integer|min:1',
        ]);
        $room = HostelRoom::create($data);
        Audit::log('hostel.room_created', $room, 'Hostel room "' . $room->room_no . '" created in dorm ' . $room->dorm_id);

        return Qs::storeOk('hostel.rooms.index');
    }

    public function roomsEdit($hostel_room)
    {
        $this->guardHostel(true);
        $d['room'] = $room = HostelRoom::find(Qs::resolveId($hostel_room));
        $d['dorms'] = Dorm::orderBy('name')->get();

        return !is_null($room) ? view('pages.support_team.hostel.rooms.edit', $d)
            : Qs::goWithDanger('hostel.rooms.index');
    }

    public function roomsUpdate(Request $req, $hostel_room)
    {
        $this->guardHostel(true);
        $room = HostelRoom::findOrFail(Qs::resolveId($hostel_room));
        $data = $req->validate([
            'dorm_id' => 'required|exists:dorms,id',
            'room_no' => [
                'required', 'string', 'max:50',
                Rule::unique('hostel_rooms')->where('dorm_id', $req->input('dorm_id'))->ignore($room->id),
            ],
            'capacity' => 'required|integer|min:1',
        ]);
        // A capacity cut below the occupants already in the (unchanged)
        // room is refused; renaming a room keeps its occupants with it,
        // so the same check on the new identity does not apply.
        if ($data['dorm_id'] == $room->dorm_id && $data['room_no'] == $room->room_no
            && $data['capacity'] < $room->occupancy()) {
            return back()->withErrors([
                'capacity' => 'Capacity cannot be below the current occupancy of ' . $room->occupancy() . ' student(s).',
            ])->withInput();
        }
        $room->update($data);
        Audit::log('hostel.room_updated', $room, 'Hostel room "' . $room->room_no . '" updated');

        return Qs::updateOk('hostel.rooms.index');
    }

    public function roomsDestroy($hostel_room)
    {
        $this->guardHostel(true);
        $room = HostelRoom::findOrFail(Qs::resolveId($hostel_room));
        if ($room->occupancy() > 0) {
            return back()->with('flash_danger', 'Cannot delete an occupied room. Deallocate its ' . $room->occupancy() . ' student(s) first.');
        }
        $label = $room->room_no;
        $room->delete();
        Audit::log('hostel.room_deleted', null, 'Hostel room "' . $label . '" deleted');

        return back()->with('flash_success', __('msg.delete_ok'));
    }

    /* ---------------- Allocation (existing StudentRecord fields only) ---------------- */

    public function allocationsIndex()
    {
        $this->guardHostel();
        $d['allocations'] = StudentRecord::with(['user', 'dorm'])
            ->whereNotNull('dorm_id')
            ->orderBy('dorm_id')->orderBy('dorm_room_no')
            ->get();
        return view('pages.support_team.hostel.allocations.index', $d);
    }

    public function allocationCreate()
    {
        $this->guardHostel();
        $d['students'] = StudentRecord::with('user')->orderBy('id')->get();
        $d['dorms'] = Dorm::orderBy('name')->get();
        $d['rooms'] = HostelRoom::with('dorm')->orderBy('dorm_id')->orderBy('room_no')->get();
        return view('pages.support_team.hostel.allocations.create', $d);
    }

    public function allocationStore(Request $req)
    {
        $this->guardHostel(true);
        $req->validate([
            'student_record_id' => 'required|exists:student_records,id',
            'dorm_id' => 'required|exists:dorms,id',
            'room_no' => 'required|string|max:50',
        ]);

        $record = StudentRecord::findOrFail($req->input('student_record_id'));

        // The room must exist AND belong to the chosen dorm.
        $room = HostelRoom::where('dorm_id', $req->input('dorm_id'))
            ->where('room_no', $req->input('room_no'))
            ->first();
        if (!$room) {
            return back()->withErrors([
                'room_no' => 'No such room in the selected dorm.',
            ])->withInput();
        }

        // Over-capacity is refused, never a silent overfill. The student
        // being (re-)allocated to the same room does not count against
        // themselves.
        $occupants = StudentRecord::where('dorm_id', $room->dorm_id)
            ->where('dorm_room_no', $room->room_no)
            ->where('id', '!=', $record->id)
            ->count();
        if ($occupants >= $room->capacity) {
            return back()->withErrors([
                'room_no' => 'Room ' . $room->room_no . ' is full (' . $occupants . '/' . $room->capacity . ' occupied).',
            ])->withInput();
        }

        $record->update([
            'dorm_id' => $room->dorm_id,
            'dorm_room_no' => $room->room_no,
        ]);
        Audit::log('hostel.allocated', $record, 'Student record ' . $record->id . ' allocated to dorm ' . $room->dorm_id . ', room ' . $room->room_no);

        return Qs::storeOk('hostel.allocations.index');
    }

    public function allocationDestroy($hostel_record)
    {
        $this->guardHostel(true);
        $record = StudentRecord::findOrFail(Qs::resolveId($hostel_record));
        $record->update(['dorm_id' => null, 'dorm_room_no' => null]);
        Audit::log('hostel.deallocated', $record, 'Student record ' . $record->id . ' deallocated from hostel');

        return back()->with('flash_success', __('msg.delete_ok'));
    }

    /* ---------------- Parent / student self view (guarded) ---------------- */

    public function myAllocation()
    {
        $user = auth()->user();

        if (Qs::userIsStudent()) {
            $record = Qs::findStudentRecord($user->id);
            $d['records'] = $record ? collect([$record->load(['user', 'dorm'])]) : collect();
        } elseif (Qs::userIsParent()) {
            $d['records'] = Qs::findMyChildren($user->id)->load(['user', 'dorm']);
        } else {
            abort(403);
        }

        return view('pages.support_team.hostel.my', $d);
    }
}
