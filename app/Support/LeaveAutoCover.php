<?php

namespace App\Support;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Support\Carbon;

/**
 * Auto-arranged leave cover (owner order 2026-10-29).
 *
 * The owner settled the long-open auto-cover question: the SYSTEM
 * arranges substitutes for a teacher's leave — for emergency leave
 * immediately at submit (nobody approves an emergency), and for
 * normal leave the moment the Admin/Principal approves it (the
 * admin still approves the leave itself). Before this, approving a
 * leave created no substitutions at all and an admin had to arrange
 * every period by hand on the substitutions board.
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
     * @return array{periods:int, arranged:int, uncovered:int}
     */
    public static function arrange(LeaveRequest $leave, ?int $createdBy): array
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
            static::alertUncovered($absent, $leave, $result['uncovered'], $start, $end);
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
        // weekday, every cell's effective teacher (override first).
        $ownCellsByDay = [];
        $ownCellsFor = function (string $dayName) use (&$ownCellsByDay) {
            if (!isset($ownCellsByDay[$dayName])) {
                $map = [];
                $cells = TimeTable::with(['subject', 'time_slot', 'tt_record'])
                    ->where('day', $dayName)->whereNotNull('subject_id')->get();
                foreach ($cells as $other) {
                    $tid = SectionTeacher::teacherIdFor(
                        optional($other->tt_record)->section_id ?? null, $other->subject
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

        if ($result['arranged'] > 0) {
            static::alertPlanReady($absent, $leave, $result['arranged'], $start, $end);
        }
        if ($result['uncovered'] > 0) {
            static::alertUncovered($absent, $leave, $result['uncovered'], $start, $end);
        }

        return $result;
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
            $dateStr = $date->toDateString();
            $absentId = (int) $row->absent_teacher_id;

            // Teachers on approved leave touching THIS date are
            // out — the leaving teacher included (their own new
            // leave is already approved when this runs).
            $onLeaveIds = LeaveRequest::where('status', 'approved')
                ->whereDate('start_date', '<=', $dateStr)
                ->whereDate('end_date', '>=', $dateStr)
                ->pluck('user_id')->unique()->all();

            $pool = User::where('user_type', 'teacher')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', User::STATUS_ACTIVE);
                })
                ->whereNotIn('id', array_merge($onLeaveIds, [$absentId, (int) $leaving->id]))
                ->orderBy('id')->get();

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
            $pick = null;
            foreach ($candidates as $candidate) {
                $cid = (int) $candidate->id;
                // The candidate's own effective cells that weekday.
                $ownCells = [];
                $dayCells = TimeTable::with(['subject', 'time_slot', 'tt_record'])
                    ->where('day', $date->format('l'))->whereNotNull('subject_id')->get();
                foreach ($dayCells as $other) {
                    if ((int) SectionTeacher::teacherIdFor(optional($other->tt_record)->section_id ?? null, $other->subject) === $cid) {
                        $ownCells[] = $other;
                    }
                }
                $existingSubs = Substitution::with('time_table.time_slot')
                    ->where('substitute_teacher_id', $cid)
                    ->whereDate('date', $dateStr)->get()
                    ->map(function ($s) {
                        return $s->time_table;
                    })->all();
                if (!static::isFree($cid, $window, $date, $ownCells, $existingSubs, [])) {
                    continue;
                }
                $pick = $candidate;
                break;
            }

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
                        // The bell must land where a TEACHER can go:
                        // their attendance list (the board itself is
                        // admin/principal-only and bounced teachers).
                        route('attendance.periods.index')
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
