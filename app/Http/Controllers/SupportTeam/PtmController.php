<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\Support\Notify;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PTM Scheduling (Batch 101).
 *
 * Controller-guarded (no team middleware — parents must reach it):
 *  - index: admin, teacher, parent.
 *  - create/store: admin only (overnight menus 2026-10-29, item 18:
 *    scheduling moved to admin; teachers no longer create slots).
 *  - book: parent only, for their own child only.
 *  - cancel: the booking parent, an admin, or the slot's teacher.
 *
 * Decisions (documented per the task brief):
 *  - Teacher scope (retired for creation, overnight menus
 *    2026-10-29): App\Support\TeacherScope::canTeachClass used to
 *    let a teacher create slots only for classes they teach;
 *    creation is now admin-only, so that rule grants nothing on
 *    its own anymore. Teachers keep viewing their own PTM slots
 *    and bookings, and cancelling their own slots.
 *  - Double-booking is refused cleanly, never duplicated: an
 *    exists-check before insert AND a UNIQUE constraint on
 *    ptm_bookings.ptm_slot_id (a race still hits the constraint
 *    and is turned into the same friendly refusal).
 *  - Cancel DELETES the booking row (no cancelled flag). The slot
 *    is then simply unbooked, so re-booking needs no status
 *    filtering anywhere and the unique constraint allows it.
 *  - A booking's child must be in the slot's class (and, when the
 *    slot names a section, in that section) — a parent cannot book
 *    a slot meant for another class for their child.
 *  - Route params are {slot}/{booking} (never {id}), so no
 *    Qs-hash binding applies; raw ids are resolved directly.
 */
class PtmController extends Controller
{
    protected function canCreate(User $user): bool
    {
        return $user->user_type === 'admin';
    }

    protected function canView(User $user): bool
    {
        return in_array($user->user_type, ['admin', 'teacher', 'parent'], true);
    }

    public function index()
    {
        $user = Auth::user();
        if (!$user || !$this->canView($user)) {
            abort(403);
        }

        $slots = PtmSlot::with(['teacher', 'my_class', 'section', 'booking.parent', 'booking.student'])
            ->orderBy('date')->orderBy('start_time')->paginate(15);

        $d['slots'] = $slots;
        $d['can_create'] = $this->canCreate($user);
        // Staff see who booked each slot; parents see only "Booked"
        // (or "Booked by you") — never another family's names.
        // Kept for teachers deliberately (overnight menus, item 18):
        // only the scheduling choice moved to admin — the teacher's
        // view of their own slots and bookings, including the
        // cancel button the blade gates on staff_view, stays whole.
        $d['staff_view'] = in_array($user->user_type, ['admin', 'teacher'], true);
        $d['is_parent'] = $user->user_type === 'parent';
        $d['my_children'] = $user->user_type === 'parent'
            ? StudentRecord::with(['user', 'my_class'])
                ->where('my_parent_id', $user->id)->orderBy('user_id')->get()
            : collect();

        return view('pages.support_team.ptm.index', $d);
    }

    public function create()
    {
        $user = Auth::user();
        if (!$user || !$this->canCreate($user)) {
            abort(403);
        }

        if ($user->user_type === 'teacher') {
            $d['teachers'] = User::where('id', $user->id)->get(['id', 'name']);
            $d['classes'] = MyClass::whereIn('id', TeacherScope::classIds($user))
                ->orderBy('id')->get();
        } else {
            $d['teachers'] = User::where('user_type', 'teacher')->orderBy('name')->get(['id', 'name']);
            $d['classes'] = MyClass::orderBy('id')->get();
        }
        $d['sections'] = Section::with('my_class')->orderBy('my_class_id')->orderBy('name')->get();

        return view('pages.support_team.ptm.create', $d);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$this->canCreate($user)) {
            abort(403);
        }

