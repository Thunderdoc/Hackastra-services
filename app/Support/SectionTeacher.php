<?php

namespace App\Support;

use App\Models\Section;
use App\Models\Subject;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Section-wise timetable teachers (owner build 2026-10-25).
 *
 * A subject has ONE class-wide teacher (subjects.teacher_id). A
 * section may override that teacher for one of its subjects via
 * section_subject_teachers — so section A and section B of the same
 * class can have different teachers for the same subject, at
 * different times. Every place that derives "the teacher of a
 * timetable cell" must resolve through here with the cell's record
 * section, never straight from the subject, or overrides silently
 * stop working on that surface.
 */
class SectionTeacher
{
    /**
     * Actual weekly teaching load per teacher id, counted from
     * PLACED timetable cells through the same precedence as
     * teacherIdFor (override first, else subject teacher) — one
     * preload of the override map, one pass over cells, never one
     * query per cell. This is the load the planning surfaces must
     * use: the old class-level periods_per_week sum ignored
     * overrides entirely, so an override-only teacher showed a
     * load of 0 while teaching ~30 periods, and the allocation
     * engine could recommend MORE work to an already-overloaded
     * override teacher.
     *
     * Placed cells ONLY, deliberately: the planned requirement
     * (periods_per_week) stays visible in Staffing's per-subject
     * demand section. Counting unplaced plan here would let
     * scratch/orphan subjects (a test class with requirements
     * but no timetable) inflate a real teacher's load.
     */
    public static function effectiveLoads(): array
    {
        $overrides = DB::table('section_subject_teachers')->get()
            ->groupBy('section_id')
            ->map(function ($rows) {
                return $rows->pluck('teacher_id', 'subject_id')
                    ->map(function ($t) { return (int) $t; })->all();
            })->all();
        $subjectTeachers = Subject::pluck('teacher_id', 'id');
        $loads = [];
        $cells = DB::table('time_tables')
            ->join('time_table_records', 'time_table_records.id', '=', 'time_tables.ttr_id')
            ->select('time_tables.subject_id', 'time_table_records.section_id')
            ->get();
        foreach ($cells as $cell) {
            $teacherId = null;
            if ($cell->section_id && isset($overrides[$cell->section_id][$cell->subject_id])) {
                $teacherId = $overrides[$cell->section_id][$cell->subject_id];
            } else {
                $st = $subjectTeachers[$cell->subject_id] ?? null;
                $teacherId = $st ? (int) $st : null;
            }
            if ($teacherId) {
                $loads[$teacherId] = ($loads[$teacherId] ?? 0) + 1;
            }
        }

        return $loads;
    }

    /**
     * Placed-cell loads per teacher PER WEEKDAY, through the same
     * teacherIdFor precedence as effectiveLoads: [teacher_id =>
     * [day name => count]], restricted to the given record ids.
     * One override preload + one pass over the cells. Used where
     * a DAY cap must be judged (manual cell writes — TTRequest):
     * effectiveLoads alone cannot say how full a teacher's Monday
     * already is.
     */
    public static function effectiveDayLoads(array $recordIds): array
    {
        if (empty($recordIds)) {
            return [];
        }
        $overrides = DB::table('section_subject_teachers')->get()
            ->groupBy('section_id')
            ->map(function ($rows) {
                return $rows->pluck('teacher_id', 'subject_id')
                    ->map(function ($t) { return (int) $t; })->all();
            })->all();
        $subjectTeachers = Subject::pluck('teacher_id', 'id');
        $loads = [];
        $cells = DB::table('time_tables')
            ->join('time_table_records', 'time_table_records.id', '=', 'time_tables.ttr_id')
            ->whereIn('time_tables.ttr_id', $recordIds)
            ->select('time_tables.subject_id', 'time_tables.day', 'time_table_records.section_id')
            ->get();
        foreach ($cells as $cell) {
            $teacherId = null;
            if ($cell->section_id && isset($overrides[$cell->section_id][$cell->subject_id])) {
                $teacherId = $overrides[$cell->section_id][$cell->subject_id];
            } else {
                $st = $subjectTeachers[$cell->subject_id] ?? null;
                $teacherId = $st ? (int) $st : null;
            }
            if ($teacherId && $cell->day) {
                $loads[$teacherId][$cell->day] = ($loads[$teacherId][$cell->day] ?? 0) + 1;
            }
        }

        return $loads;
    }

    /**
     * The teacher of a subject inside a section: the section's
     * override when one exists, else the subject's class teacher.
     * A null section (whole-class grid) always yields the subject's
     * teacher. Returns a user id or null.
     */
    public static function teacherIdFor($sectionId, $subject): ?int
    {
        if (!$subject) {
            return null;
        }
        if ($sectionId && Schema::hasTable('section_subject_teachers')) {
            $override = DB::table('section_subject_teachers')
                ->where('section_id', (int) $sectionId)
                ->where('subject_id', (int) $subject->id)
                ->value('teacher_id');
            if ($override) {
                return (int) $override;
            }
        }
        return $subject->teacher_id ? (int) $subject->teacher_id : null;
    }

