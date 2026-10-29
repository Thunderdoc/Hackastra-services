<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\QuizAttempt;

/**
 * Internal-marks importer (owner orders 2026-10-22 + 2026-10-29).
 *
 * The teacher chooses where the Internal component's marks come
 * from: typed manually on the marks grid, or filled from work the
 * students already did — assignments (every graded submission
 * across the teacher's own assignments for the class + subject)
 * or quizzes (the attempts of the quizzes the teacher picks).
 * Either way the aggregation is the teacher's choice: the average
 * of ALL scores, the average of the BEST N, or the average of the
 * LATEST N. Each score first becomes a percentage of its own
 * maximum, the chosen aggregate percentage is scaled onto the
 * Internal component's maximum, and only students who actually
 * have a graded score are written — a student with no submission
 * keeps a blank cell, never an invented zero.
 *
 * The marks-screen importer (MarkController@importInternal) and
 * the older per-module bridge buttons share these semantics; the
 * bridges keep their own write loops, this class owns the maths.
 */
class InternalMarksImporter
{
    /**
     * The scheme's Internal component for an exam + subject — the
     * same resolution the bridges have always used (a configured
     * component named Internal / Internal Assessment / INT, never
     * a legacy column).
     */
    public static function internalComponent($examId, $subjectId)
    {
        foreach (MarkScheme::components($examId, $subjectId) as $component) {
            if ($component['field'] !== null) {
                continue;
            }
            if ($component['name'] === 'Internal'
                || $component['name'] === 'Internal Assessment'
                || $component['short_code'] === 'INT'
                || stripos($component['name'], 'Internal') === 0) {
                return $component;
            }
        }
        return null;
    }

    /**
     * Per-student percentage entries from ASSIGNMENTS: every graded
     * submission across all of this teacher's assignments for the
     * class + subject.
     *
     * @return array<int, array<int, array{pct: float, at: int}>> keyed by student user id
     */
    public static function assignmentPercents($teacherId, $classId, $subjectId): array
    {
        $assignments = Assignment::where([
                'teacher_id' => $teacherId,
                'my_class_id' => $classId,
                'subject_id' => $subjectId,
            ])
            ->with(['submissions' => function ($q) { $q->whereNotNull('marks_awarded'); }])
            ->get();

        $percents = [];
        foreach ($assignments as $assignment) {
            if (!($assignment->max_marks > 0)) {
                continue;
            }
            foreach ($assignment->submissions as $sub) {
                $percents[$sub->student_id][] = [
                    'pct' => ($sub->marks_awarded / $assignment->max_marks) * 100,
                    'at' => $assignment->created_at ? $assignment->created_at->timestamp : 0,
                ];
            }
        }
        return $percents;
    }

    /**
     * Per-student percentage entries from the chosen QUIZZES: one
     * entry per attempt (Quiz v2 allows a single attempt each).
     *
     * @return array<int, array<int, array{pct: float, at: int}>> keyed by student user id
     */
    public static function quizPercents(array $quizIds): array
    {
        if (!$quizIds) {
            return [];
        }
        $attempts = QuizAttempt::with('student_record')
            ->whereIn('quiz_id', $quizIds)->get();

        $percents = [];
        foreach ($attempts as $attempt) {
            $record = $attempt->student_record;
            if (!$record || !$record->user_id || (int) $attempt->total <= 0) {
                continue;
            }
            $percents[$record->user_id][] = [
                'pct' => ((int) $attempt->score / (int) $attempt->total) * 100,
                'at' => $attempt->submitted_at ? strtotime($attempt->submitted_at) : 0,
            ];
        }
        return $percents;
    }

    /**
     * The teacher's aggregation choice: 'all' averages every
     * entry, 'best' averages the best N percentages, 'latest'
     * averages the N most recent entries. Null when there is
     * nothing to aggregate.
     */
    public static function aggregate(array $entries, string $mode, ?int $n): ?float
    {
        if (!$entries) {
            return null;
        }
        if ($mode === 'best') {
            usort($entries, function ($a, $b) { return $b['pct'] <=> $a['pct']; });
            $entries = array_slice($entries, 0, max(1, (int) $n));
        } elseif ($mode === 'latest') {
            usort($entries, function ($a, $b) { return $b['at'] <=> $a['at']; });
            $entries = array_slice($entries, 0, max(1, (int) $n));
        }
        $sum = 0.0;
        foreach ($entries as $e) {
            $sum += $e['pct'];
        }
        return $sum / count($entries);
    }

    /** Scale an aggregate percentage onto the component's maximum. */
    public static function scaled(float $percent, $component): int
    {
        $max = (int) $component['max_marks'];
        $score = (int) round($percent / 100 * $max);
        return max(0, min($max, $score));
    }

    /** Normalise the request's mode/N pair (mirrors the bridges). */
    public static function modeFromRequest($req): array
    {
        $mode = strtolower((string) $req->input('mode', ''));
        if (!in_array($mode, ['best', 'latest'], true)) {
            $mode = 'all';
        }
        if ($mode === 'all') {
            return ['mode' => 'all', 'n' => null];
        }
        $n = (int) $req->input('avg_n', 0);
        return ['mode' => $mode, 'n' => $n > 0 ? $n : 1];
    }
}
