<?php

namespace App\Support;

use App\Models\LeaveRequest;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-arranged leave cover (owner order 2026-10-29).
 *
 * The owner settled the long-open auto-cover question: the SYSTEM
 * arranges substitutes for a teacher's leave — for emergency leave
 * immediately at submit (nobody approves an emergency), and for
 * normal leave the plan is PREPARED at submit so the leave queue
 * already shows who covers each period ("yahi pe toh system
 * allocate karega" — owner, 2026-10-29 night). The prepared plan
 * is provisional: its rows are 'pending', they grant no access
 * and ring no bells, and a rejection deletes them again. Approval
 * reconciles the plan (stale picks dropped, gaps filled) and only
 * then are the Admins belled that a plan is waiting on the board.
 * Before all this, approving a leave created no substitutions at
 * all and an admin had to arrange every period by hand.
 *
 * Eligibility mirrors the manual board exactly (SubstitutionController):
 * the substitute must be a live teacher (user_type teacher, status
 * null/active), must not be the absent teacher, must not be on
 * approved leave touching the date, and must be free at the period's
 * slot — no own effective period and no other substitution
 * overlapping the slot window that date. Among the free teachers
 * the pick is deterministic: fewest substitutions inside the leave
 * range first, then fewest on that date, then lowest id — so the
 * load spreads and the result is reproducible.
 *
 * Periods nobody can cover stay uncovered: every admin and the
 * principal get an urgent bell (the principal's standing rule —
 * unresolved coverage is alerted, never silently dropped). The
 * substitute gets the same 'substitution_assigned' bell the manual
 * board sends, with the identical title format, so removing a row
 * later still cleans its bell up.
 *
 * created_by is the approver for approval-triggered runs and null
 * (system) for emergency runs; the board renders null as
 * "System (auto)".
 */
class LeaveAutoCover
{
    /**
     * Arrange cover for every period of the leave that still needs
     * it. Idempotent: periods already covered (manually or by an
     * earlier run) are skipped. Never throws for a single bad
     * period — that period simply counts as uncovered.
     *
     * $announce separates the two call shapes: at SUBMIT time the
     * plan is prepared quietly (false — no plan-ready bell, no
     * uncovered alert; the leave is not approved yet), while at
     * APPROVAL / emergency time the run reconciles stale picks
     * and announces the waiting plan (true).
     *
     * $planReadyBell exists for the legacy board flow, where a
     * submitted-by-hand plan still waited for review. The leave
     * page flow (owner order 2026-10-29 night — everything happens
     * on the leave page) confirms the plan inside the approval
     * itself via confirmPlanFor(), so its callers pass false: a
     * "review and submit the plan" bell would be a lie there.
     *
     * @return array{periods:int, arranged:int, uncovered:int}
     */
    public static function arrange(LeaveRequest $leave, ?int $createdBy, bool $announce = true, bool $planReadyBell = true): array
    {
        $result = ['periods' => 0, 'arranged' => 0, 'uncovered' => 0];

        $absent = $leave->user;
        if (!$absent || !LeaveCover::isLiveTeacher($absent)) {
            return $result;
        }

        try {
            $start = Carbon::parse($leave->start_date)->startOfDay();
            $end = Carbon::parse($leave->end_date)->startOfDay();
        } catch (\Throwable $e) {
            return $result;
        }
        if ($end->lt($start)) {
            return $result;
        }

        if ($announce) {
            static::dropStalePendingPicks($absent, $start, $end);
        }

        // Candidate pool: live teachers, not the absent teacher, not
        // on approved leave anywhere inside the range (this leave
        // itself excluded — it is the one being covered).
        $onLeaveIds = LeaveRequest::where('status', 'approved')
            ->where('id', '!=', $leave->id)
            ->where('user_id', '!=', $absent->id)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->pluck('user_id')->unique()->all();
        $pool = User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', User::STATUS_ACTIVE);
            })
            ->where('id', '!=', $absent->id)
            ->whereNotIn('id', $onLeaveIds)
            ->orderBy('id')->get();
        // Partial leave (owner order 2026-10-29): the window
        // restricts cover to the periods the teacher is actually
        // away for — the rest of the day they teach themselves.
        $window = $leave->periodWindow();
        if ($pool->isEmpty()) {
            $result['uncovered'] = LeaveCover::uncoveredCount($absent->id, $start, $end, $window) ?? 0;
            $result['periods'] = $result['uncovered'];
            if ($announce) {
                static::alertUncovered($absent, $leave, $result['uncovered'], $start, $end);
            }
            return $result;
        }
        $poolIds = $pool->map(function ($u) {
            return (int) $u->id;
        })->all();

        // The absent teacher's own cells, grouped by weekday name —
        // the same effective-teacher filter LeaveCover counts with.
        $query = TimeTable::with(['subject', 'time_slot', 'tt_record.my_class'])
            ->whereNotNull('subject_id');
        SectionTeacher::applyTeacherFilter($query, (int) $absent->id);
        $cellsByDay = [];
        foreach ($query->get() as $cell) {
            $cellsByDay[$cell->day][] = $cell;
        }
        if (!$cellsByDay) {
            return $result;
        }

        // Periods already covered for this teacher in the range.
        $covered = [];
        $existingForAbsent = Substitution::where('absent_teacher_id', $absent->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->whereNotNull('substitute_teacher_id')
            ->get(['time_table_id', 'date']);
        foreach ($existingForAbsent as $sub) {
            $d = $sub->date instanceof Carbon ? $sub->date->toDateString() : (string) $sub->date;
            $covered[$d . '|' . (int) $sub->time_table_id] = true;
        }

        // Existing load of the pool inside the range, for ranking:
        // totals per teacher + per (teacher, date).
        $loadTotal = array_fill_keys($poolIds, 0);
        $loadByDate = [];
        $subsInRange = Substitution::whereIn('substitute_teacher_id', $poolIds)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get(['substitute_teacher_id', 'date', 'time_table_id']);
        foreach ($subsInRange as $sub) {
            $tid = (int) $sub->substitute_teacher_id;
            $d = $sub->date instanceof Carbon ? $sub->date->toDateString() : (string) $sub->date;
            $loadTotal[$tid]++;
            $loadByDate[$d][$tid] = ($loadByDate[$d][$tid] ?? 0) + 1;
        }

        // Busy windows per (date, teacher): existing substitutions.
        $busySubs = [];
        $subCells = Substitution::with('time_table.time_slot')
            ->whereIn('substitute_teacher_id', $poolIds)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();
        foreach ($subCells as $sub) {
            $d = $sub->date instanceof Carbon ? $sub->date->toDateString() : Carbon::parse((string) $sub->date)->toDateString();
            $busySubs[$d][(int) $sub->substitute_teacher_id][] = $sub->time_table;
        }

        // Effective-teacher map per weekday, computed lazily: for a
        // weekday, every cell's effective teacher (override first),
        // resolved from ONE bulk override map — SectionTeacher::
        // teacherIdFor() costs a query per cell, and a weekday
        // carries ~200 cells; that per-cell storm was part of why
        // a leave approval could not finish against the remote
        // production database (owner report 2026-10-30).
        $overrideMap = static::overrideMap();
        $ownCellsByDay = [];
        $ownCellsFor = function (string $dayName) use (&$ownCellsByDay, $overrideMap) {
            if (!isset($ownCellsByDay[$dayName])) {
                $map = [];
                $cells = TimeTable::with(['subject', 'time_slot', 'tt_record'])
                    ->where('day', $dayName)->whereNotNull('subject_id')->get();
                foreach ($cells as $other) {
                    $tid = static::effectiveTeacherId(
                        $overrideMap,
                        optional($other->tt_record)->section_id ?? null,
                        $other->subject
                    );
                    if ($tid) {
                        $map[(int) $tid][] = $other;
                    }
                }
                $ownCellsByDay[$dayName] = $map;
            }
            return $ownCellsByDay[$dayName];
        };

        $assignedNow = []; // dateStr => teacherId => TimeTable[] (this run)
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dayName = $date->format('l');
            $cells = $cellsByDay[$dayName] ?? [];
            if (!$cells) {
                continue;
            }
            $dateStr = $date->toDateString();
            $ownMap = $ownCellsFor($dayName);

            // Day types (T2, master prompt): a non-teaching day
            // (holiday / closure / event-only / exam day) has no
            // regular periods, so no cover is arranged for it. A
            // half day narrows the window to the periods the
            // school runs that date, intersected with any
            // partial-leave window.
            if (!DayType::isTeachingDay($date)) {
                continue;
            }
            $effectiveWindow = $window;
            $dtWindow = DayType::windowFor($date);
            if ($dtWindow !== null) {
                $effectiveWindow = $window === null ? $dtWindow
                    : [max($window[0], $dtWindow[0]), min($window[1], $dtWindow[1])];
                if ($effectiveWindow[0] > $effectiveWindow[1]) {
                    continue;
                }
            }

            if ($effectiveWindow !== null) {
                $ttrIds = [];
                foreach ($cells as $c) {
                    $ttrIds[(int) $c->ttr_id] = true;
                }
                $indexMap = LeaveCover::periodIndexMap(array_keys($ttrIds), $dayName);
                $cells = array_values(array_filter($cells, function ($c) use ($indexMap, $effectiveWindow) {
                    $n = $indexMap[(int) $c->id] ?? null;
                    return $n !== null && $n >= $effectiveWindow[0] && $n <= $effectiveWindow[1];
                }));
                if (!$cells) {
                    continue;
                }
            }

            foreach ($cells as $cell) {
                $key = $dateStr . '|' . (int) $cell->id;
                if (isset($covered[$key])) {
                    continue;
                }
                $result['periods']++;
                // NOT $window: that name carries the leave's
                // period window across the whole date loop, and
                // rebinding it here (the pre-T2 code did) poisons
                // every later date's window filter with Carbon
                // slot times.
                $slotWindow = static::slotWindow($date, $cell->time_slot);

                // Rank the pool for this date: range load, day load, id.
                $candidates = $pool->all();
                usort($candidates, function ($a, $b) use ($loadTotal, $loadByDate, $dateStr) {
                    $ta = $loadTotal[(int) $a->id] ?? 0;
                    $tb = $loadTotal[(int) $b->id] ?? 0;
                    if ($ta !== $tb) {
                        return $ta <=> $tb;
                    }
                    $da = $loadByDate[$dateStr][(int) $a->id] ?? 0;
                    $db = $loadByDate[$dateStr][(int) $b->id] ?? 0;
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                    return (int) $a->id <=> (int) $b->id;
                });

                $pick = null;
                foreach ($candidates as $candidate) {
                    $cid = (int) $candidate->id;
                    if (!static::isFree($cid, $slotWindow, $date, $ownMap[$cid] ?? [], $busySubs[$dateStr][$cid] ?? [], $assignedNow[$dateStr][$cid] ?? [])) {
                        continue;
                    }
                    $pick = $candidate;
                    break;
                }

                if (!$pick) {
                    $result['uncovered']++;
                    continue;
                }

                try {
                    Substitution::create([
                        'time_table_id' => (int) $cell->id,
                        'date' => $dateStr,
                        'absent_teacher_id' => (int) $absent->id,
                        'substitute_teacher_id' => (int) $pick->id,
                        'created_by' => $createdBy,
                        'reason' => 'Auto-arranged (leave)',
                        // Owner order 2026-10-29: the arrangement
                        // is a PLAN. It grants no access and rings
                        // no substitute bell until the Admin reviews
                        // and submits it on the board.
                        'status' => 'pending',
                    ]);
                } catch (\Throwable $e) {
                    // Unique (date, time_table_id) race or a bad row:
                    // the period stays uncovered, the run continues.
                    $result['uncovered']++;
                    continue;
                }

                $covered[$key] = true;
                $assignedNow[$dateStr][(int) $pick->id][] = $cell;
                $loadTotal[(int) $pick->id]++;
                $loadByDate[$dateStr][(int) $pick->id] = ($loadByDate[$dateStr][(int) $pick->id] ?? 0) + 1;
                $result['arranged']++;

            }
        }

        if ($announce) {
            // Bell the Admins with the size of the plan now waiting
            // on the board — newly arranged in this run, or prepared
            // earlier at submit and made real by this approval (in
            // that case this run arranged nothing itself, but the
            // plan is exactly what the Admin must go and submit).
            $pendingTotal = (int) Substitution::where('absent_teacher_id', $absent->id)
                ->where('status', 'pending')
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->count();
            if ($pendingTotal > 0 && $planReadyBell) {
                static::alertPlanReady($absent, $leave, $pendingTotal, $start, $end);
            }
            if ($result['uncovered'] > 0) {
                static::alertUncovered($absent, $leave, $result['uncovered'], $start, $end);
            }
        }

        return $result;
    }

    /**
     * Approval-time reconcile: a plan prepared at submit can name
     * a substitute who has since come onto APPROVED leave through
     * a path that never ran the cover chain (a data-level approval,
     * or a chain that failed silently — failures are swallowed by
     * design at the call sites). Such a pick can never serve: drop
     * the still-pending row so the arrange run re-picks the period
     * honestly. Confirmed rows are the Admin's deliberate act and
     * are never touched here.
     */
    protected static function dropStalePendingPicks(User $absent, Carbon $start, Carbon $end): void
    {
        try {
            $rows = Substitution::where('absent_teacher_id', $absent->id)
                ->where('status', 'pending')
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->get();
            foreach ($rows as $row) {
                $d = $row->date instanceof Carbon ? $row->date->toDateString() : (string) $row->date;
                $onLeave = LeaveRequest::where('status', 'approved')
                    ->where('user_id', $row->substitute_teacher_id)
                    ->whereDate('start_date', '<=', $d)
                    ->whereDate('end_date', '>=', $d)
                    ->exists();
                if ($onLeave) {
                    $row->delete();
                }
            }
        } catch (\Throwable $e) {
            // Reconcile is best-effort; the arrange run still proceeds.
        }
    }

    /**
     * Make this leave's prepared plan LIVE (owner order
     * 2026-10-29 night: everything happens on the leave page —
     * the Substitutions board is a record, not a workplace).
     * Called the moment the leave becomes real: on approval in
     * decide(), and at submit for an emergency (already
     * auto-approved). Flips the leave's pending rows to
     * 'confirmed', bells every substitute (with the shared
     * 'Substitution assigned' title the board's destroy cleanup
     * matches on), bells each covered period's students, and
     * bells the students of periods nobody could cover that the
     * period is free. Returns how many rows went live.
     */
    public static function confirmPlanFor(LeaveRequest $leave, ?int $actorId): int
    {
        $absent = $leave->user;
        if (!$absent || $absent->user_type !== 'teacher') {
            return 0;
        }
        try {
            $start = Carbon::parse($leave->start_date)->startOfDay();
            $end = Carbon::parse($leave->end_date)->startOfDay();
        } catch (\Throwable $e) {
            return 0;
        }
        if ($end->lt($start)) {
            return 0;
        }

        $rows = Substitution::with([
            'time_table.subject', 'time_table.tt_record.my_class', 'time_table.tt_record.section',
            'substitute', 'absent_teacher',
        ])->pending()
            ->where('absent_teacher_id', $absent->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();

        // One statement for the whole plan (was one UPDATE per
        // row) and one bulk insert for every substitute bell
        // (was one INSERT + one schema check per row) — the
        // per-row write storm is what made approvals time out
        // against the remote database (owner report 2026-10-30).
        if ($rows->isNotEmpty()) {
            Substitution::whereIn('id', $rows->pluck('id')->all())
                ->update(['status' => 'confirmed']);
        }

        $substituteBells = [];
        foreach ($rows as $row) {
            $date = $row->date instanceof Carbon ? $row->date->copy() : Carbon::parse((string) $row->date);
            $cell = $row->time_table;
            if (!$cell || !$cell->subject) {
                continue;
            }
            $substituteBells[] = [
                'user_id' => (int) $row->substitute_teacher_id,
                'type' => 'substitution_assigned',
                // Same title shape as
                // SubstitutionController::bellTitle — the
                // board's destroy cleanup finds bells by it.
                'title' => 'Substitution assigned: ' . $cell->subject->name . ' on ' . $date->format('d M Y'),
                'body' => 'You cover ' . optional(optional($cell->tt_record)->my_class)->name . ' — ' . $cell->subject->name . ' on ' . $date->format('d M Y') . '.',
                'link' => route('substitutions.mine'),
            ];
        }
        try {
            Notify::insertMany($substituteBells);
        } catch (\Throwable $e) {
            // Bells only — the confirmation itself stands.
        }

        foreach ($rows as $row) {
            $date = $row->date instanceof Carbon ? $row->date->copy() : Carbon::parse((string) $row->date);
            $cell = $row->time_table;
            if (!$cell || !$cell->subject) {
                continue;
            }
            static::bellStudentsForCell($cell, 'period_substitute',
                'Substitute teacher: ' . $cell->subject->name . ' on ' . $date->format('d M Y'),
                optional($row->substitute)->name . ' will take your ' . $cell->subject->name
                . ' period on ' . $date->format('d M Y') . ' (' . $absent->name . ' is on leave).');
        }
        // Free-period bells for EVERY date of the leave — a date
        // where nothing could be arranged has no rows above, but
        // its students still must hear the period is free.
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            static::bellFreePeriods($absent, $d->copy(), $leave);
        }

        if ($rows->isNotEmpty()) {
            try {
                Audit::log('substitution.plan_submitted', null,
                    'Substitution plan for ' . $absent->name . ' (' . $start->format('d M Y') . ' - ' . $end->format('d M Y')
                    . ') went live with the leave ' . ($actorId ? 'approval' : 'auto-approval') . ' - ' . $rows->count() . ' period(s).');
            } catch (\Throwable $e) {
                // Audit only.
            }
        }

        return $rows->count();
    }

    /** Bell every student of a period's class/section. Bell-only:
     *  any failure is swallowed. Mirrors the board confirm's
     *  helper so students hear the same story on both paths. */
    protected static function bellStudentsForCell(TimeTable $cell, string $type, string $title, string $body): void
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
            // One bulk insert for the whole class/section —
            // per-student single inserts here were the bulk of
            // the leave-approval query storm (see Notify::toUsers).
            Notify::toUsers($q->pluck('user_id')->all(), $type, $title, $body, route('tt.mine'));
        } catch (\Throwable $e) {
            // Bell only.
        }
    }

    /** Students of periods still without a substitute hear the
     *  period is free. Day-type doctrine matches arrange(): a
     *  non-teaching day expects no registers at all, and a half
     *  day narrows the window. */
    protected static function bellFreePeriods(User $absent, Carbon $date, LeaveRequest $leave): void
    {
        try {
            if (!DayType::isTeachingDay($date)) {
                return;
            }
            $dayName = $date->format('l');
            $query = TimeTable::with(['subject', 'tt_record.my_class', 'tt_record.section'])
                ->whereNotNull('subject_id')->where('day', $dayName);
            SectionTeacher::applyTeacherFilter($query, (int) $absent->id);
            $cells = $query->get();
            if ($cells->isEmpty()) {
                return;
            }

            $window = $leave->periodWindow();
            $dtWindow = DayType::windowFor($date);
            if ($dtWindow !== null) {
                $window = $window === null ? $dtWindow
                    : [max($window[0], $dtWindow[0]), min($window[1], $dtWindow[1])];
                if ($window[0] > $window[1]) {
                    return;
                }
            }
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

            $coveredIds = Substitution::where('absent_teacher_id', $absent->id)
                ->whereDate('date', $date->toDateString())
                ->pluck('time_table_id')->map(function ($i) { return (int) $i; })->all();

            foreach ($cells as $cell) {
                if (in_array((int) $cell->id, $coveredIds, true) || !$cell->subject) {
                    continue;
                }
                static::bellStudentsForCell($cell, 'period_free',
                    'Free period: ' . $cell->subject->name . ' on ' . $date->format('d M Y'),
                    'No substitute is available for your ' . $cell->subject->name
                    . ' period on ' . $date->format('d M Y') . ' — it is a free period.');
            }
        } catch (\Throwable $e) {
            // Bell only.
        }
    }

    /** Bell to every Admin (owner order 2026-10-29): a system plan
     *  is waiting for review + submit. The Admin is the submit
     *  actor, so this bell is admin-only; gaps alert wider (admins
     *  + principal) via alertUncovered, as before. */
    protected static function alertPlanReady(User $absent, LeaveRequest $leave, int $arranged, Carbon $start, Carbon $end): void
    {
        try {
            $title = 'Substitution plan ready: ' . $absent->name;
            $body = 'The system has arranged cover for ' . $arranged . ' period(s) of '
                . $absent->name . ' between ' . $start->format('d M Y') . ' and ' . $end->format('d M Y')
                . '. Review the plan on the Substitutions board — switch any teacher you want — then submit it. '
                . 'Substitutes get access and their bells only after you submit.';
            foreach (User::where('user_type', 'admin')->pluck('id') as $uid) {
                Notify::toUser((int) $uid, 'substitution_plan_ready', $title, $body, route('substitutions.index'));
            }
        } catch (\Throwable $e) {
            // Alert only — never break the leave flow over a bell.
        }
    }

    /** Urgent bell to every admin + the principal when periods stay
     *  uncovered (the principal's standing alert rule). */
    protected static function alertUncovered(User $absent, LeaveRequest $leave, int $uncovered, Carbon $start, Carbon $end): void
    {
        if ($uncovered <= 0) {
            return;
        }
        try {
            $title = 'Uncovered periods: ' . $absent->name . ' on leave';
            $body = $uncovered . ' period(s) of ' . $absent->name . ' between '
                . $start->format('d M Y') . ' and ' . $end->format('d M Y')
                . ' still have no substitute. Arrange cover from the substitutions board.';
            foreach (User::whereIn('user_type', ['admin', 'principal'])->pluck('id') as $uid) {
                Notify::toUser((int) $uid, 'leave_coverage_unresolved', $title, $body, route('substitutions.index'));
            }
        } catch (\Throwable $e) {
            // Alert only — never break the leave flow over a bell.
        }
    }

    /**
     * The chain (owner order 2026-10-29): when a teacher who is
     * THEMSELVES covering periods for someone else goes on approved
     * leave, those covers cannot stay with an absent teacher. Each
     * affected substitution moves to the next eligible teacher —
     * same eligibility and deterministic pick as arrange() — and
     * the new substitute gets the standard assignment bell. A
     * period nobody free can take loses its row and alerts every
     * admin + the principal, exactly like an uncovered period.
     *
     * Call this AFTER arrange() for the same leave, once the leave
     * is approved (or auto-approved for an emergency).
     *
     * @return array{reassigned:int, lost:int}
     */
    public static function reassignForAbsentSubstitute(LeaveRequest $leave, ?int $createdBy): array
    {
        $result = ['reassigned' => 0, 'lost' => 0];

        $leaving = $leave->user;
        if (!$leaving || $leaving->user_type !== 'teacher') {
            return $result;
        }

        try {
            $start = Carbon::parse($leave->start_date)->startOfDay();
            $end = Carbon::parse($leave->end_date)->startOfDay();
        } catch (\Throwable $e) {
            return $result;
        }
        if ($end->lt($start)) {
            return $result;
        }

        $rows = Substitution::with(['time_table.time_slot', 'time_table.subject', 'time_table.tt_record.my_class', 'absent_teacher'])
            ->where('substitute_teacher_id', $leaving->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();
        if ($rows->isEmpty()) {
            return $result;
        }

        foreach ($rows as $row) {
            $cell = $row->time_table;
            if (!$cell) {
                continue;
            }
            $date = $row->date instanceof Carbon ? $row->date->copy() : Carbon::parse((string) $row->date);

            $pick = static::pickReplacementFor($row, $date, [(int) $leaving->id]);

            if ($pick) {
                $wasPending = $row->isPending();
                $row->update([
                    'substitute_teacher_id' => (int) $pick->id,
                    'created_by' => $createdBy,
                ]);
                $result['reassigned']++;
                if ($wasPending) {
                    // Owner order 2026-10-29: a pending plan moves
                    // silently — the new substitute is belled
                    // when the Admin submits the plan, not before.
                    continue;
                }
                try {
                    Notify::toUser(
                        (int) $pick->id,
                        'substitution_assigned',
                        'Substitution assigned: ' . $cell->subject->name . ' on ' . $date->format('d M Y'),
                        'You cover ' . optional(optional($cell->tt_record)->my_class)->name . ' — ' . $cell->subject->name . ' on ' . $date->format('d M Y') . ' (moved: the earlier substitute is on leave).',
                        // The bell lands on the teacher's own extra
                        // classes page (owner order 2026-10-30: one
                        // place listing every cover they hold).
                        route('substitutions.mine')
                    );
                } catch (\Throwable $e) {
                    // Bell only — the move itself stands.
                }
                continue;
            }

            // Nobody free: the period loses its cover. Remove the
            // row (its substitute is absent — keeping it would lie
            // about coverage) and alert the office.
            $absentName = optional($row->absent_teacher)->name ?: 'A teacher';
            $row->delete();
            $result['lost']++;
            try {
                $title = 'Cover lost: ' . $absentName . ' on ' . $date->format('d M Y');
                $body = 'The substitute for ' . $absentName . ' (' . $cell->subject->name . ', '
                    . optional(optional($cell->tt_record)->my_class)->name . ') on ' . $date->format('d M Y')
                    . ' is now on leave too, and no free teacher could be found. Arrange cover from the substitutions board.';
                foreach (User::whereIn('user_type', ['admin', 'principal'])->pluck('id') as $uid) {
                    Notify::toUser((int) $uid, 'leave_coverage_unresolved', $title, $body, route('substitutions.index'));
                }
            } catch (\Throwable $e) {
                // Alert only.
            }
        }

        return $result;
    }

    /**
     * The deterministic replacement pick for ONE substitution row
     * on ONE date — the same eligibility and ranking arrange() and
     * the chained-absence reassign use: live teachers only, never
     * the absent teacher, never anyone on approved leave touching
     * the date, never anyone in $excludeIds; free at the slot
     * (no own effective period, no other substitution overlapping);
     * ranked fewest covers that date first, then lowest id.
     */
    protected static function pickReplacementFor(Substitution $row, Carbon $date, array $excludeIds): ?User
    {
        $cell = $row->time_table;
        if (!$cell) {
            return null;
        }
        $dateStr = $date->toDateString();

        $onLeaveIds = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->pluck('user_id')->unique()->all();

        $pool = User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', User::STATUS_ACTIVE);
            })
            ->whereNotIn('id', array_merge($onLeaveIds, [(int) $row->absent_teacher_id], $excludeIds))
            ->orderBy('id')->get();
        if ($pool->isEmpty()) {
            return null;
        }

        // Rank: fewest covers that date first, then lowest id.
        $dayLoad = Substitution::whereDate('date', $dateStr)
            ->whereIn('substitute_teacher_id', $pool->pluck('id')->all())
            ->selectRaw('substitute_teacher_id, COUNT(*) as c')
            ->groupBy('substitute_teacher_id')
            ->pluck('c', 'substitute_teacher_id');
        $candidates = $pool->all();
        usort($candidates, function ($a, $b) use ($dayLoad) {
            $da = (int) ($dayLoad[(int) $a->id] ?? 0);
            $db = (int) ($dayLoad[(int) $b->id] ?? 0);
            if ($da !== $db) {
                return $da <=> $db;
            }
            return (int) $a->id <=> (int) $b->id;
        });

        $window = static::slotWindow($date, $cell->time_slot);
        $dayCells = TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->where('day', $date->format('l'))->whereNotNull('subject_id')->get();
        // Resolve every cell's effective teacher ONCE from the
        // bulk map and batch the candidates' existing covers into
        // one query: the previous shape re-scanned all ~200 day
        // cells PER CANDIDATE, each with its own teacherIdFor
        // query — up to ~7,800 queries to re-pick a single row.
        $overrideMap = static::overrideMap();
        $ownByTeacher = [];
        foreach ($dayCells as $other) {
            $tid = static::effectiveTeacherId(
                $overrideMap,
                optional($other->tt_record)->section_id ?? null,
                $other->subject
            );
            if ($tid) {
                $ownByTeacher[(int) $tid][] = $other;
            }
        }
        $subsByTeacher = [];
        foreach (Substitution::with('time_table.time_slot')
            ->whereIn('substitute_teacher_id', $pool->pluck('id')->all())
            ->whereDate('date', $dateStr)->get() as $s) {
            $subsByTeacher[(int) $s->substitute_teacher_id][] = $s->time_table;
        }
        foreach ($candidates as $candidate) {
            $cid = (int) $candidate->id;
            if (!static::isFree($cid, $window, $date, $ownByTeacher[$cid] ?? [], $subsByTeacher[$cid] ?? [], [])) {
                continue;
            }
            return $candidate;
        }

        return null;
    }

    /**
     * The whole section-override table as a local lookup map,
     * "section_id:subject_id" => teacher_id (truthy teachers
     * only). One query; callers resolve effective teachers from
     * it instead of SectionTeacher::teacherIdFor()'s query per
     * cell. Semantics identical: a truthy override wins, else
     * the subject's class-level teacher (see effectiveTeacherId).
     */
    protected static function overrideMap(): array
    {
        $map = [];
        if (Schema::hasTable('section_subject_teachers')) {
            foreach (DB::table('section_subject_teachers')->get(['section_id', 'subject_id', 'teacher_id']) as $o) {
                if ($o->teacher_id) {
                    $map[(int) $o->section_id . ':' . (int) $o->subject_id] = (int) $o->teacher_id;
                }
            }
        }
        return $map;
    }

    /** Effective teacher of a cell resolved from a bulk map. */
    protected static function effectiveTeacherId(array $overrideMap, $sectionId, $subject): ?int
    {
        if ($sectionId && $subject && isset($overrideMap[(int) $sectionId . ':' . (int) $subject->id])) {
            return $overrideMap[(int) $sectionId . ':' . (int) $subject->id];
        }
        return ($subject && $subject->teacher_id) ? (int) $subject->teacher_id : null;
    }

    /**
     * Owner order 2026-10-30: when a teacher's account is suspended,
     * terminated or deleted, the cover they were holding cannot
     * stay with them — the class would stand uncovered behind a
     * name that can no longer teach it. Every future row they
     * substitute (today included) moves to the next eligible
     * teacher with the same pick as every other reassign; a
     * confirmed row's new substitute gets the standard bell, a
     * pending plan row moves silently. A period nobody free can
     * take loses its row (keeping it would lie about coverage)
     * and alerts every admin + the principal, exactly like an
     * uncovered period. Past rows are history and stay untouched.
     *
     * @return array{reassigned:int, lost:int}
     */
    public static function reassignForDepartedTeacher(User $departing, ?int $actorId, string $reasonLabel = 'no longer available'): array
    {
        $result = ['reassigned' => 0, 'lost' => 0];
        if (!$departing || $departing->user_type !== 'teacher') {
            return $result;
        }

        $rows = Substitution::with(['time_table.time_slot', 'time_table.subject', 'time_table.tt_record.my_class', 'absent_teacher'])
            ->where('substitute_teacher_id', $departing->id)
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $cell = $row->time_table;
            if (!$cell || !$cell->subject) {
                continue;
            }
            $date = $row->date instanceof Carbon ? $row->date->copy() : Carbon::parse((string) $row->date);
            $pick = static::pickReplacementFor($row, $date, [(int) $departing->id]);

            if ($pick) {
                $wasPending = $row->isPending();
                $row->update([
                    'substitute_teacher_id' => (int) $pick->id,
                    'created_by' => $actorId ?: $row->created_by,
                ]);
                $result['reassigned']++;
                if ($wasPending) {
                    continue;
                }
                try {
                    Notify::toUser(
                        (int) $pick->id,
                        'substitution_assigned',
                        'Substitution assigned: ' . $cell->subject->name . ' on ' . $date->format('d M Y'),
                        'You cover ' . optional(optional($cell->tt_record)->my_class)->name . ' — ' . $cell->subject->name . ' on ' . $date->format('d M Y') . ' (moved: the earlier substitute is ' . $reasonLabel . ').',
                        route('substitutions.mine')
                    );
                } catch (\Throwable $e) {
                    // Bell only — the move itself stands.
                }
                continue;
            }

            $absentName = optional($row->absent_teacher)->name ?: 'A teacher';
            $row->delete();
            $result['lost']++;
            try {
                $title = 'Cover lost: ' . $absentName . ' on ' . $date->format('d M Y');
                $body = 'The substitute for ' . $absentName . ' (' . $cell->subject->name . ', '
                    . optional(optional($cell->tt_record)->my_class)->name . ') on ' . $date->format('d M Y')
                    . ' is ' . $reasonLabel . ', and no free teacher could be found. Arrange cover from the substitutions board.';
                foreach (User::whereIn('user_type', ['admin', 'principal'])->pluck('id') as $uid) {
                    Notify::toUser((int) $uid, 'leave_coverage_unresolved', $title, $body, route('substitutions.index'));
                }
            } catch (\Throwable $e) {
                // Alert only.
            }
        }

        if ($result['reassigned'] > 0 || $result['lost'] > 0) {
            try {
                Audit::log('substitution.cover_healed', null,
                    'Cover held by ' . $departing->name . ' (' . $reasonLabel . '): '
                    . $result['reassigned'] . ' period(s) moved to another teacher, '
                    . $result['lost'] . ' period(s) could not be re-covered.');
            } catch (\Throwable $e) {
                // Audit only.
            }
        }

        return $result;
    }

    /**
     * Free at the slot = no own effective period overlapping the
     * window, no existing substitution overlapping it, and nothing
     * assigned earlier in this run overlapping it. Mirrors the
     * manual board's clash rule; a slot without times cannot clash.
     */
    protected static function isFree(int $teacherId, ?array $window, Carbon $date, array $ownCells, array $existingSubCells, array $assignedCells): bool
    {
        if (!$window) {
            return true;
        }
        [$start, $end] = $window;
        foreach (array_merge($ownCells, $existingSubCells, $assignedCells) as $other) {
            if (!$other) {
                continue;
            }
            $otherWindow = static::slotWindow($date, $other->time_slot ?? null);
            if ($otherWindow && $start->lt($otherWindow[1]) && $otherWindow[0]->lt($end)) {
                return false;
            }
        }
        return true;
    }

    /** A slot's window on a date, parsed from its time strings.
     *  Same helper shape as the manual board's. */
    protected static function slotWindow(Carbon $date, $slot): ?array
    {
        if (!$slot || !$slot->time_from || !$slot->time_to) {
            return null;
        }
        try {
            $start = Carbon::parse($date->toDateString() . ' ' . $slot->time_from);
            $end = Carbon::parse($date->toDateString() . ' ' . $slot->time_to);
        } catch (\Throwable $e) {
            return null;
        }
        if ($end->lte($start)) {
            return null;
        }
        return [$start, $end];
    }
}
