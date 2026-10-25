<?php

namespace App\Support;

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
     * Constrain a TimeTable query to cells taught by a teacher —
     * either as the subject's class teacher OR as a section
     * override for the cell's record section. Used by the teacher
     * week grid, the dashboard's today periods and period
     * attendance, so all three agree on what "my periods" means.
     */
    public static function applyTeacherFilter($query, int $userId): void
    {
        $query->where(function ($q) use ($userId) {
            $q->whereHas('subject', function ($sq) use ($userId) {
                $sq->where('teacher_id', $userId);
            })->orWhereExists(function ($sq) use ($userId) {
                $sq->select(DB::raw('1'))
                    ->from('section_subject_teachers')
                    ->join('time_table_records', 'time_table_records.id', '=', 'time_tables.ttr_id')
                    ->whereColumn('section_subject_teachers.section_id', 'time_table_records.section_id')
                    ->whereColumn('section_subject_teachers.subject_id', 'time_tables.subject_id')
                    ->where('section_subject_teachers.teacher_id', $userId);
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
