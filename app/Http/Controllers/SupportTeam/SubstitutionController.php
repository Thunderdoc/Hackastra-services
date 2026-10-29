<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Models\UserNotification;
use App\Support\Audit;
use App\Support\LeaveCover;
use App\Support\Notify;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Wave 1 feature 4 (Substitution): cover for an absent teacher's
 * period on one date.
 *
 * These routes carry NO team middleware, so every action guards
 * itself here. Overnight menus 2026-10-29, item 19 (image-31:
 * the Substitutions decision screen): substitutions are NOT
 * decided by teachers — the Admin arranges them (manually here;
 * any auto-assignment is a separate build) and the system notifies
 * the substitute. Admin manages all substitutions; the Principal
 * reads the board (monitoring only, via the leave queue's cover
 * badge); teachers get no access at all — no menu link, and every
 * action refuses them server-side. Anyone else (including Super
 * Admin, who has no oversight role on this surface) gets a 403.
 *
 * Clash rule (store): the substitute must not already teach
 * another period at an overlapping time that day — checked against
 * (a) the substitute's own timetable cells on that date's weekday
 * and (b) existing substitutions for that substitute on that date.
 * Slot overlap reuses the timetable's own time concept (the cell's
 * time slot window); adjacent slots that merely touch do not clash.
 * Substitute-taking-attendance is deliberately NOT part of this
 * feature: the period-attendance take guard is untouched, so the
 * substitute still cannot submit the register.
 */
class SubstitutionController extends Controller
{
    /** List: admin sees all, principal reads (monitoring only). Teachers get no access. */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $query = Substitution::with([
            'time_table.subject', 'time_table.time_slot',
            'time_table.tt_record.my_class', 'time_table.tt_record.section',
            'absent_teacher', 'substitute', 'creator',
        ])->orderByDesc('date')->orderByDesc('id');

        if (Qs::userIsAdmin() || Qs::userIsPrincipal()) {
            // Round-2 roles fix: the principal's leave queue links its
            // "N without cover" badge here, so the board must open for
            // the principal (read-only monitoring). Arranging and
            // deleting cover stay admin-only.
            $d['substitutions'] = $query->get();
        } else {
            abort(403, 'Substitutions are managed by administrators.');
        }

        $d['can_delete'] = function (Substitution $sub) use ($user) {
            return Qs::userIsAdmin();
        };

        /* Full-auto flow (owner order 2026-10-29): system-arranged
           plans wait here as 'pending' groups — one group per
           (absent teacher, date). The Admin reviews them, switches
           any teacher (the select lists only teachers FREE at that
           slot, computed live), and submits; only then do the rows
           go live. The Principal sees the same groups read-only. */
        $pendingRows = Substitution::with([
            'time_table.subject', 'time_table.time_slot',
            'time_table.tt_record.my_class', 'time_table.tt_record.section',
            'absent_teacher', 'substitute',
        ])->pending()->orderBy('date')->orderBy('id')->get();
        $d['pending_groups'] = $pendingRows->groupBy(function (Substitution $row) {
            $d = $row->date instanceof Carbon ? $row->date->toDateString() : (string) $row->date;
            return (int) $row->absent_teacher_id . '|' . $d;
        });
        $d['free_options'] = [];
        if (Qs::userIsAdmin()) {
            foreach ($pendingRows as $row) {
                $d['free_options'][$row->id] = $this->freeTeachersFor($row);
            }
        }
        $d['is_admin'] = Qs::userIsAdmin();

