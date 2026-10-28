<?php

namespace App\Support;

use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;

/**
 * Timetable validation (Scheduling Engine Slice E, validation
 * half): one server-side check shared by the validation page —
 * and written so generation, manual edits and (later) publication
 * can all call the same rules. A timetable is only as good as its
 * worst clash, so the checks run across ALL records of the same
 * year, not just the record being viewed: a teacher double-booked
 * between two classes is invisible from inside either one.
 *
 * Errors block trust in the grid (clashes, collisions, broken
 * cells). Warnings are planning gaps (requirement shortfalls,
 * teachers over the weekly cap, unset requirements).
 */
class TimetableValidator
{
    /**
     * Agent D round 2: the weekday number of a cell, falling back
     * to its day NAME when day_num is NULL (manually added cells
     * stored NULL before the fillable fix). Grouping or clashing
     * on raw day_num put every NULL cell on one phantom day: false
     * clashes across days and missed clashes on the same day.
     */
    protected static function dayNumOf($cell): ?int
    {
        if ($cell->day_num) {
            return (int) $cell->day_num;
        }
        $idx = array_search($cell->day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], true);
        return $idx !== false ? $idx + 1 : null;
    }

    /**
     * Agent D round 2: a cell timestamp as an epoch int. App-written
     * cells store ints, but legacy/probe cells may carry datetime
     * strings — a bare (int) cast turns '2098-09-03 08:00:00' into
     * 2098, collapsing every time to one garbage point and hiding
     * real overlaps. Numeric values pass through; strings parse.
     */
    protected static function tsInt($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $t = strtotime($value);
        return $t === false ? 0 : $t;
    }

    /**
     * Agent D round 2: a cell whose end is at or before its start
     * (zero-length or negative timestamps — manual/legacy data;
     * the generator never creates one, slot minutes validate >=
     * 20). Such a cell is invalid data, not an overlap: it must be
     * reported once, as broken, and skipped by the overlap and
     * clash sweeps so it is not triple-reported.
     */
    protected static function isBroken($cell): bool
    {
        return self::tsInt($cell->timestamp_to) <= self::tsInt($cell->timestamp_from);
    }

    /**
     * Agent D round 3: public alias so the Manage page flags exactly
     * the cells the validator reports — one definition, no drift
     * between the Validate page and the Manage grid.
     */
    public static function isBrokenCell($cell): bool
    {
        return self::isBroken($cell);
    }

    /**
     * Wave 7 — the ERROR half of report(), shared: the index's green
     * Validated pill must use exactly this verdict, or the pill
     * could disagree with the Validate page. $cells are this
     * record's cells (with subject + time_slot); $clashes is the
     * year's clash set from clashes().
     */
    protected static function recordErrors($record, $cells, $clashes): array
    {
        $errors = [];

        /* Class collisions: two lessons in the same class slot. */
        foreach ($cells->groupBy(function ($c) { return self::dayNumOf($c) . '|' . $c->ts_id; }) as $group) {
            if ($group->count() > 1) {
                $c = $group->first();
                $errors[] = 'Class collision on ' . $c->day . ' (' . optional($c->time_slot)->full . '): '
                    . $group->count() . ' lessons in one slot.';
            }
        }

        /* Same-record overlaps (agent D round 2): two lessons in
           one record whose TIMES overlap but whose slots differ
           (e.g. a manual 9:20 cell over a 9:00 cell). The slot
           group above only catches identical slots, so these were
           invisible. One error per day is enough to flag the record. */
        foreach ($cells->groupBy(function ($c) { return self::dayNumOf($c); }) as $dayCells) {
            $sorted = $dayCells->sortBy(function ($c) { return self::tsInt($c->timestamp_from); })->values();
            $prevEnd = null;
            foreach ($sorted as $c) {
                // Broken cells are reported as broken below, never
                // as overlaps, and must not poison the running end.
                if (self::isBroken($c)) {
                    continue;
                }
                $from = self::tsInt($c->timestamp_from);
                $to = self::tsInt($c->timestamp_to);
                if ($prevEnd !== null && $from < $prevEnd) {
                    $errors[] = 'Class overlap on ' . $c->day . ' (' . optional($c->time_slot)->full . '): 2 lessons overlap in time.';
                    break;
                }
                if ($prevEnd === null || $to > $prevEnd) {
                    $prevEnd = $to;
                }
            }
        }

        /* Broken cells. */
        /* Agent D round 9: the generator never places an
           out-of-stream or cross-class subject, but the manual
           Manage path offered every class subject (no stream
           filter) and accepted any existing subject_id — and the
           validator stayed silent, because its coverage loop SKIPS
           out-of-stream subjects. Such a cell is wrong data, not a
           gap: it now errors, so the green Validated pill can no
           longer certify a grid containing it. Placement itself
           stays possible (deliberate admin act); it just cannot
           pass validation silently. Whole-class grids (no section)
           skip the stream check, as before. */
        $sectionStream = $record->section_id ? optional($record->section)->stream : null;
        foreach ($cells as $c) {
            if (self::isBroken($c)) {
                $errors[] = 'Broken cell on ' . $c->day . ' (' . date('g:i A', self::tsInt($c->timestamp_from))
                    . ' - ' . date('g:i A', self::tsInt($c->timestamp_to))
                    . '): the period ends at or before it starts — fix or delete it on the Manage page.';
            }
            if (!$c->subject) {
                $errors[] = 'A period on ' . $c->day . ' points at a subject that no longer exists.';
            } elseif (!SectionTeacher::teacherIdFor($record->section_id ?? null, $c->subject)) {
                $errors[] = $c->subject->name . ' on ' . $c->day . ' has no teacher assigned.';
            }
            if ($c->subject) {
                if ((int) $c->subject->my_class_id !== (int) $record->my_class_id) {
                    $errors[] = $c->subject->name . ' on ' . $c->day . ' belongs to another class — it cannot sit in this grid.';
                } elseif ($sectionStream && !Streams::appliesTo($c->subject, $sectionStream)) {
                    $errors[] = $c->subject->name . ' on ' . $c->day . ' is not offered to this section\'s stream ('
                        . Streams::label($sectionStream) . ').';
                }
            }
        }

        /* Teacher double-booking across every record of the year:
           only clashes involving THIS record are its errors — the
           full unique set for the year is globalErrors(), which the
           readiness page uses (counting per-record reports would
           multiply-count every clash). */
        foreach ($clashes as $clash) {
            if (in_array($record->id, $clash['records'], true)) {
                $errors[] = $clash['message'];
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Wave 7 — ids of the year's records with ZERO errors, for the
     * index's green Validated pill. One shared pass (year cells +
     * year clashes loaded once), never per-record report() calls.
     * Green is computed live on every list render — nothing is
     * stored, so an edited timetable can never show a stale green.
     */
    public static function cleanRecordIds($year): array
    {
        $records = TimeTableRecord::where('year', $year)->get();
        if ($records->isEmpty()) {
            return [];
        }
        $cells = TimeTable::with(['subject.teacher', 'time_slot'])
            ->whereIn('ttr_id', $records->pluck('id'))->get()->groupBy('ttr_id');
        $clashes = self::clashes($year);

        $clean = [];
        foreach ($records as $record) {
            if (empty(self::recordErrors($record, $cells->get($record->id, collect()), $clashes))) {
                $clean[] = $record->id;
            }
        }
        return $clean;
    }

    public static function report(TimeTableRecord $record): array
    {
        $warnings = [];

        $cells = TimeTable::with(['subject.teacher', 'time_slot'])
            ->where('ttr_id', $record->id)->get();
        $yearRecords = TimeTableRecord::where('year', $record->year)->pluck('id');

        $errors = self::recordErrors($record, $cells, self::clashes($record->year));

        /* Requirement coverage for this record's class. Agent D
           round 2: a streamed section grid covers only its own
           stream (common + own stream) — warning about other
           streams' subjects at 0 placed was a false alarm. */
        $sectionStream = $record->section_id ? optional($record->section)->stream : null;
        $coverage = [];
        // Agent D round 34 (F-R34-1): a record whose class was
        // deleted (bare MyClass destroy) has a null my_class —
        // reading ->name threw, so report() 500'd the validate
        // page while recordErrors still certified it green (P10).
        $className = optional($record->my_class)->name ?: 'Deleted class';
        foreach (Subject::where('my_class_id', $record->my_class_id)->orderBy('name')->get() as $s) {
            if ($sectionStream && !Streams::appliesTo($s, $sectionStream)) {
                continue;
            }
            $placed = $cells->where('subject_id', $s->id)->count();
            $coverage[] = [
                'subject' => $s,
                'placed' => $placed,
                'target' => $s->periods_per_week,
            ];
            if ($s->periods_per_week === null) {
                $warnings[] = $s->name . ' (' . $className . ') has no weekly requirement set — its coverage cannot be checked.';
            } elseif ($placed < (int) $s->periods_per_week) {
                $warnings[] = $s->name . ' (' . $className . '): ' . $placed . ' of '
                    . (int) $s->periods_per_week . ' required periods are placed.';
            }
        }

        /* Teacher weekly loads across the year's records vs cap. */
        $allCells = TimeTable::with(['subject.teacher', 'tt_record'])
            ->whereIn('ttr_id', $yearRecords)->get();
        $cap = Staffing::maxPeriodsPerWeek();
        $loads = [];
        foreach ($allCells->groupBy(function ($c) { return SectionTeacher::teacherIdFor(optional($c->tt_record)->section_id ?? null, $c->subject); }) as $teacherId => $group) {
            if (!$teacherId) {
                continue;
            }
            $teacher = User::find($teacherId);
            $loads[] = [
                'teacher' => $teacher,
                'periods' => $group->count(),
                'cap' => $cap,
                'over' => $group->count() > $cap,
            ];
            if ($group->count() > $cap) {
                $warnings[] = ($teacher ? $teacher->name : 'A teacher') . ' carries ' . $group->count()
                    . ' periods in the week, over the cap of ' . $cap . '.';
            }
        }

        return [
            'record' => $record,
            'cells' => $cells->count(),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'coverage' => $coverage,
            'loads' => $loads,
        ];
    }

    /**
     * Every teacher double-booking in a year's records, once each:
     * [['message' => ..., 'records' => [recordIdA, recordIdB]]].
     *
     * Agent D round 2: clashes are INTERVAL overlaps now, not
     * equal start times. Two levels with different period lengths
     * (e.g. 40 vs 30 minutes) put one teacher at 9:00-9:40 and
     * 9:30-10:00 — overlapping, but the old start-time key missed
     * them entirely. Per teacher+day, cells sort by start and a
     * running max-end sweep catches every overlap. Same-record
     * overlaps are class overlaps (recordErrors), not teacher
     * clashes. Record pairs are canonicalised by id so one pair
     * never reports twice in opposite orders.
     */
    public static function clashes($year): array
    {
        $yearRecords = TimeTableRecord::where('year', $year)->pluck('id');
        $cells = TimeTable::with(['subject.teacher', 'tt_record'])
            ->whereIn('ttr_id', $yearRecords)->get();
        $byTeacherDay = [];
        foreach ($cells as $c) {
            // Section build: a clash is about the teacher who
            // actually teaches the cell (override or class teacher).
            $teacherId = SectionTeacher::teacherIdFor(optional($c->tt_record)->section_id ?? null, $c->subject);
            if (!$c->subject || !$teacherId) {
                continue;
            }
            if (self::isBroken($c)) {
                continue; // broken cells are reported as broken, not as clashes
            }
            $dayNum = self::dayNumOf($c);
            if ($dayNum === null) {
                continue; // undatable legacy cells cannot clash-check
            }
            $byTeacherDay[$teacherId . '|' . $dayNum]['teacher'] = $teacherId;
            $byTeacherDay[$teacherId . '|' . $dayNum]['cells'][] = $c;
        }
        $out = [];
        foreach ($byTeacherDay as $group) {
            $sorted = collect($group['cells'])->sortBy(function ($c) { return self::tsInt($c->timestamp_from); })->values();
            $prevEnd = null;
            $prev = null;
            foreach ($sorted as $c) {
                $from = self::tsInt($c->timestamp_from);
                $to = self::tsInt($c->timestamp_to);
                if ($prev !== null && $from < $prevEnd) {
                    if ((int) $prev->ttr_id !== (int) $c->ttr_id) {
                        $first = (int) $prev->ttr_id < (int) $c->ttr_id ? $prev : $c;
                        $second = $first === $prev ? $c : $prev;
                        $out[] = [
                            'message' => 'Teacher double-booked: ' . (optional(User::find($group['teacher']))->name ?: 'A teacher') . ' on ' . $c->day
                                . ' at ' . date('g:i A', $from) . ' — '
                                . optional($first->tt_record)->name . ' and ' . optional($second->tt_record)->name . '.',
                            'records' => [(int) $first->ttr_id, (int) $second->ttr_id],
                        ];
                    }
                }
                if ($prevEnd === null || $to > $prevEnd) {
                    $prevEnd = $to;
                    $prev = $c;
                }
            }
        }

        return $out;
    }

    /** The year's unique blocking-clash messages (readiness page). */
    public static function globalErrors($year): array
    {
        return array_values(array_unique(array_column(self::clashes($year), 'message')));
    }
}
