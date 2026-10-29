<?php

namespace App\Support;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One day's substitution facts, loaded in a handful of bulk
 * queries and then answered from memory.
 *
 * Why this exists (live incident, 2026-10-29 night): the leave
 * queue and the substitutions board each rendered in ~93,000
 * database queries — every plan row asked every candidate teacher
 * "are you free?", and each answer re-loaded EVERY timetable cell
 * of that weekday and re-resolved EVERY cell's teacher from the
 * database. On the local disk that is 11 slow seconds; against
 * the hosted database (a network round-trip per query) the pages
 * simply hung. The fix is structural: a day's cells, effective
 * teachers, substitutions, approved leaves and live teachers are
 * a few bulk queries, and every clash question is answered from
 * that snapshot.
 *
 * Deliberately NOT memoized across calls: this codebase has been
 * bitten by static caches going stale (a switched substitute was
 * validated against a teacher list built before that teacher
 * existed). A snapshot build is ~8 bulk queries, so callers build
 * one per operation and share it within that operation only —
 * always fresh, in production and in tests alike.
 */
class SubstitutionDay
{
    /**
     * @return array{cells: array, subs: array, on_leave: array, teachers: array}
     *   cells: list of ['id' => int, 'teacher_id' => ?int,
     *          'subject_name' => ?string, 'slot' => ?TimeSlot]
     *   subs:  list of ['substitute_id' => int, 'slot' => ?TimeSlot]
     *   on_leave: list of user ids on APPROVED leave touching the date
     *   teachers: live teacher User models, name order
     */
    public static function forDate(Carbon $date): array
    {
        $key = $date->toDateString();

        // Every cell of the weekday, with everything a clash check
        // needs eager-loaded in a handful of queries.
        $cellsRaw = TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->where('day', $date->format('l'))
            ->whereNotNull('subject_id')
            ->get();

        // Effective teachers in bulk: one read of the override
        // table, then SectionTeacher::teacherIdFor's exact rule
        // (an override row with a teacher wins, else the subject's
        // own teacher) applied in memory.
        $overrides = [];
        if (Schema::hasTable('section_subject_teachers')) {
            foreach (DB::table('section_subject_teachers')->get(['section_id', 'subject_id', 'teacher_id']) as $row) {
                $overrides[(int) $row->section_id . ':' . (int) $row->subject_id] = $row->teacher_id ? (int) $row->teacher_id : null;
            }
        }

        $cells = [];
        foreach ($cellsRaw as $cell) {
            $sectionId = optional($cell->tt_record)->section_id;
            $teacherId = null;
            if ($cell->subject) {
                $ov = $sectionId ? ($overrides[(int) $sectionId . ':' . (int) $cell->subject_id] ?? null) : null;
                $teacherId = $ov ?: ($cell->subject->teacher_id ? (int) $cell->subject->teacher_id : null);
            }
            $cells[] = [
                'id' => (int) $cell->id,
                'teacher_id' => $teacherId,
                'subject_name' => optional($cell->subject)->name,
                'slot' => $cell->time_slot,
            ];
        }

        $subs = [];
        foreach (Substitution::with('time_table.time_slot')->whereDate('date', $key)->get() as $sub) {
            $subs[] = [
                'substitute_id' => (int) $sub->substitute_teacher_id,
                'slot' => optional($sub->time_table)->time_slot,
            ];
        }

        $onLeave = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $key)
            ->whereDate('end_date', '>=', $key)
            ->pluck('user_id')->unique()
            ->map(function ($i) { return (int) $i; })->all();

        $teachers = User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', User::STATUS_ACTIVE);
            })
            ->orderBy('name')->get()->all();

        return [
            'cells' => $cells,
            'subs' => $subs,
            'on_leave' => $onLeave,
            'teachers' => $teachers,
        ];
    }
}
