<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use Illuminate\Support\Facades\DB;

/**
 * Staffing foundation (Scheduling Engine, 2026-10-23): the shared
 * numbers every planning surface must agree on — workload caps from
 * Settings, and who is eligible to teach a subject. Demand and
 * capacity calculations (staffing dashboard, allocation engine)
 * build on these so no two screens ever compute differently.
 */
class Staffing
{
    /** Maximum teaching periods per day for one teacher (Settings, default 8). */
    public static function maxPeriodsPerDay(): int
    {
        $v = (int) Qs::getSetting('teacher_max_periods_day');

        return $v > 0 ? $v : 8;
    }

    /** Maximum teaching periods per week for one teacher (Settings, default 40). */
    public static function maxPeriodsPerWeek(): int
    {
        $v = (int) Qs::getSetting('teacher_max_periods_week');

        return $v > 0 ? $v : 40;
    }

    /**
     * THE cap resolver (per-teacher caps, owner direction
     * 2026-11-06 "teacher capacity badhao"): a teacher's effective
     * caps are their own override (users.max_periods_per_day /
     * max_periods_per_week, set on the Edit User form) when one is
     * recorded, else the school defaults above. Every surface that
     * judges a SPECIFIC teacher's load — generation, the
     * validator, allocation, planning apply, manual cell writes,
     * the teacher's Workload page, the report below — reads caps
     * through here, never the raw Settings values, so no two
     * screens can disagree about the same teacher. (Surfaces that
     * quote the SCHOOL default itself — the Settings page, the
     * generate form's prefill — keep using the two global methods;
     * that is what a default means.)
     *
     * @return array{day: int, week: int}
     */
    public static function capsFor(\App\User $teacher): array
    {
        $day = $teacher->getAttribute('max_periods_per_day');
        $week = $teacher->getAttribute('max_periods_per_week');

        return [
            'day' => ($day !== null && (int) $day > 0) ? (int) $day : self::maxPeriodsPerDay(),
            'week' => ($week !== null && (int) $week > 0) ? (int) $week : self::maxPeriodsPerWeek(),
        ];
    }

