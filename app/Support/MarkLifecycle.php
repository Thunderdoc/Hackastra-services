<?php

namespace App\Support;

use App\Models\ExamDate;
use App\Models\MarkSubmission;
use Illuminate\Support\Carbon;

/**
 * Marks entry lifecycle (owner order 2026-10-29).
 *
 * The owner's rule, in his example: if a class's exam is on
 * 16 July, the assigned teacher's marks options (enter / edit /
 * upload / submit — theory, internal, every component alike)
 * enable on 17 July, the day AFTER the exam — not on the exam
 * day itself, because the paper is written that day and
 * correction starts the next. Before that date the grid is
 * view-only and saves are refused. The
 * teacher saves drafts freely while entry is open, then SUBMITS:
 * from that moment the unit is locked — the teacher can no longer
 * edit those marks. Only the school Admin can reopen a submitted
 * unit (for a genuine correction), and reopening is audited.
 *
 * The date gate reads the exam's own date sheet (exam_dates, one
 * row per exam+class+subject). An exam/class/subject with NO date
 * row is not gated — most existing exams have no sheet yet, and
 * their entry keeps working exactly as before.
 */
class MarkLifecycle
{
    /**
     * Full state for one (exam, class, section, subject) unit.
     *
     * @return array{exam_date:?string, open:bool, opens_on:?string,
     *               submitted:?\App\Models\MarkSubmission, locked:bool}
     */
    public static function state($examId, $classId, $sectionId, $subjectId): array
    {
        $raw = ExamDate::where('exam_id', $examId)
            ->where('my_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->value('exam_date');

        $examDate = null;
        $open = true;
        $opensOn = null;
        if ($raw) {
            try {
                $day = Carbon::parse($raw)->startOfDay();
                $examDate = $day->format('d M Y');
                // Owner's rule (2026-10-29): entry opens the day
                // AFTER the exam — the paper is written on the exam
                // day itself, correction starts the next day.
                if (!Carbon::today()->gt($day)) {
                    $open = false;
                    $opensOn = $day->copy()->addDay()->format('d M Y');
                }
            } catch (\Throwable $e) {
                // An unparseable stored date must not lock a whole
                // school out of marks entry — treat it as no gate.
                $examDate = null;
                $open = true;
            }
        }

        $submission = MarkSubmission::forUnit($examId, $sectionId, $subjectId);

        return [
            'exam_date' => $examDate,
            'open' => $open,
            'opens_on' => $opensOn,
            'submitted' => $submission,
            'locked' => $submission !== null || !$open,
        ];
    }

    /**
     * Hard gate for the save + submit endpoints: refuses with the
     * reason (already submitted, or the exam date has not arrived).
     */
    public static function guard($examId, $classId, $sectionId, $subjectId): void
    {
        $state = static::state($examId, $classId, $sectionId, $subjectId);
        if ($state['submitted']) {
            abort(403, 'These marks have been submitted and are locked. Ask the school Admin to reopen them if a correction is needed.');
        }
        if (!$state['open']) {
            abort(403, 'Marks entry for this subject opens on ' . $state['opens_on'] . ' — the day after the exam (' . $state['exam_date'] . ').');
        }
    }

    /**
     * The same gate as a message (null = the write may proceed).
     * The assignment/quiz → internal bridges use this: they answer
     * with a flash message instead of the marks screen's 403 page.
     */
    public static function blockReason($examId, $classId, $sectionId, $subjectId): ?string
    {
        $state = static::state($examId, $classId, $sectionId, $subjectId);
        if ($state['submitted']) {
            return 'These marks have already been submitted and are locked — internal marks cannot be pushed into a submitted unit. Ask the school Admin to reopen them first.';
        }
        if (!$state['open']) {
            return 'Marks entry for this subject opens on ' . $state['opens_on'] . ' — the day after the exam (' . $state['exam_date'] . '). Internal marks can be pushed from that date.';
        }
        return null;
    }
}
