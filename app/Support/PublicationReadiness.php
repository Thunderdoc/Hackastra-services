<?php

namespace App\Support;

use App\Models\Exam;
use App\Models\Mark;
use App\Models\MarkSubmission;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\User;

/**
 * Result publication readiness (owner order 2026-10-29).
 *
 * A result can be published only when every teacher has SUBMITTED
 * the marks for their (section, subject) unit — the units are the
 * distinct section+subject pairs that actually have mark rows for
 * the exam. This helper is the single source the Admin's
 * publication page, the notify-teachers action and the
 * publication-request gate all read, so the three can never
 * disagree about what is still pending.
 */
class PublicationReadiness
{
    /** All mark units of the exam (one per section+subject with rows). */
    public static function units(Exam $exam)
    {
        return Mark::where('exam_id', $exam->id)
            ->where('year', $exam->year)
            ->select('my_class_id', 'section_id', 'subject_id')
            ->distinct()
            ->orderBy('my_class_id')
            ->orderBy('section_id')
            ->orderBy('subject_id')
            ->get();
    }

    /**
     * The units whose teacher has NOT submitted yet, enriched with
     * display names and the unit's effective teacher.
     */
    public static function pendingUnits(Exam $exam): array
    {
        $submitted = MarkSubmission::where('exam_id', $exam->id)->get()
            ->keyBy(function ($s) { return $s->section_id . ':' . $s->subject_id; });

        $pending = [];
        foreach (static::units($exam) as $unit) {
            if ($submitted->has($unit->section_id . ':' . $unit->subject_id)) {
                continue;
            }
            $subject = Subject::find($unit->subject_id);
            $teacherId = $subject ? SectionTeacher::teacherIdFor($unit->section_id, $subject) : null;
            $pending[] = [
                'my_class_id' => (int) $unit->my_class_id,
                'section_id' => (int) $unit->section_id,
                'subject_id' => (int) $unit->subject_id,
                'class_name' => optional(MyClass::find($unit->my_class_id))->name ?? ('Class ' . $unit->my_class_id),
                'section_name' => optional(Section::find($unit->section_id))->name ?? '',
                'subject_name' => $subject ? $subject->name : ('Subject ' . $unit->subject_id),
                'teacher_id' => $teacherId ? (int) $teacherId : null,
                'teacher_name' => $teacherId ? (optional(User::find($teacherId))->name ?? 'Unassigned') : 'Unassigned',
            ];
        }
        return $pending;
    }

    public static function pendingCount(Exam $exam): int
    {
        return count(static::pendingUnits($exam));
    }

    public static function totalUnits(Exam $exam): int
    {
        return static::units($exam)->count();
    }
}