    /** Same resolution, as a User model (or null). */
    public static function teacherFor($sectionId, $subject): ?User
    {
        $id = static::teacherIdFor($sectionId, $subject);
        return $id ? User::find($id) : null;
    }

    /**
     * The teacher's effective units, grouped for option lists
     * (scoped-options audit, owner 2026-11-01): section ids by
     * class where she is the EFFECTIVE teacher of at least one
     * subject (teacherIdFor semantics — the override map is loaded
     * once and resolved in memory, like effectiveLoads). Picker
     * forms narrow their section options to this set so an offered
     * section can never be one the store would refuse.
     */
    public static function unitSectionIdsByClass(User $user): array
    {
        $overrides = [];
        if (Schema::hasTable('section_subject_teachers')) {
            $overrides = DB::table('section_subject_teachers')->get()
                ->groupBy('section_id')
                ->map(function ($rows) {
                    return $rows->pluck('teacher_id', 'subject_id')
                        ->map(function ($t) { return (int) $t; })->all();
                })->all();
        }
        $subjectsByClass = Subject::all()->groupBy('my_class_id');

        $out = [];
        foreach (Section::all() as $sec) {
            foreach ($subjectsByClass[$sec->my_class_id] ?? [] as $sub) {
                $tid = $overrides[$sec->id][$sub->id] ?? ($sub->teacher_id ? (int) $sub->teacher_id : null);
                if ($tid === (int) $user->id) {
                    $out[(int) $sec->my_class_id][] = (int) $sec->id;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The subjects that are "hers" for option lists: she owns them
     * at class level OR holds at least one section override for
     * them — the same definition the LMS create forms already use.
     */
    public static function unitSubjectIds(User $user): array
    {
        $direct = Subject::where('teacher_id', $user->id)->pluck('id');
        $override = Schema::hasTable('section_subject_teachers')
            ? DB::table('section_subject_teachers')->where('teacher_id', $user->id)->pluck('subject_id')
            : collect();

        return $direct->merge($override)
            ->map(function ($id) { return (int) $id; })
            ->unique()->values()->all();
    }

    /**
     * Constrain a TimeTable query to cells taught by a teacher —
     * the cell's EFFECTIVE teacher (SectionTeacher::teacherIdFor):
     * the section override wins, the subject's class teacher only
     * when no override exists for that section+subject. The old
     * filter matched every cell of a subject the teacher owned at
     * class level, so a class-level English teacher was shown all
     * 22 senior-section English grids even where an override
     * teacher actually teaches them (Ananya: 100 "periods", 76
     * slot columns, 31 same-time collisions on her week grid).
     * Used by the teacher week grid, the dashboard's today periods
     * and period attendance, so all three agree on "my periods".
     */
    public static function applyTeacherFilter($query, int $userId): void
    {
        $query->where(function ($q) use ($userId) {
            // Override cells: this teacher is the section's override
            // teacher for the cell's subject.
            $q->orWhereExists(function ($sq) use ($userId) {
                $sq->select(DB::raw('1'))
                    ->from('section_subject_teachers')
                    ->join('time_table_records', 'time_table_records.id', '=', 'time_tables.ttr_id')
                    ->whereColumn('section_subject_teachers.section_id', 'time_table_records.section_id')
                    ->whereColumn('section_subject_teachers.subject_id', 'time_tables.subject_id')
                    ->where('section_subject_teachers.teacher_id', $userId);
            });
            // Subject-teacher cells, EXCEPT section-grid cells that
            // have an override (any teacher): those belong to the
            // override teacher, not the class-level subject teacher.
            // Class grids have a NULL record section, so the
            // whereNotExists below is trivially true for them.
            $q->orWhere(function ($q2) use ($userId) {
                $q2->whereHas('subject', function ($sq) use ($userId) {
                    $sq->where('teacher_id', $userId);
                })->whereNotExists(function ($sq) {
                    $sq->select(DB::raw('1'))
                        ->from('section_subject_teachers')
                        ->join('time_table_records', 'time_table_records.id', '=', 'time_tables.ttr_id')
                        ->whereColumn('section_subject_teachers.section_id', 'time_table_records.section_id')
                        ->whereColumn('section_subject_teachers.subject_id', 'time_tables.subject_id');
                });
            });
        });
    }

    /**
     * The record a student/parent sees for a class+section: the
     * section's own grid first (current session, then latest year),
     * else the whole-class grid (same fallback order). Returns null
     * when the class has no grid at all.
     */
    public static function recordForClassSection(int $classId, $sectionId, string $year = null)
    {
        $recordClass = \App\Models\TimeTableRecord::class;
        $base = function ($withSection) use ($recordClass, $classId, $sectionId) {
            $q = $recordClass::where('my_class_id', $classId)->whereNull('exam_id');
            if ($withSection && $sectionId) {
                $q->where('section_id', (int) $sectionId);
            } else {
                $q->whereNull('section_id');
            }
            return $q;
        };
        if ($year) {
            $current = $base(true)->where('year', $year)->first()
                ?: $base(false)->where('year', $year)->first();
            if ($current) {
                return $current;
            }
        }
        return $base(true)->orderByDesc('year')->first()
            ?: $base(false)->orderByDesc('year')->first();
    }
}
