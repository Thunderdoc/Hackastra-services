<?php

namespace App\Support;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Support\Carbon;

/**
 * Uncovered periods (Round 3 Phase 1, owner order 2026-11-07):
 * the periods of a date that still have nobody — the absent
 * teacher is on APPROVED leave, and no substitution row gives the
 * period a substitute, and the office has not cancelled the class.
 *
 * Uncovered periods are NOT stored anywhere: auto-cover leaves
 * gaps with no row at all, so the gap list has to be derived —
 * from the day's approved leaves and the absent teachers' real
 * timetable cells, minus every (date, time_table_id) that already
 * carries a substitute (any status) or a cancelled row. Legacy
 * rows that DO exist with a NULL substitute (the rows the old
 * principal dashboard counted) are gaps too, even when their
 * absent teacher has no approved leave that date — the row itself
 * is the office's record that the period needs a decision.
 *
 * A cancelled cell is RESOLVED: it appears here never, exactly as
 * LeaveCover counts it neither covered nor uncovered.
 *
 * Bulk shape: one read of the date's substitution rows, one read
 * of its approved leaves, then ONE timetable query per teacher on
 * leave (never one per cell) — this feeds both the action page
 * and a dashboard count, so it must stay cheap on a real day.
 */
class UncoveredPeriods
{
    /**
     * @param string|Carbon $date
     * @return array<int, array{date:string, time_table_id:int, cell:TimeTable, absent_teacher:?User, existing_row_id:?int}>
     */
    public static function forDate($date): array
    {
        $carbon = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse((string) $date)->startOfDay();
        $dateStr = $carbon->toDateString();
        $dayName = $carbon->format('l');

        // The date's whole substitution sheet in one read: which
        // cells already carry a substitute, which are cancelled,
        // and the row behind each cell (for orphan detection).
        $coveredTt = [];
        $cancelledTt = [];
        $rowByTt = [];
        foreach (Substitution::whereDate('date', $dateStr)->get() as $row) {
            $ttId = (int) $row->time_table_id;
            $rowByTt[$ttId] = $row;
            if ($row->substitute_teacher_id !== null) {
                $coveredTt[$ttId] = true;
            }
            if ($row->status === 'cancelled') {
                $cancelledTt[$ttId] = true;
            }
        }

        $items = []; // time_table_id => item

        if (DayType::isTeachingDay($carbon)) {
            // Approved leaves touching the date, grouped per
            // teacher — a teacher with two overlapping leaves is
            // away for the UNION of their period windows (a null
            // window anywhere means the whole day).
            $leaves = LeaveRequest::with('user')
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $dateStr)
                ->whereDate('end_date', '>=', $dateStr)
                ->get();
            $byTeacher = [];
            foreach ($leaves as $leave) {
                if (!$leave->user || !LeaveCover::isLiveTeacher($leave->user)) {
                    continue;
                }
                $byTeacher[(int) $leave->user_id]['user'] = $leave->user;
                $byTeacher[(int) $leave->user_id]['windows'][] = $leave->periodWindow();
            }

            foreach ($byTeacher as $teacherId => $entry) {
                $teacher = $entry['user'];
                $windows = $entry['windows'];
                $fullDay = in_array(null, $windows, true);

                $query = TimeTable::with(['subject', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
                    ->where('day', $dayName)
                    ->whereNotNull('subject_id');
                SectionTeacher::applyTeacherFilter($query, (int) $teacherId);
                $cells = $query->get();
                if ($cells->isEmpty()) {
                    continue;
                }

                if (!$fullDay) {
                    $ttrIds = $cells->pluck('ttr_id')->map(function ($i) {
                        return (int) $i;
                    })->unique()->all();
                    $indexMap = LeaveCover::periodIndexMap($ttrIds, $dayName);
                    $cells = $cells->filter(function ($cell) use ($indexMap, $windows) {
                        $n = $indexMap[(int) $cell->id] ?? null;
                        if ($n === null) {
                            return false;
                        }
                        foreach ($windows as $w) {
                            if ($n >= $w[0] && $n <= $w[1]) {
                                return true;
                            }
                        }
                        return false;
                    });
                }

                foreach ($cells as $cell) {
                    $ttId = (int) $cell->id;
                    if (isset($coveredTt[$ttId]) || isset($cancelledTt[$ttId]) || isset($items[$ttId])) {
                        continue;
                    }
                    $items[$ttId] = [
                        'date' => $dateStr,
                        'time_table_id' => $ttId,
                        'cell' => $cell,
                        'absent_teacher' => $teacher,
                        'existing_row_id' => isset($rowByTt[$ttId]) ? (int) $rowByTt[$ttId]->id : null,
                    ];
                }
            }
        }

        // Orphan rows: a row exists, names nobody, and is not
        // cancelled — a gap on record even with no approved leave
        // behind it. Cells already listed above keep their leave's
        // teacher; the rest resolve their cell + teacher in bulk.
        $orphanTtIds = [];
        foreach ($rowByTt as $ttId => $row) {
            if ($row->substitute_teacher_id === null && $row->status !== 'cancelled' && !isset($items[$ttId])) {
                $orphanTtIds[] = $ttId;
            }
        }
        if ($orphanTtIds) {
            $cells = TimeTable::with(['subject', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
                ->whereIn('id', $orphanTtIds)->get()->keyBy('id');
            $teachers = User::whereIn('id', collect($orphanTtIds)->map(function ($ttId) use ($rowByTt) {
                return (int) $rowByTt[$ttId]->absent_teacher_id;
            })->unique()->all())->get()->keyBy('id');
            foreach ($orphanTtIds as $ttId) {
                $cell = $cells->get($ttId);
                if (!$cell) {
                    continue;
                }
                $row = $rowByTt[$ttId];
                $items[$ttId] = [
                    'date' => $dateStr,
                    'time_table_id' => $ttId,
                    'cell' => $cell,
                    'absent_teacher' => $teachers->get((int) $row->absent_teacher_id),
                    'existing_row_id' => (int) $row->id,
                ];
            }
        }

        $items = array_values($items);
        usort($items, function ($a, $b) {
            $ta = static::slotStart($a['cell']);
            $tb = static::slotStart($b['cell']);
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return $a['time_table_id'] <=> $b['time_table_id'];
        });

        return $items;
    }

    /**
     * @param string|Carbon $from
     * @param string|Carbon $to
     * @return array<int, array{date:string, time_table_id:int, cell:TimeTable, absent_teacher:?User, existing_row_id:?int}>
     */
    public static function forDateRange($from, $to): array
    {
        $start = $from instanceof Carbon ? $from->copy()->startOfDay() : Carbon::parse((string) $from)->startOfDay();
        $end = $to instanceof Carbon ? $to->copy()->startOfDay() : Carbon::parse((string) $to)->startOfDay();
        if ($end->lt($start)) {
            return [];
        }
        $out = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            // forDate is already slot-sorted within its date, and
            // the walk is chronological, so the merged list stays
            // sorted by date, then slot start, then id.
            foreach (static::forDate($date) as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** Slot start as a comparable number (the board's own rule:
     *  the slot's timestamp_from, else its parsed time_from). */
    protected static function slotStart(TimeTable $cell): int
    {
        $slot = $cell->time_slot;
        if (!$slot) {
            return PHP_INT_MAX;
        }
        if ($slot->timestamp_from) {
            return (int) $slot->timestamp_from;
        }
        if ($slot->time_from) {
            $ts = strtotime((string) $slot->time_from);
            return $ts === false ? PHP_INT_MAX : $ts;
        }

        return PHP_INT_MAX;
    }
}
