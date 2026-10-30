<?php

namespace App\Support;

use App\Models\Substitution;
use Illuminate\Support\Carbon;

/**
 * Time-limited access for a covering teacher (owner order
 * 2026-10-29): while a Substitution row stands, the substitute
 * steps into the absent teacher's shoes FOR THAT PERIOD AND THAT
 * DATE ONLY — they see the period in their own attendance list,
 * take and submit its register, export its roster, and view the
 * class's LMS content for that subject (the absent teacher's
 * assignments and study materials) so the lesson can run.
 *
 * The access is exactly the row: another date, another period or
 * another teacher gets nothing, and when the row is removed (or
 * re-assigned by the leave chain) the access ends with it. The
 * substitute never gains the absent teacher's ownership — no
 * editing, grading or deleting of their content.
 *
 * Only CONFIRMED rows grant access (owner order 2026-10-29): a
 * system-arranged plan sits 'pending' — invisible here — until the
 * Admin reviews and submits it on the substitutions board.
 */
class SubstitutionAccess
{
    /** Does this teacher hold the cover for this exact period on
     *  this exact date? */
    public static function coversPeriod(int $userId, int $timeTableId, $date): bool
    {
        try {
            $dateStr = $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString();
        } catch (\Throwable $e) {
            return false;
        }

        return Substitution::confirmed()
            ->where('time_table_id', $timeTableId)
            ->where('substitute_teacher_id', $userId)
            ->whereDate('date', $dateStr)
            ->exists();
    }

    /** Does this teacher cover ANY period of this class + subject
     *  on this date? (The LMS view window: subject-wise, one day.) */
    public static function coversSubjectOn(int $userId, int $classId, int $subjectId, $date): bool
    {
        try {
            $dateStr = $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString();
        } catch (\Throwable $e) {
            return false;
        }

        return Substitution::confirmed()
            ->where('substitute_teacher_id', $userId)
            ->whereDate('date', $dateStr)
            ->whereHas('time_table', function ($q) use ($classId, $subjectId) {
                $q->where('subject_id', $subjectId)
                    ->whereHas('tt_record', function ($r) use ($classId) {
                        $r->where('my_class_id', $classId);
                    });
            })
            ->exists();
    }

    /** Class + subject pairs this teacher covers on a date, as
     *  [class_id => [subject_id, ...]] — the LMS list window: the
     *  covered class's assignments and study materials join the
     *  substitute's own lists for that day only, labelled as
     *  substitute work (owner order 2026-10-30). */
    public static function coveredClassSubjectsOn(int $userId, $date): array
    {
        try {
            $dateStr = $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString();
        } catch (\Throwable $e) {
            return [];
        }

        $rows = Substitution::confirmed()
            ->where('substitute_teacher_id', $userId)
            ->whereDate('date', $dateStr)
            ->with(['time_table.tt_record', 'time_table.subject'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $tt = $row->time_table;
            if (!$tt || !$tt->tt_record || !$tt->subject_id) {
                continue;
            }
            $classId = (int) $tt->tt_record->my_class_id;
            $map[$classId] = $map[$classId] ?? [];
            if (!in_array((int) $tt->subject_id, $map[$classId], true)) {
                $map[$classId][] = (int) $tt->subject_id;
            }
        }

        return $map;
    }

    /** Is this (class, subject) one of the teacher's OWN teaching
     *  pairs — as the subject's teacher or through a section
     *  override? A pair the teacher owns is never labelled
     *  substitute work, even if they also cover a period of it. */
    public static function ownPair(int $userId, int $classId, int $subjectId): bool
    {
        $subject = \App\Models\Subject::find($subjectId);
        if ($subject && (int) $subject->my_class_id === $classId && (int) $subject->teacher_id === $userId) {
            return true;
        }

        return \Illuminate\Support\Facades\DB::table('section_subject_teachers')
            ->join('sections', 'sections.id', '=', 'section_subject_teachers.section_id')
            ->where('sections.my_class_id', $classId)
            ->where('section_subject_teachers.subject_id', $subjectId)
            ->where('section_subject_teachers.teacher_id', $userId)
            ->exists();
    }

    /** Timetable ids this teacher covers on a date (their extra
     *  periods for the attendance list). */
    public static function coveredPeriodIdsOn(int $userId, $date): array
    {
        try {
            $dateStr = $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString();
        } catch (\Throwable $e) {
            return [];
        }

        return Substitution::confirmed()
            ->where('substitute_teacher_id', $userId)
            ->whereDate('date', $dateStr)
            ->pluck('time_table_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }
}
