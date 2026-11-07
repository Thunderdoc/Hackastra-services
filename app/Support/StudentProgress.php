<?php

namespace App\Support;

use App\Models\AssessmentPeriod;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\Subject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 — Parent Progress assembler (design: hk-tmp/round4/
 * f2-parent-progress-design.md §3). Read-only, viewer-agnostic:
 * it takes already-scoped StudentRecords (the caller owns the
 * scope — a parent's children, never a request parameter) and
 * assembles, per child:
 *
 *   periods    — published-only marks trend points (§3.2), one
 *                per eligible assessment period, chronological.
 *                Overall % = mean of per-subject percentages
 *                (GradeCalculator::percentage over the STORED
 *                tex{term} total and the batched scheme maximum);
 *                exam_records is never queried.
 *   attendance — per-month trend + session totals (§3.3) from ONE
 *                grouped query, keyed by student_record_id.
 *   fees_blocked / fees_due — FeeDefaulter::outstandingFor, the
 *                single dues source (§5.3). A blocked child's
 *                marks are never loaded for display: its user id
 *                is excluded from the marks query and its points
 *                are forced empty server-side.
 *
 * Period resolution is dual-mode (§2.3): the real
 * assessment_periods table when it (and exams.period_id) exists,
 * with a per-exam legacy fallback for NULL period_id rows, else
 * synthesised legacy periods — byte-for-byte the R1 backfill
 * shape. Unit periods never become points; standalone periods
 * with counts_toward_annual = false are excluded, matching the
 * annual card.
 *
 * D1 note: subject totals are read from the stored tex{term}
 * column. If R1's deferred generic total store (D1) lands, this
 * is the one read that changes.
 */
class StudentProgress
{
    /**
     * @param  Collection $records  StudentRecord models, already scoped
     * @return array  record_id => [record, periods, attendance, fees_blocked, fees_due]
     */
    public static function forChildren(Collection $records, string $session): array
    {
        $out = [];
        if ($records->isEmpty()) {
            return $out;
        }
        $records = $records->values();

        // §5.3 fee gate — per child by design (FeeDefaulter is the
        // single dues source); bounded by the children count.
        $feesDue = [];
        foreach ($records as $record) {
            $feesDue[$record->id] = FeeDefaulter::outstandingFor($record->user_id, $session);
        }

        $attendance = self::attendanceFor($records);

        $periodsByChild = self::periodPoints($records, $session, $feesDue);

        foreach ($records as $record) {
            $blocked = $feesDue[$record->id] > 0;
            $out[$record->id] = [
                'record' => $record,
                'periods' => $blocked ? [] : ($periodsByChild[$record->id] ?? []),
                'attendance' => $attendance[$record->id] ?? [
                    'months' => [],
                    'session' => ['percent' => null, 'present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0],
                ],
                'fees_blocked' => $blocked,
                'fees_due' => $feesDue[$record->id],
            ];
        }

        return $out;
    }

    /**
     * §2.3 resolver: eligibility-filtered period descriptors for
     * the session, sort_order ascending. Each descriptor:
     * [key, id, name, kind, legacy_term, counts_toward_annual,
     *  sort_order, fed_by_unit]
     * key is "p{id}" in real mode, "t{term}" in legacy mode.
     */
    public static function periodsFor(string $session): Collection
    {
        if (self::realMode()) {
            $periods = AssessmentPeriod::where('year', $session)
                ->orderBy('sort_order')->orderBy('id')->get();

            $fedBy = $periods->where('kind', 'unit')
                ->pluck('feeds_period_id')->filter()->map(function ($i) { return (int) $i; })->all();

            $eligible = $periods
                ->filter(function ($p) {
                    return in_array($p->kind, ['term', 'standalone'], true) && $p->counts_toward_annual;
                })
                ->map(function ($p) use ($fedBy) {
                    return [
                        'key' => 'p' . $p->id,
                        'id' => (int) $p->id,
                        'name' => $p->name,
                        'kind' => $p->kind,
                        'legacy_term' => $p->legacy_term !== null ? (int) $p->legacy_term : null,
                        'counts_toward_annual' => true,
                        'sort_order' => (int) $p->sort_order,
                        'fed_by_unit' => in_array((int) $p->id, $fedBy, true),
                    ];
                })
                ->values();

            // §2.3 per-exam fallback: an exam with period_id NULL
            // whose term no period row covers at all (not even an
            // ineligible one) synthesises its legacy period — the
            // same shape as the legacy mode below.
            $coveredTerms = $periods->pluck('legacy_term')
                ->filter(function ($t) { return $t !== null; })
                ->map(function ($t) { return (int) $t; })->all();
            $nullTerms = Exam::where('year', $session)->whereNull('period_id')
                ->pluck('term')->unique()
                ->map(function ($t) { return (int) $t; })
                ->reject(function ($t) use ($coveredTerms) {
                    return in_array($t, $coveredTerms, true);
                });

            foreach ($nullTerms as $term) {
                $eligible->push([
                    'key' => 't' . $term,
                    'id' => null,
                    'name' => 'Term ' . $term,
                    'kind' => 'term',
                    'legacy_term' => $term,
                    'counts_toward_annual' => true,
                    'sort_order' => $term * 10,
                    'fed_by_unit' => false,
                ]);
            }

            return $eligible->sortBy('sort_order')->values();
        }

        // Legacy mode: one virtual period per distinct exam term —
        // exactly the R1 §3.1 backfill row shape.
        $terms = Exam::where('year', $session)
            ->pluck('term')->unique()->map(function ($t) { return (int) $t; })->sort()->values();

        return $terms->map(function ($term) {
            return [
                'key' => 't' . $term,
                'id' => null,
                'name' => 'Term ' . $term,
                'kind' => 'term',
                'legacy_term' => $term,
                'counts_toward_annual' => true,
                'sort_order' => $term * 10,
                'fed_by_unit' => false,
            ];
        });
    }

    protected static function realMode(): bool
    {
        return Schema::hasTable('assessment_periods') && Schema::hasColumn('exams', 'period_id');
    }

    /** §3.2 — marks trend points per child. */
    protected static function periodPoints(Collection $records, string $session, array $feesDue): array
    {
        $points = [];
        foreach ($records as $record) {
            $points[$record->id] = [];
        }

        $periods = self::periodsFor($session);
        $periodByKey = $periods->keyBy('key');
        // Legacy-term lookup so a NULL period_id exam (real mode)
        // and every legacy-mode exam land on their period.
        $periodByTerm = [];
        foreach ($periods as $p) {
            if ($p['legacy_term'] !== null && !isset($periodByTerm[$p['legacy_term']])) {
                $periodByTerm[$p['legacy_term']] = $p;
            }
        }

        $classIds = $records->pluck('my_class_id')->unique()->map(function ($i) { return (int) $i; })->all();

        // M3 — eligible, published exams only. Publication is a
        // query-level gate (§5.2): unpublished rows never load.
        $examQuery = Exam::where('year', $session)
            ->where('results_published', true)
            ->where(function ($q) use ($classIds) {
                $q->where('my_class_id', 0)->orWhereIn('my_class_id', $classIds);
            });
        if (self::realMode()) {
            $examQuery->with('period');
            $eligibleIds = $periods->pluck('id')->filter()->all();
            $examQuery->where(function ($q) use ($eligibleIds) {
                $q->whereIn('period_id', $eligibleIds ?: [0])->orWhereNull('period_id');
            });
        }
        $exams = $examQuery->get();

        // Resolve each exam to its period descriptor; drop exams
        // whose period is ineligible (unit / not counting / a
        // NULL-period exam whose term has no eligible period).
        $examPeriod = []; // exam_id => period descriptor
        foreach ($exams as $exam) {
            $period = null;
            if (self::realMode() && $exam->period_id) {
                $period = $periodByKey->get('p' . $exam->period_id);
            } else {
                $period = $periodByTerm[(int) $exam->term] ?? null;
                // Legacy mode has no period rows: synthesise on the
                // fly from the resolver's own list (already keyed
                // by term above); a term absent there means the
                // resolver saw no such exam — impossible here.
                if ($period === null && !self::realMode()) {
                    $period = [
                        'key' => 't' . (int) $exam->term, 'id' => null,
                        'name' => 'Term ' . (int) $exam->term, 'kind' => 'term',
                        'legacy_term' => (int) $exam->term, 'counts_toward_annual' => true,
                        'sort_order' => (int) $exam->term * 10, 'fed_by_unit' => false,
                    ];
                }
            }
            if ($period !== null) {
                $examPeriod[$exam->id] = $period;
            }
        }
        $exams = $exams->filter(function ($e) use ($examPeriod) {
            return isset($examPeriod[$e->id]);
        });
        if ($exams->isEmpty()) {
            return $points;
        }

        // Per child: ≤1 exam per period — class-scoped beats
        // global, then lowest id (§2.2, deterministic).
        $examForChildPeriod = []; // record_id => period_key => Exam
        foreach ($records as $record) {
            foreach ($exams as $exam) {
                if ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $record->my_class_id) {
                    continue;
                }
                $key = $examPeriod[$exam->id]['key'];
                $current = $examForChildPeriod[$record->id][$key] ?? null;
                if ($current === null
                    || ((int) $current->my_class_id === 0 && (int) $exam->my_class_id !== 0)
                    || ((int) $current->my_class_id === (int) $exam->my_class_id && $exam->id < $current->id)) {
                    $examForChildPeriod[$record->id][$key] = $exam;
                }
            }
        }

        // Blocked children contribute no user ids to the marks
        // query at all (§5.3 — no marks data server-side).
        $openRecords = $records->filter(function ($r) use ($feesDue) {
            return $feesDue[$r->id] <= 0;
        });
        if ($openRecords->isEmpty()) {
            return $points;
        }
        $examIds = $exams->pluck('id')->map(function ($i) { return (int) $i; })->all();
        $childUserIds = $openRecords->pluck('user_id')->map(function ($i) { return (int) $i; })->all();

        // M4 — marks (key = child USER ids, §1.3), stored grades.
        $marks = Mark::with('grade')
            ->where('year', $session)
            ->whereIn('exam_id', $examIds)
            ->whereIn('student_id', $childUserIds)
            ->get();

        // M5 — scheme maxima, batched (§3.4).
        $maxima = MarkScheme::maxTotalsForExams($examIds);
        $legacyMax = MarkScheme::legacyMaxTotal();

        // M6 — subject names from the mark rows themselves.
        $subjectNames = $marks->isEmpty()
            ? collect()
            : Subject::whereIn('id', $marks->pluck('subject_id')->unique()->all())->pluck('name', 'id');

        // M6b — class types for the period grade, bands loaded
        // once per class type (never per point).
        $classTypes = MyClass::with('class_type')->whereIn('id', $classIds)->get()
            ->mapWithKeys(function ($c) {
                return [$c->id => $c->class_type_id ? (int) $c->class_type_id : null];
            });
        $gradeCache = [];

        $marksByStudentExam = $marks->groupBy(function ($m) {
            return $m->student_id . ':' . $m->exam_id;
        });

        foreach ($openRecords as $record) {
            $childPoints = [];
            $previousPct = null;
            foreach ($periods as $period) {
                $exam = $examForChildPeriod[$record->id][$period['key']] ?? null;
                if (!$exam) {
                    continue;
                }
                $tex = 'tex' . $exam->term;
                $rows = $marksByStudentExam->get($record->user_id . ':' . $exam->id) ?: collect();

                $subjects = [];
                $pcts = [];
                foreach ($rows as $mark) {
                    $total = $mark->$tex;
                    $total = $total === null ? null : (int) $total;
                    $max = $maxima[$exam->id . ':' . $mark->subject_id] ?? $legacyMax;
                    $pct = $total === null ? null : GradeCalculator::percentage($total, $max);
                    if ($pct !== null) {
                        $pcts[] = $pct;
                    }
                    $subjects[] = [
                        'name' => $subjectNames[$mark->subject_id] ?? 'Subject',
                        'total' => $total,
                        'max' => $max,
                        'pct' => $pct === null ? null : round($pct, 1),
                        'grade' => $mark->grade ? $mark->grade->name : null,
                        'remark' => $mark->grade ? $mark->grade->remark : null,
                    ];
                }
                usort($subjects, function ($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });

                if (!$pcts) {
                    continue; // §3.2 existence rule — no point, never 0%
                }
                $periodPct = round(array_sum($pcts) / count($pcts), 1);
                $classTypeId = $classTypes[$record->my_class_id] ?? null;
                $gradeName = self::gradeName($periodPct, $classTypeId, $gradeCache);

                $childPoints[] = [
                    'period_key' => $period['key'],
                    'period_name' => $period['name'],
                    'period_kind' => $period['kind'],
                    'exam_id' => (int) $exam->id,
                    'exam_name' => $exam->name,
                    'pct' => $periodPct,
                    'grade' => $gradeName,
                    'delta' => $previousPct === null ? null : round($periodPct - $previousPct, 1),
                    'subjects' => $subjects,
                    'fed_by_unit' => (bool) $period['fed_by_unit'],
                ];
                $previousPct = $periodPct;
            }
            $points[$record->id] = $childPoints;
        }

        return $points;
    }

    /**
     * Period grade through the same bands MarkRepo::getGrade
     * uses (class-type bands, global fallback, null under 1),
     * with the bands fetched once per class type per call.
     */
    protected static function gradeName(float $pct, $classTypeId, array &$cache): ?string
    {
        if ($pct < 1) {
            return null;
        }
        $cacheKey = ($classTypeId ?: 'global') . ':' . $pct;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $grade = null;
        if ($classTypeId) {
            if (!isset($cache['bands:' . $classTypeId])) {
                $cache['bands:' . $classTypeId] = Grade::where('class_type_id', $classTypeId)->get();
            }
            $bands = $cache['bands:' . $classTypeId];
            if ($bands->count() > 0) {
                $band = GradeCalculator::gradeFor($pct, $bands->toArray());
                $grade = $band ? $bands->firstWhere('id', $band['id']) : null;
                if ($grade) {
                    return $cache[$cacheKey] = $grade->name;
                }
            }
        }
        if (!isset($cache['bands:global'])) {
            $cache['bands:global'] = Grade::whereNull('class_type_id')->get();
        }
        $global = $cache['bands:global'];
        if ($global->count() > 0) {
            $band = GradeCalculator::gradeFor($pct, $global->toArray());
            $grade = $band ? $global->firstWhere('id', $band['id']) : null;
        }

        return $cache[$cacheKey] = ($grade ? $grade->name : null);
    }

    /** §3.3 — attendance months + session totals, ONE grouped query. */
    protected static function attendanceFor(Collection $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $out[$record->id] = [
                'months' => [],
                'session' => ['percent' => null, 'present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0],
            ];
        }
        if ($records->isEmpty() || !Schema::hasTable('attendance_session_entries')) {
            return $out;
        }

        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', attendance_sessions.date)"
            : "DATE_FORMAT(attendance_sessions.date, '%Y-%m')";

        $rows = DB::table('attendance_session_entries as ase')
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'ase.session_id')
            ->whereIn('ase.student_record_id', $records->pluck('id')->all())
            ->selectRaw("ase.student_record_id as rid, {$monthExpr} as ym, ase.status as status, COUNT(*) as c")
            ->groupBy('rid', 'ym', 'ase.status')
            ->get();

        $byChild = [];
        foreach ($rows as $row) {
            $byChild[$row->rid][$row->ym][$row->status] = (int) $row->c;
        }

        foreach ($byChild as $rid => $months) {
            ksort($months);
            $monthPoints = [];
            $session = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
            foreach ($months as $ym => $counts) {
                $present = $counts['present'] ?? 0;
                $absent = $counts['absent'] ?? 0;
                $late = $counts['late'] ?? 0;
                $marked = $present + $absent + $late;
                if ($marked < 1) {
                    continue;
                }
                $monthPoints[] = [
                    'key' => $ym,
                    'label' => Carbon::createFromFormat('Y-m-d', $ym . '-01')->format('F Y'),
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'marked' => $marked,
                    'percent' => (int) round(($present + $late) / $marked * 100),
                ];
                $session['present'] += $present;
                $session['absent'] += $absent;
                $session['late'] += $late;
                $session['marked'] += $marked;
            }
            $session['percent'] = $session['marked'] > 0
                ? (int) round(($session['present'] + $session['late']) / $session['marked'] * 100)
                : null;
            $out[$rid] = ['months' => $monthPoints, 'session' => $session];
        }

        return $out;
    }
}
