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
