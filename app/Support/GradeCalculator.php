<?php

namespace App\Support;

/**
 * Pure grade/total logic for the marks workflow (audit §7).
 *
 * Deliberately free of Eloquent/DB so it can be unit-tested in isolation
 * and reused by the marksheet, tabulation and student views — one source
 * of truth instead of three copies of the same arithmetic.
 *
 * Grade bands are passed in (they come from the `grades` table in the app):
 * each band is ['grade' => 'A', 'mark_from' => 70, 'mark_to' => 100, 'remark' => 'Excellent'].
 */
class GradeCalculator
{
    /**
     * Distinguish "missing" from "zero": null stays null, never a fake 0.
     */
    public static function total(array $scores): ?float
    {
        $present = array_filter($scores, function ($score) {
            return $score !== null && $score !== '';
        });

        if (count($present) === 0) {
            return null;
        }

        return array_sum(array_map('floatval', $present));
    }

    public static function average(array $scores): ?float
    {
        $total = self::total($scores);
        if ($total === null) {
            return null;
        }

        $count = count(array_filter($scores, function ($score) {
            return $score !== null && $score !== '';
        }));

        return $count > 0 ? round($total / $count, 2) : null;
    }

    /**
     * @return array|null the matching band, or null when no band covers the score
     */
    public static function gradeFor($score, array $bands): ?array
    {
        if ($score === null || $score === '') {
            return null;
        }

        foreach ($bands as $band) {
            if ($score >= $band['mark_from'] && $score <= $band['mark_to']) {
                return $band;
            }
        }

        return null;
    }

    public static function isPass($score, $passMark = 40): bool
    {
        return $score !== null && $score !== '' && (float) $score >= (float) $passMark;
    }

    /**
     * Competition ranking ("1, 2, 2, 4") — ties share a position and the
     * next position skips, which is the defined tie rule required by §7.
     *
     * @param  array $totals student_id => total (nulls are unranked)
     * @return array student_id => position|null
     */
    public static function positions(array $totals): array
    {
        $ranked = array_filter($totals, function ($total) {
            return $total !== null && $total !== '';
        });
        arsort($ranked);

        $positions = [];
        $position = 0;
        $seen = 0;
        $previous = null;

        foreach ($ranked as $id => $total) {
            $seen++;
            if ($previous === null || (float) $total !== (float) $previous) {
                $position = $seen;
                $previous = $total;
            }
            $positions[$id] = $position;
        }

        foreach ($totals as $id => $total) {
            if (!array_key_exists($id, $positions)) {
                $positions[$id] = null;
            }
        }

        return $positions;
    }
}