        $data = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'teacher_id' => 'required|exists:users,id',
            'my_class_id' => 'required|exists:my_classes,id',
            'section_id' => 'nullable|exists:sections,id',
        ]);

        $teacher = User::findOrFail($data['teacher_id']);
        if ($teacher->user_type !== 'teacher') {
            return back()->withInput()
                ->with('flash_danger', 'The selected user is not a teacher.');
        }

        if ($user->user_type === 'teacher') {
            // A teacher creates slots only for classes they teach,
            // and only in their own name.
            if ((int) $data['teacher_id'] !== (int) $user->id
                || !TeacherScope::canTeachClass($user, $data['my_class_id'])) {
                abort(403);
            }
        }

        if (!empty($data['section_id'])) {
            $section = Section::findOrFail($data['section_id']);
            if ((int) $section->my_class_id !== (int) $data['my_class_id']) {
                return back()->withInput()
                    ->with('flash_danger', 'The selected section does not belong to the selected class.');
            }
        }

        $data['created_by'] = $user->id;
        PtmSlot::create($data);

        return redirect()->route('ptm.index')->with('flash_success', 'PTM slot created.');
    }

    public function book(Request $request, $slot)
    {
        $user = Auth::user();
        if (!$user || $user->user_type !== 'parent') {
            abort(403);
        }

        $slot = PtmSlot::with('booking')->findOrFail($slot);

        if ($slot->hasEnded()) {
            return back()->with('flash_danger', 'This slot has already ended and cannot be booked.');
        }

        $request->validate([
            'student_user_id' => 'required|integer',
        ]);

        // The child must be one of this parent's own children.
        $child = StudentRecord::where('user_id', $request->input('student_user_id'))
            ->where('my_parent_id', $user->id)->first();
        if (!$child) {
            return back()->with('flash_danger', 'You can only book a slot for your own child.');
        }

        if ((int) $child->my_class_id !== (int) $slot->my_class_id) {
            return back()->with('flash_danger', 'This slot is for a different class than your child.');
        }
        if ($slot->section_id && (int) $child->section_id !== (int) $slot->section_id) {
            return back()->with('flash_danger', 'This slot is for a different section than your child.');
        }

        if ($slot->booking) {
            return back()->with('flash_danger', 'This slot has already been booked.');
        }

        try {
            DB::transaction(function () use ($slot, $user, $child) {
                // Re-check inside the transaction; the UNIQUE
                // constraint below is the final guard against races.
                if (PtmBooking::where('ptm_slot_id', $slot->id)->exists()) {
                    throw new \RuntimeException('already booked');
                }
                PtmBooking::create([
                    'ptm_slot_id' => $slot->id,
                    'parent_id' => $user->id,
                    'student_user_id' => $child->user_id,
                ]);
            });
        } catch (QueryException $e) {
            return back()->with('flash_danger', 'This slot has already been booked.');
        } catch (\RuntimeException $e) {
            return back()->with('flash_danger', 'This slot has already been booked.');
        }

        Notify::toUser(
            $slot->teacher_id,
            'ptm_booked',
            'PTM slot booked: ' . $slot->date->format('d M Y') . ' ' . substr($slot->start_time, 0, 5),
            'Booked by ' . $user->name . ' for ' . (optional($child->user)->name ?: 'their child') . '.',
            route('ptm.index')
        );

        return redirect()->route('ptm.index')->with('flash_success', 'Slot booked.');
    }

    /**
     * Delete a slot (owner order 2026-10-30: old slots piled up
     * with no way to remove them). Admin only — the same role
     * that creates slots. A BOOKED slot is refused: its booking
     * must be cancelled first, so a family's meeting is never
     * silently deleted from under them.
     */
    public function destroy($slot)
    {
        $user = Auth::user();
        if (!$user || !$this->canCreate($user)) {
            abort(403);
        }

        $slot = PtmSlot::with('booking')->findOrFail($slot);
        if ($slot->booking) {
            return back()->with('flash_danger', 'This slot is booked — cancel the booking first, then delete the slot.');
        }

        $slot->delete();

        return redirect()->route('ptm.index')->with('flash_success', 'PTM slot deleted.');
    }

    public function cancel($booking)
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $booking = PtmBooking::with('slot')->findOrFail($booking);

        $isOwner = (int) $booking->parent_id === (int) $user->id;
        $isAdmin = $user->user_type === 'admin';
        $isSlotTeacher = $user->user_type === 'teacher'
            && $booking->slot && (int) $booking->slot->teacher_id === (int) $user->id;
        if (!$isOwner && !$isAdmin && !$isSlotTeacher) {
            abort(403);
        }

        // Deleting frees the slot for re-booking immediately.
        // Round 4: the booking's bell item must not outlive it —
        // cancel() used to delete the booking and leave the
        // teacher's "PTM slot booked" notification pointing at a
        // booking that no longer exists. Match the exact title
        // book() wrote (slot date + start time) for this teacher.
        $slot = $booking->slot;
        $booking->delete();
        if ($slot) {
            UserNotification::where('user_id', $slot->teacher_id)
                ->where('type', 'ptm_booked')
                ->where('title', 'PTM slot booked: ' . $slot->date->format('d M Y') . ' ' . substr($slot->start_time, 0, 5))
                ->delete();
        }

        return redirect()->route('ptm.index')->with('flash_success', 'Booking cancelled. The slot is free again.');
    }
}
