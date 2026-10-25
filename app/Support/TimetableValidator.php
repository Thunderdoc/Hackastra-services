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
    public static function report(TimeTableRecord $record): array
    {
        $errors = [];
        $warnings = [];

        $cells = TimeTable::with(['subject.teacher', 'time_slot'])
            ->where('ttr_id', $record->id)->get();
        $yearRecords = TimeTableRecord::where('year', $record->year)->pluck('id');

        /* Class collisions: two lessons in the same class slot. */
        foreach ($cells->groupBy(function ($c) { return $c->day_num . '|' . $c->ts_id; }) as $group) {
            if ($group->count() > 1) {
                $c = $group->first();
                $errors[] = 'Class collision on ' . $c->day . ' (' . optional($c->time_slot)->full . '): '
                    . $group->count() . ' lessons in one slot.';
            }
        }

        /* Broken cells. */
        foreach ($cells as $c) {
            if (!$c->subject) {
                $errors[] = 'A period on ' . $c->day . ' points at a subject that no longer exists.';
            } elseif (!SectionTeacher::teacherIdFor($record->section_id ?? null, $c->subject)) {
                $errors[] = $c->subject->name . ' on ' . $c->day . ' has no teacher assigned.';
            }
        }

        /* Teacher double-booking across every record of the year:
           same teacher, same day, same time of day. On this page
           only clashes involving THIS record are errors — the full
           unique set for the year is globalErrors(), which the
           readiness page uses (counting per-record reports would
           multiply-count every clash). */
        foreach (self::clashes($record->year) as $clash) {
            if ($clash['records'][0] === $record->id || $clash['records'][1] === $record->id) {
                $errors[] = $clash['message'];
            }
        }

        /* Requirement coverage for this record's class. */
        $coverage = [];
        foreach (Subject::where('my_class_id', $record->my_class_id)->orderBy('name')->get() as $s) {
            $placed = $cells->where('subject_id', $s->id)->count();
            $coverage[] = [
                'subject' => $s,
                'placed' => $placed,
                'target' => $s->periods_per_week,
            ];
            if ($s->periods_per_week === null) {
                $warnings[] = $s->name . ' (' . $record->my_class->name . ') has no weekly requirement set — its coverage cannot be checked.';
            } elseif ($placed < (int) $s->periods_per_week) {
                $warnings[] = $s->name . ' (' . $record->my_class->name . '): ' . $placed . ' of '
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
     */
    public static function clashes($year): array
    {
        $yearRecords = TimeTableRecord::where('year', $year)->pluck('id');
        $cells = TimeTable::with(['subject.teacher', 'tt_record'])
            ->whereIn('ttr_id', $yearRecords)->get();
        $seen = [];
        $out = [];
        foreach ($cells as $c) {
            // Section build: a clash is about the teacher who
            // actually teaches the cell (override or class teacher).
            $teacherId = SectionTeacher::teacherIdFor(optional($c->tt_record)->section_id ?? null, $c->subject);
            if (!$c->subject || !$teacherId) {
                continue;
            }
            $key = $teacherId . '|' . $c->day_num . '|' . date('H:i', (int) $c->timestamp_from);
            if (isset($seen[$key])) {
                $first = $seen[$key];
                if ((int) $first->ttr_id === (int) $c->ttr_id) {
                    continue; // same record+slot duplicates are class collisions, counted there
                }
                $out[] = [
                    'message' => 'Teacher double-booked: ' . (optional(User::find($teacherId))->name ?: 'A teacher') . ' on ' . $c->day
                        . ' at ' . date('g:i A', (int) $c->timestamp_from) . ' — '
                        . optional($first->tt_record)->name . ' and ' . optional($c->tt_record)->name . '.',
                    'records' => [(int) $first->ttr_id, (int) $c->ttr_id],
                ];
            } else {
                $seen[$key] = $c;
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
