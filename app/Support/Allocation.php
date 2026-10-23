<?php

namespace App\Support;

use App\Models\Subject;

/**
 * Allocation engine (Scheduling Engine Slice C): for every
 * class-subject that needs attention — no teacher, or its teacher
 * is over the weekly cap — recommend the best eligible teacher and
 * SAY WHY. Rules, in order: (1) only eligible teachers are ever
 * proposed (eligibility rows + teachers already teaching that
 * subject name — Staffing::eligibleTeacherIds); ineligible is a
 * hard stop, never proposed, never applicable. (2) Among eligible
 * teachers, prefer the lowest committed weekly load that still
 * fits under the cap with this subject added. (3) If nobody fits,
 * say so plainly with the reason — a partial truth beats a
 * confident bad assignment.
 */
class Allocation
{
    /** Committed weekly periods per teacher id (configured requirements only). */
    public static function teacherLoads(): array
    {
        $loads = [];
        foreach (Subject::whereNotNull('teacher_id')->whereNotNull('periods_per_week')->get() as $s) {
            $loads[$s->teacher_id] = ($loads[$s->teacher_id] ?? 0) + (int) $s->periods_per_week;
        }

        return $loads;
    }

    /**
     * One row per class-subject needing attention:
     * [subject, issue, recommended_id, reason, eligible_count].
     * issue: 'unassigned' | 'overloaded'.
     */
    public static function recommendations(): array
    {
        $cap = Staffing::maxPeriodsPerWeek();
        $loads = self::teacherLoads();
        $rows = [];

        $subjects = Subject::with(['my_class', 'teacher'])
            ->orderBy('my_class_id')->orderBy('name')->get();

        foreach ($subjects as $s) {
            $issue = null;
            if (!$s->teacher_id) {
                $issue = 'unassigned';
            } elseif ($s->periods_per_week && ($loads[$s->teacher_id] ?? 0) > $cap) {
                $issue = 'overloaded';
            }
            if (!$issue) {
                continue;
            }

            $eligible = Staffing::eligibleTeacherIds($s->name);
            // Never recommend moving a subject to its current teacher.
            $candidates = array_values(array_filter($eligible, function ($id) use ($s) {
                return (int) $id !== (int) $s->teacher_id;
            }));

            $need = (int) ($s->periods_per_week ?? 0);
            $best = null;
            $bestLoad = null;
            foreach ($candidates as $id) {
                $load = $loads[$id] ?? 0;
                if ($need > 0 && $load + $need > $cap) {
                    continue;
                }
                if ($bestLoad === null || $load < $bestLoad) {
                    $best = $id;
                    $bestLoad = $load;
                }
            }

            if (empty($eligible)) {
                $reason = 'No eligible teacher is recorded for ' . $s->name . ' — record eligibility first.';
            } elseif ($best === null && $need > 0) {
                $reason = 'Every eligible teacher would exceed the weekly cap (' . $cap . ') with this subject added.';
            } elseif ($best === null) {
                $reason = 'Set the weekly-period requirement on this subject so the engine can check capacity.';
            } else {
                $reason = 'Lowest current load among ' . count($candidates) . ' eligible teacher(s): '
                    . $bestLoad . ' of ' . $cap . ' periods committed.';
            }

            $rows[] = [
                'subject' => $s,
                'issue' => $issue,
                'recommended_id' => $best,
                'reason' => $reason,
                'eligible_count' => count($eligible),
                'loads' => $loads,
            ];
        }

        return $rows;
    }
}