    /**
     * The recorded overrides only: [teacher_id => ['day' => ?int,
     * 'week' => ?int]] for teachers carrying at least one. The
     * generator composes these over its own run values (the form's
     * day cap) the same way capsFor composes over the Settings
     * defaults: a teacher's own cap wins for that teacher. One
     * query; safe on an un-migrated install (no columns = no
     * overrides).
     */
    public static function overridesMap(): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'max_periods_per_day')) {
            return [];
        }
        $map = [];
        $rows = \App\User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNotNull('max_periods_per_day')
                    ->orWhereNotNull('max_periods_per_week');
            })
            ->get(['id', 'max_periods_per_day', 'max_periods_per_week']);
        foreach ($rows as $u) {
            $map[(int) $u->id] = [
                'day' => $u->max_periods_per_day !== null ? (int) $u->max_periods_per_day : null,
                'week' => $u->max_periods_per_week !== null ? (int) $u->max_periods_per_week : null,
            ];
        }

        return $map;
    }

    /**
     * Ids of teachers eligible to teach a subject NAME: everyone
     * with an eligibility row for it, PLUS anyone already assigned
     * to teach that subject in some class (an existing assignment
     * is itself evidence of eligibility — the school put them
     * there). Suspended/terminated accounts are excluded.
     */
    public static function eligibleTeacherIds(string $subjectName): array
    {
        $fromEligibility = TeacherEligibility::where('subject_name', $subjectName)
            ->pluck('teacher_id');
        $fromAssignments = Subject::where('name', $subjectName)
            ->whereNotNull('teacher_id')->pluck('teacher_id');

        return $fromEligibility->merge($fromAssignments)->unique()
            ->filter(function ($id) {
                $u = \App\User::find($id);

                return $u && $u->user_type === 'teacher'
                    && (!$u->getAttribute('status') || $u->getAttribute('status') === 'active');
            })->values()->all();
    }

    /**
     * The staffing report (Slice B): demand vs capacity per subject
     * name, and committed load per teacher — all from real configured
     * data. Definitions (shown on the dashboard so nobody has to
     * guess): DEMAND = sum of the weekly-period requirements set on
     * class-subjects of that name; COMMITTED = the part of demand
     * whose class-subject has a teacher; CAPACITY = eligible
     * teachers x the weekly cap (a gross ceiling — a teacher
     * eligible for two subjects counts toward both; the allocation
     * engine, Slice C, checks true feasibility); SHORTFALL =
     * demand above capacity. A class-subject without a configured
     * requirement is counted as UNCONFIGURED, never as zero demand.
     *
     * Scope note (wave 2, recorded 2026-10-25): COMMITTED here is a
     * CLASS-subject planning estimate from subjects.teacher_id. It
     * deliberately does NOT split by section_subject_teachers
     * overrides: an override changes who teaches a SECTION's
     * generated cells, and periods_per_week is a class-level number
     * with no per-section split to divide by — inventing one would
     * misstate both teachers' loads. True taught loads live in the
     * generated cells, and the clash validator reads those cells
     * override-aware. So when overrides exist, the validator — not
     * this estimate — is the authority for who actually teaches
     * what. Do not "fix" this estimate by substituting override
     * teachers for the whole subject.
     */
    public static function report(): array
    {
        $capWeek = self::maxPeriodsPerWeek();
        $subjects = Subject::with('teacher')->get();
        $sectionsByClass = \App\Models\Section::all()->groupBy('my_class_id');
        $byName = [];
        foreach (self::subjectNames() as $name) {
            // Round 13: same offered-only universe as Allocation
            // — a subject no section of its class offers carries
            // no demand here either, or the two surfaces would
            // disagree about the same subject.
            $rows = $subjects->where('name', $name)->filter(function ($r) use ($sectionsByClass) {
                return Streams::offeredInClass($r, $sectionsByClass->get($r->my_class_id, collect()));
            });
            // Round 14: a name with NO offered row anywhere is not
            // part of the staffing universe at all — previously it
            // still rendered as a zero row ("0 classes, Covered")
            // on the staffing page, and its eligible-teacher
            // capacity inflated the totals. Dropping it keeps the
            // page, the totals and the allocation surface in exact
            // agreement. (Readiness only reads teachers, so it is
            // unaffected; a name with offered rows but no weekly
            // requirement still renders as "Requirement incomplete".)
            if ($rows->isEmpty()) {
                continue;
            }
            $eligible = self::eligibleTeacherIds($name);
            $demand = (int) $rows->sum(function ($r) { return (int) ($r->periods_per_week ?? 0); });
            // Round 3: COMMITTED counts only LIVE teachers
            // (active teacher row). A ghost id (deleted row) or
            // a demoted/suspended teacher used to count as
            // committed, hiding demand that needs a teacher.
            $committed = (int) $rows->filter(function ($r) {
                return $r->teacher_id && $r->periods_per_week && $r->teacher
                    && $r->teacher->user_type === 'teacher'
                    && (!$r->teacher->getAttribute('status') || $r->teacher->getAttribute('status') === 'active');
            })->sum(function ($r) { return (int) $r->periods_per_week; });
            $capacity = count($eligible) * $capWeek;
            $byName[$name] = [
                'classes' => $rows->count(),
                'unconfigured' => $rows->filter(function ($r) { return !$r->periods_per_week; })->count(),
                'demand' => $demand,
                'committed' => $committed,
                'unassigned' => $demand - $committed,
                'eligible_count' => count($eligible),
                'capacity' => $capacity,
                'shortfall' => max(0, $demand - $capacity),
                'uncovered' => $demand > 0 && count($eligible) === 0,
            ];
        }

        $teachers = [];
        // Allotment audit 2026-10-28: committed load is the
        // override-aware placed-cell count (SectionTeacher::
        // effectiveLoads), and a teacher's subject list must
        // include their override subjects — otherwise an
        // override-only teacher displayed 0 classes, 0 subjects
        // and 0 committed periods while teaching a full week.
        $loads = SectionTeacher::effectiveLoads();
        $overrideSubjectIds = DB::table('section_subject_teachers')->get()
            ->groupBy('teacher_id')
            ->map(function ($rows) {
                return $rows->pluck('subject_id')->unique()->values()->all();
            })->all();
        $overrideSubjects = $overrideSubjectIds
            ? $subjects->whereIn('id', collect($overrideSubjectIds)->flatten()->unique()->values()->all())
            : collect();
        foreach (\App\User::where('user_type', 'teacher')->orderBy('name')->get() as $t) {
            $mine = $subjects->where('teacher_id', $t->id);
            if (!empty($overrideSubjectIds[$t->id])) {
                $mine = $mine->merge($overrideSubjects->whereIn('id', $overrideSubjectIds[$t->id]))
                    ->unique('id')->values();
            }
            $committed = (int) ($loads[$t->id] ?? 0);
            // Per-teacher caps (2026-11-06): HER row is judged
            // against HER cap (capsFor), not the school default.
            // The per-SUBJECT capacity above stays a gross ceiling
            // at the default — a subject's capacity spans many
            // teachers, so no single teacher's override can price
            // it; the per-teacher truth is this row.
            $teacherCapWeek = self::capsFor($t)['week'];
            $teachers[] = [
                'teacher' => $t,
                'classes' => $mine->count(),
                'subjects' => $mine->pluck('name')->unique()->values()->all(),
                'committed' => $committed,
                'unconfigured' => $mine->filter(function ($r) { return !$r->periods_per_week; })->count(),
                'cap' => $teacherCapWeek,
                'utilization' => $teacherCapWeek > 0 ? (int) round($committed * 100 / $teacherCapWeek) : 0,
                'overloaded' => $committed > $teacherCapWeek,
                'eligibility_count' => TeacherEligibility::where('teacher_id', $t->id)->count(),
            ];
        }

        return [
            'subjects' => $byName,
            'teachers' => $teachers,
            'totals' => [
                'demand' => array_sum(array_column($byName, 'demand')),
                'capacity' => array_sum(array_column($byName, 'capacity')),
                'shortage_subjects' => count(array_filter($byName, function ($r) { return $r['shortfall'] > 0 || $r['uncovered']; })),
                'unconfigured' => array_sum(array_column($byName, 'unconfigured')),
                'unassigned' => array_sum(array_column($byName, 'unassigned')),
                'overloaded' => count(array_filter($teachers, function ($r) { return $r['overloaded']; })),
                'cap_week' => $capWeek,
                'cap_day' => self::maxPeriodsPerDay(),
            ],
        ];
    }

    /** Distinct subject names in the school, sorted — the eligibility vocabulary. */
    public static function subjectNames(): array
    {
        return Subject::orderBy('name')->pluck('name')->unique()->values()->all();
    }
}