        return view('pages.support_team.substitutions.index', $d);
    }

    /** Form: admin picks any period. Teachers get no access. */
    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user || !Qs::userIsAdmin()) {
            abort(403, 'Substitutions are managed by administrators.');
        }

        $d['periods'] = $this->periodOptions($user);
        $d['teachers'] = User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', User::STATUS_ACTIVE);
            })
            ->orderBy('name')->get();
        $d['selected_date'] = $request->query('date');
        $d['selected_period'] = $request->query('time_table_id');

        return view('pages.support_team.substitutions.create', $d);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !Qs::userIsAdmin()) {
            abort(403, 'Substitutions are managed by administrators.');
        }

        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time_table_id' => 'required|integer|exists:time_tables,id',
            'substitute_teacher_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $date = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();
        $tt = TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->findOrFail((int) $data['time_table_id']);

        if (!$tt->subject) {
            throw ValidationException::withMessages([
                'time_table_id' => 'This timetable period has no subject assigned.',
            ]);
        }

        // The period's effective teacher (section override else
        // subject teacher) is the absent teacher — derived here,
        // never trusted from the request.
        $absentId = SectionTeacher::teacherIdFor(
            optional($tt->tt_record)->section_id ?? null, $tt->subject
        );

        // Overnight menus 2026-10-29, item 19: only the Admin
        // arranges cover — the absent-teacher self-create path is
        // retired with the teacher menu link (the guard at the top
        // already refuses non-admins).

        if (!$absentId) {
            throw ValidationException::withMessages([
                'time_table_id' => 'This period has no teacher assigned, so there is nobody to substitute for.',
            ]);
        }

        $substitute = User::findOrFail((int) $data['substitute_teacher_id']);

        if ((int) $substitute->id === (int) $absentId) {
            throw ValidationException::withMessages([
                'substitute_teacher_id' => 'The substitute cannot be the absent teacher.',
            ]);
        }

        if ($substitute->user_type !== 'teacher' || !$substitute->isActiveAccount()) {
            throw ValidationException::withMessages([
                'substitute_teacher_id' => 'The substitute must be an active teacher.',
            ]);
        }

        // Same weekday rule as period attendance: a period only runs
        // on its own weekday.
        if ($tt->day && $date->format('l') !== $tt->day) {
            throw ValidationException::withMessages([
                'date' => 'This period runs on ' . $tt->day . ' — ' . $date->format('d M Y') . ' is a ' . $date->format('l') . '.',
            ]);
        }

        if (Substitution::whereDate('date', $date->toDateString())
            ->where('time_table_id', $tt->id)->exists()) {
            throw ValidationException::withMessages([
                'time_table_id' => 'This period already has a substitute for ' . $date->format('d M Y') . '.',
            ]);
        }

        if ($clash = $this->clashMessage($substitute->id, $date, $tt)) {
            throw ValidationException::withMessages([
                'substitute_teacher_id' => $clash,
            ]);
        }

        try {
            $sub = Substitution::create([
                'time_table_id' => $tt->id,
                'date' => $date->toDateString(),
                'absent_teacher_id' => $absentId,
                'substitute_teacher_id' => $substitute->id,
                'created_by' => $user->id,
                'reason' => $data['reason'] ?? null,
                // A manual arrangement IS the Admin's own submitted
                // decision — it goes live immediately, unlike a
                // system plan (owner order 2026-10-29).
                'status' => 'confirmed',
            ]);
        } catch (QueryException $e) {
            // The (date, time_table_id) unique key fired on a race.
            throw ValidationException::withMessages([
                'time_table_id' => 'This period already has a substitute for ' . $date->format('d M Y') . '.',
            ]);
        }

        // Round 6 (roles audit): a substitution is a "you must be
        // somewhere" assignment exactly like an invigilation duty —
        // and duties have rung the teacher's bell since the
        // 2026-10-25 batch fix. Without this, the substitute only
        // learns by opening the index. The absent teacher gets no
        // bell: they are the one being covered, not an actor.
        Notify::toUser(
            (int) $substitute->id,
            'substitution_assigned',
            static::bellTitle($tt->subject->name, $date->toDateString()),
            'You cover ' . optional(optional($tt->tt_record)->my_class)->name . ' — ' . $tt->subject->name . ' on ' . $date->format('d M Y') . '.',
            // The bell must land where a TEACHER can go: their
            // attendance list. The board itself is admin/principal
            // only and bounced teachers to a 403 (owner order
            // 2026-10-29: notification click = right page, no error).
            route('attendance.periods.index')
        );

        return redirect()->route('substitutions.index')
            ->with('flash_success', 'Substitution arranged: ' . $substitute->name . ' covers ' . $tt->subject->name . ' on ' . $date->format('d M Y') . '.');
    }

    /** Delete: admin only. */
    public function destroy(Request $request, Substitution $substitution)
    {
        $user = $request->user();
        if (!$user || !Qs::userIsAdmin()) {
            abort(403, 'Substitutions are managed by administrators.');
        }

        // Round 6 (roles audit, PTM-cancel precedent): removing the
        // substitution must also remove the substitute's bell item,
        // or it dangles naming a cover that no longer exists. The
        // title is rebuilt from the row itself, so the match is
        // exact; other bells for the same teacher are untouched.
        $substitution->loadMissing('time_table.subject');
        $oldTitle = static::bellTitle(
            optional($substitution->time_table->subject)->name ?: '',
            (string) $substitution->date
        );
        // Only a CONFIRMED row ever rang that bell (owner order
        // 2026-10-29): cleaning up for a pending row could kill the
        // bell of a confirmed sibling with the same title (a double
        // period shares subject + date).
        if ($substitution->isConfirmed()) {
            UserNotification::where('user_id', (int) $substitution->substitute_teacher_id)
                ->where('type', 'substitution_assigned')
                ->where('title', $oldTitle)
                ->delete();
        }

        $substitution->delete();

        return redirect()->route('substitutions.index')
            ->with('flash_success', 'Substitution removed.');
    }

    /**
     * Switch the substitute on a PENDING plan row (owner order
     * 2026-10-29). Only teachers the system itself shows as free
     * for that slot may be picked — the same list the review
     * section renders — so a switch can never create a clash, a
     * cover by the absent teacher, or a cover by someone on leave.
     */
    public function switchSubstitute(Request $request, Substitution $substitution)
    {
        $user = $request->user();
        if (!$user || !Qs::userIsAdmin()) {
            abort(403, 'Substitutions are managed by administrators.');
        }
        if (!$substitution->isPending()) {
            return back()->with('flash_danger', 'This substitution is already submitted and live. To change it, remove it and arrange a new one.');
        }

        $data = $request->validate([
            'substitute_teacher_id' => 'required|integer|exists:users,id',
        ]);
        $target = (int) $data['substitute_teacher_id'];
        if ($target === (int) $substitution->substitute_teacher_id) {
            return back()->with('flash_success', 'No change — that teacher is already assigned in the plan.');
        }

        $freeIds = $this->freeTeachersFor($substitution)->pluck('id')
            ->map(function ($id) { return (int) $id; })->all();
        if (!in_array($target, $freeIds, true)) {
            throw ValidationException::withMessages([
                'substitute_teacher_id' => 'That teacher is not free at this time (own period, another cover, or on leave). Pick one of the free teachers shown.',
            ]);
        }

        $substitution->update(['substitute_teacher_id' => $target]);

        return back()->with('flash_success', 'Substitution switched — the plan is still unsubmitted. Submit it when the whole plan looks right.');
    }

    /**
     * Submit one pending plan group (owner order 2026-10-29): every
     * pending row for (absent teacher, date) goes live at once.
     * Only NOW do substitutes gain access and get their bells, and
     * the affected students hear about their covered periods — and
     * about any period that stayed uncovered, as a free period.
     */
    public function confirm(Request $request)
    {
        $user = $request->user();
        if (!$user || !Qs::userIsAdmin()) {
            abort(403, 'Substitutions are managed by administrators.');
        }

        $data = $request->validate([
            'absent_teacher_id' => 'required|integer|exists:users,id',
            'date' => 'required|date_format:Y-m-d',
        ]);
        $date = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();

        $rows = Substitution::with([
            'time_table.subject', 'time_table.tt_record.my_class', 'time_table.tt_record.section',
            'substitute', 'absent_teacher',
        ])->pending()
            ->where('absent_teacher_id', (int) $data['absent_teacher_id'])
            ->whereDate('date', $date->toDateString())
            ->get();

        if ($rows->isEmpty()) {
            return back()->with('flash_danger', 'That substitution plan has already been submitted.');
        }

        foreach ($rows as $row) {
            $row->update(['status' => 'confirmed']);
        }

        foreach ($rows as $row) {
            $cell = $row->time_table;
            if (!$cell || !$cell->subject) {
                continue;
            }
            try {
                Notify::toUser(
                    (int) $row->substitute_teacher_id,
                    'substitution_assigned',
                    static::bellTitle($cell->subject->name, $date->toDateString()),
                    'You cover ' . optional(optional($cell->tt_record)->my_class)->name . ' — ' . $cell->subject->name . ' on ' . $date->format('d M Y') . '.',
                    route('attendance.periods.index')
                );
            } catch (\Throwable $e) {
                // Bell only — the submission itself stands.
            }
            $this->bellStudentsForCell($cell, 'period_substitute',
                'Substitute teacher: ' . $cell->subject->name . ' on ' . $date->format('d M Y'),
                optional($row->substitute)->name . ' will take your ' . $cell->subject->name
                . ' period on ' . $date->format('d M Y') . ' (' . optional($row->absent_teacher)->name . ' is on leave).');
        }

        $this->bellFreePeriods((int) $data['absent_teacher_id'], $date);

        Audit::log('substitution.plan_submitted', null,
            'Substitution plan for ' . optional($rows->first()->absent_teacher)->name . ' on '
            . $date->format('d M Y') . ' submitted by ' . $user->name . ' (' . $rows->count() . ' period(s)).');

        return back()->with('flash_success', 'Substitution plan submitted: ' . $rows->count()
            . ' period(s) confirmed for ' . $date->format('d M Y')
            . '. Substitutes and students have been notified.');
    }

    /**
     * Teachers the system can offer for this row's slot: live
     * teachers, not the absent teacher, not the current pick, not
     * on approved leave that date, and clash-free at the slot.
     * This is the list the review section renders (owner order
     * 2026-10-29: the system shows the free slots itself).
     */
    protected function freeTeachersFor(Substitution $row)
    {
        $row->loadMissing(['time_table.subject', 'time_table.time_slot']);
        $cell = $row->time_table;
        if (!$cell) {
            return collect();
        }
        $date = $row->date instanceof Carbon ? $row->date->copy() : Carbon::parse((string) $row->date);
        $onLeaveIds = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->pluck('user_id')->unique()->all();

        return User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', User::STATUS_ACTIVE);
            })
            ->where('id', '!=', (int) $row->absent_teacher_id)
            ->where('id', '!=', (int) $row->substitute_teacher_id)
            ->whereNotIn('id', $onLeaveIds)
            ->orderBy('name')->get()
            ->filter(function (User $t) use ($date, $cell) {
                return $this->clashMessage((int) $t->id, $date, $cell) === null;
            })->values();
    }

    /** Bell every student of a period's class/section. Bell-only:
     *  any failure is swallowed so a submission never breaks on it. */
    protected function bellStudentsForCell(TimeTable $cell, string $type, string $title, string $body): void
    {
        try {
            $record = $cell->tt_record;
            if (!$record) {
                return;
            }
            $q = StudentRecord::where('grad', 0);
            if ($record->section_id) {
                $q->where('section_id', $record->section_id);
            } else {
                $q->where('my_class_id', $record->my_class_id);
            }
            foreach ($q->pluck('user_id') as $uid) {
                Notify::toUser((int) $uid, $type, $title, $body, route('tt.mine'));
            }
        } catch (\Throwable $e) {
            // Bell only.
        }
    }

    /**
     * Free-period bells (owner order 2026-10-29): after a plan for
     * (absent teacher, date) is submitted, any of that teacher's
     * periods that day still without a substitute are a free period
     * for the class — the students hear it from the system, not by
     * discovering an empty classroom. A partial leave's window
     * limits this to the periods the teacher is actually away for.
     */
    protected function bellFreePeriods(int $absentId, Carbon $date): void
    {
        try {
            $dayName = $date->format('l');
            $query = TimeTable::with(['subject', 'tt_record.my_class', 'tt_record.section'])
                ->whereNotNull('subject_id')->where('day', $dayName);
            SectionTeacher::applyTeacherFilter($query, $absentId);
            $cells = $query->get();
            if ($cells->isEmpty()) {
                return;
            }

            $leave = LeaveRequest::where('user_id', $absentId)->where('status', 'approved')
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString())
                ->latest('id')->first();
            $window = $leave ? $leave->periodWindow() : null;
            if ($window !== null) {
                $indexMap = LeaveCover::periodIndexMap(
                    $cells->pluck('ttr_id')->unique()->map(function ($i) { return (int) $i; })->all(),
                    $dayName
                );
                $cells = $cells->filter(function ($c) use ($indexMap, $window) {
                    $n = $indexMap[(int) $c->id] ?? null;
                    return $n !== null && $n >= $window[0] && $n <= $window[1];
                });
            }

            $coveredIds = Substitution::where('absent_teacher_id', $absentId)
                ->whereDate('date', $date->toDateString())
                ->pluck('time_table_id')->map(function ($i) { return (int) $i; })->all();

            foreach ($cells as $cell) {
                if (in_array((int) $cell->id, $coveredIds, true) || !$cell->subject) {
                    continue;
                }
                $this->bellStudentsForCell($cell, 'period_free',
                    'Free period: ' . $cell->subject->name . ' on ' . $date->format('d M Y'),
                    'No substitute is available for your ' . $cell->subject->name
                    . ' period on ' . $date->format('d M Y') . ' — it is a free period.');
            }
        } catch (\Throwable $e) {
            // Bell only.
        }
    }

    /**
     * Single definition of the substitution bell title, shared by
     * store (notify) and destroy (cleanup) so the two can never
     * drift apart. $date is a Y-m-d string.
     */
    protected static function bellTitle(string $subjectName, string $date): string
    {
        return 'Substitution assigned: ' . $subjectName . ' on ' . Carbon::parse($date)->format('d M Y');
    }

    /**
     * Period options for the create form. Admin-only since the
     * 2026-10-29 overnight menus order, so the form lists every
     * period with a subject; the teacher filter is retired with
     * teacher access.
     */
    protected function periodOptions(User $user)
    {
        $query = TimeTable::with(['subject', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
            ->whereNotNull('subject_id');

        return $query->get()->sortBy(function ($tt) {
            return ($tt->day ?? '') . ' ' . optional($tt->time_slot)->time_from;
        })->values();
    }

    /**
     * Clash message when the substitute is already busy at an
     * overlapping time on this date, or null when free. Checks (a)
     * the substitute's own timetable cells on the date's weekday
     * and (b) existing substitutions for that substitute on the
     * date — both compared by time-slot window overlap.
     */
    protected function clashMessage(int $substituteId, Carbon $date, TimeTable $cell): ?string
    {
        $window = $this->slotWindow($date, optional($cell->time_slot));
        if (!$window) {
            return null;
        }
        [$start, $end] = $window;

        $ownCells = TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->where('day', $date->format('l'))
            ->where('id', '!=', $cell->id)
            ->whereNotNull('subject_id')
            ->get()
            ->filter(function ($other) use ($substituteId) {
                return SectionTeacher::teacherIdFor(
                    optional($other->tt_record)->section_id ?? null, $other->subject
                ) === $substituteId;
            });

        foreach ($ownCells as $other) {
            $otherWindow = $this->slotWindow($date, optional($other->time_slot));
            if ($otherWindow && $this->overlaps($start, $end, $otherWindow[0], $otherWindow[1])) {
                return 'This teacher already teaches ' . $other->subject->name . ' at ' . (optional($other->time_slot)->full ?: 'this time') . ' on ' . $date->format('l') . '.';
            }
        }

        $existing = Substitution::with('time_table.time_slot')
            ->whereDate('date', $date->toDateString())
            ->where('substitute_teacher_id', $substituteId)
            ->get();

        foreach ($existing as $sub) {
            $otherWindow = $this->slotWindow($date, optional(optional($sub->time_table)->time_slot));
            if ($otherWindow && $this->overlaps($start, $end, $otherWindow[0], $otherWindow[1])) {
                return 'This teacher is already covering another period at this time on ' . $date->format('d M Y') . '.';
            }
        }

        return null;
    }

    /** A slot's window on a date, parsed from its time strings. */
    protected function slotWindow(Carbon $date, $slot): ?array
    {
        if (!$slot || !$slot->time_from || !$slot->time_to) {
            return null;
        }
        try {
            $start = Carbon::parse($date->toDateString() . ' ' . $slot->time_from);
            $end = Carbon::parse($date->toDateString() . ' ' . $slot->time_to);
        } catch (\Exception $e) {
            return null;
        }
        if ($end->lte($start)) {
            return null;
        }
        return [$start, $end];
    }

    /** Strict overlap: slots that merely touch do not clash. */
    protected function overlaps(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): bool
    {
        return $aStart->lt($bEnd) && $bStart->lt($aEnd);
    }
}
