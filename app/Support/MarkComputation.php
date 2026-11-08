<?php

namespace App\Support;

use App\Models\Exam;
use App\Models\ExamRecord;
use App\Models\Grade;
use App\Models\Mark;
use App\Repositories\ExamRepo;
use App\Repositories\MarkRepo;
use App\Repositories\MyClassRepo;

/**
 * Batch M2 — the single marks computation pipeline.
 *
 * Extracted from MarkController@update so that every writer
 * (teacher entry save, the assignment internal-marks push, future
 * surfaces) produces identical derived data. For each mark row it derives:
 *
 *   subject total  — via MarkScheme (configured components or legacy)
 *   tex{term}      — the dual-write: the subject total ALSO lands in
 *                    the legacy marks.tex{term} column, so tabulation,
 *                    cross-term totals and every old reader stay right
 *   grade_id       — MarkRepo::getGrade over the existing per-class
 *                    type bands (+ global fallback). Band logic and
 *                    the "total < 1 ⇒ no grade" quirk are unchanged.
 *   sub_pos        — MarkRepo::getSubPos (existing ordinal rule)
 *   exam_records   — total / ave / class_ave / pos via MarkRepo
 *
 * Null doctrine: a student with nothing entered in a subject gets
 * tex = NULL, grade = NULL, sub_pos = NULL — never a phantom 0 or a
 * phantom 1st place.
 *
 * Hotfix 2026-11-08 (owner: marks save sat on "Submitting…" for
 * seconds): the two recomputes used to call the MarkRepo aggregate
 * helpers once per mark / per student, and getPos re-ran a
 * per-student total query for EVERY student in the section — a
 * 40-student save cost ~2,600 queries. Both recomputes now load
 * their inputs in a handful of bulk queries and derive the very
 * same values in memory (the TimetableValidator overrideMap
 * idiom). Every formula, tie rule and null quirk of the MarkRepo
 * helpers is replicated exactly; OwnerMarksPerfTest pins the query
 * budget and the derived state against the fixture unit.
 */
class MarkComputation
{
    /**
     * Recompute tex{term} + grade + subject position for every mark
     * row of one (exam, class, section, subject, year), reading the
     * raw scores (components or legacy columns) fresh from storage.
     */
    public static function recomputeSubject(Exam $exam, $classId, $sectionId, $subjectId, $year)
    {
        $examRepo = app(ExamRepo::class);
        $classType = app(MyClassRepo::class)->findTypeByClass($classId);
        $tex = 'tex' . $exam->term;

        $marks = Mark::where([
            'exam_id' => $exam->id, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'subject_id' => $subjectId, 'year' => $year,
        ])->with('markComponents')->get();

        // Scheme resolved once for the unit (MarkScheme::subjectTotal
        // re-asked isConfigured() and re-read the component rows for
        // every mark).
        $components = MarkScheme::configuredComponents($exam->id, $subjectId);
        $configured = $components->isNotEmpty();
        $maxTotal = $configured
            ? (int) $components->sum('max_marks')
            : MarkScheme::legacyMaxTotal();

        $totals = [];
        foreach ($marks as $mk) {
            $totals[$mk->id] = self::subjectTotalFromLoaded($mk, $configured);
        }

        // Grade bands loaded once (MarkRepo::getGrade re-queried the
        // bands table for every mark).
        $primaryBands = Grade::where('class_type_id', $classType->id)->get();
        $primaryBandRows = $primaryBands->toArray();
        $fallbackBands = null;
        $fallbackBandRows = null;

        // Subject positions rank this unit's fresh totals against
        // the STORED totals of the whole class (MarkRepo::getSubPos
        // semantics): other sections keep the totals they already
        // have, this section's rows take the values just computed.
        $freshByStudent = [];
        foreach ($marks as $mk) {
            $freshByStudent[(int) $mk->student_id] = $totals[$mk->id];
        }
        $rankValues = [];
        $classRows = Mark::where([
            'exam_id' => $exam->id, 'my_class_id' => $classId,
            'subject_id' => $subjectId, 'year' => $year,
        ])->get(['student_id', 'section_id', $tex]);
        foreach ($classRows as $row) {
            $value = (int) $row->section_id === (int) $sectionId
                && array_key_exists((int) $row->student_id, $freshByStudent)
                ? $freshByStudent[(int) $row->student_id]
                : $row->$tex;
            if ($value !== null) {
                $rankValues[] = $value;
            }
        }
        rsort($rankValues);

        foreach ($marks as $mk) {
            $total = $totals[$mk->id];
            $gradeId = null;
            if ($total !== null) {
                // Grade on the percentage of the scheme maximum —
                // the bands are percentage bands (see
                // GradeCalculator::percentage).
                $pct = GradeCalculator::percentage($total, $maxTotal);
                $gradeId = self::gradeIdFor($pct, $primaryBands, $primaryBandRows, $fallbackBands, $fallbackBandRows);
            }
            // getSubPos: null total ⇒ no position; otherwise the
            // first index of the value in the descending class list
            // (ties share the better position), +1.
            $pos = $total === null || !$rankValues
                ? null
                : array_search($total, $rankValues) + 1;
            $examRepo->updateMark($mk->id, [
                $tex => $total,
                'grade_id' => $gradeId,
                'sub_pos' => $pos,
            ]);
        }
    }

    /**
     * MarkScheme::subjectTotal() computed from an eager-loaded
     * markComponents relation and a unit-level configured flag —
     * same values, no per-mark queries.
     */
    private static function subjectTotalFromLoaded(Mark $mark, bool $configured): ?int
    {
        if (!$configured) {
            // Legacy path: tca + exm, the formula the app has always
            // used — but NULL when all three inputs are unentered.
            if ($mark->t1 === null && $mark->t2 === null && $mark->exm === null) {
                return null;
            }
            $tca = $mark->tca !== null ? (int) $mark->tca : (int) $mark->t1 + (int) $mark->t2;
            return $tca + (int) $mark->exm;
        }

        $entered = $mark->markComponents->filter(function ($row) {
            return $row->score !== null;
        });
        if ($entered->isEmpty()) {
            return null;
        }
        return (int) $entered->sum('score');
    }

    /**
     * MarkRepo::getGrade() + getGrade2() with the band collections
     * loaded once: a below-1 score earns no grade; the class-type
     * bands win when they cover the score; the global (null
     * class-type) bands are the fallback whenever the class-type
     * set is empty or does not cover the score.
     */
    private static function gradeIdFor($pct, $primaryBands, array $primaryBandRows, &$fallbackBands, &$fallbackBandRows): ?int
    {
        if ($pct === null || $pct < 1) {
            return null;
        }
        if ($primaryBands->count() > 0) {
            $band = GradeCalculator::gradeFor($pct, $primaryBandRows);
            if ($band) {
                $grade = $primaryBands->firstWhere('id', $band['id']);
                return $grade ? (int) $grade->id : null;
            }
            return self::fallbackGradeIdFor($pct, $fallbackBands, $fallbackBandRows);
        }
        return self::fallbackGradeIdFor($pct, $fallbackBands, $fallbackBandRows);
    }

    private static function fallbackGradeIdFor($pct, &$fallbackBands, &$fallbackBandRows): ?int
    {
        if ($fallbackBands === null) {
            $fallbackBands = Grade::whereNull('class_type_id')->get();
            $fallbackBandRows = $fallbackBands->toArray();
        }
        if ($fallbackBands->count() > 0) {
            $band = GradeCalculator::gradeFor($pct, $fallbackBandRows);
            if ($band) {
                $grade = $fallbackBands->firstWhere('id', $band['id']);
                return $grade ? (int) $grade->id : null;
            }
        }
        return null;
    }

    /**
     * Recompute exam_records (total, ave, class_ave, pos) for every
     * student record of one (exam, class, section, year). These are
     * the aggregates the report card footer and tabulation print.
     *
     * The four MarkRepo aggregates are derived from ONE bulk read
     * of the exam's mark rows:
     *   total     — getExamTotalTerm: sum of the student's tex over
     *               the whole class (no section filter); SQL SUM
     *               semantics — 0 when no entered values exist.
     *   ave       — getExamAvgTerm: average of the student's tex
     *               values ABOVE 0 within this section, rounded to
     *               1dp; NULL when none.
     *   class_ave — getClassAvg: average of tex over EVERY mark row
     *               of the exam+class+year (nulls ignored), 1dp;
     *               NULL when nothing is entered.
     *   pos       — getPos: the student's total ranked (first-index
     *               / ties-share-best, array_search semantics)
     *               against the totals of every student who has
     *               mark rows in this section.
     */
    public static function recomputeRecords(Exam $exam, $classId, $sectionId, $year)
    {
        $examRepo = app(ExamRepo::class);
        $tex = 'tex' . $exam->term;

        $records = ExamRecord::where([
            'exam_id' => $exam->id, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'year' => $year,
        ])->get();

        if ($records->isEmpty()) {
            return;
        }

        $rows = Mark::where([
            'exam_id' => $exam->id, 'my_class_id' => $classId, 'year' => $year,
        ])->get(['student_id', 'section_id', $tex]);

        $totalByStudent = [];
        $aveSumByStudent = [];
        $aveCountByStudent = [];
        $rosterSeen = [];
        $classSum = 0;
        $classCount = 0;
        foreach ($rows as $row) {
            $value = $row->$tex;
            if ($value !== null) {
                $classSum += $value;
                $classCount++;
                $sid = (int) $row->student_id;
                $totalByStudent[$sid] = ($totalByStudent[$sid] ?? 0) + $value;
            }
            if ((int) $row->section_id === (int) $sectionId) {
                $sid = (int) $row->student_id;
                $rosterSeen[$sid] = true;
                if ($value !== null && $value > 0) {
                    $aveSumByStudent[$sid] = ($aveSumByStudent[$sid] ?? 0) + $value;
                    $aveCountByStudent[$sid] = ($aveCountByStudent[$sid] ?? 0) + 1;
                }
            }
        }
        $classAve = $classCount ? round($classSum / $classCount, 1) : null;

        $rosterTotals = [];
        foreach (array_keys($rosterSeen) as $sid) {
            $rosterTotals[] = $totalByStudent[$sid] ?? 0;
        }
        rsort($rosterTotals);

        foreach ($records as $exr) {
            $stId = (int) $exr->student_id;
            $total = $totalByStudent[$stId] ?? 0;
            $ave = !empty($aveCountByStudent[$stId])
                ? round($aveSumByStudent[$stId] / $aveCountByStudent[$stId], 1)
                : null;
            $examRepo->updateRecord(['id' => $exr->id], [
                'total' => $total,
                'ave' => $ave,
                'class_ave' => $classAve,
                'pos' => array_search($total, $rosterTotals) + 1,
            ]);
        }
    }

    /**
     * Whole-class re-derivation (kept in code after the standalone
     * Marks Correction page was removed by owner decision
     * 2026-10-23): grades are re-derived from the ALREADY STORED
     * tex{term} totals (raw scores
     * are not re-read), then records are recomputed. Semantics are
     * byte-identical to the controller code this replaces; for
     * configured subjects it stays correct because entry dual-writes
     * tex on every save.
     */
    public static function fixClass(Exam $exam, $classId, $sectionId, $year)
    {
        $markRepo = app(MarkRepo::class);
        $examRepo = app(ExamRepo::class);
        $classType = app(MyClassRepo::class)->findTypeByClass($classId);
        $tex = 'tex' . $exam->term;

        $marks = Mark::where([
            'exam_id' => $exam->id, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'year' => $year,
        ])->get();

        $maxBySubject = [];
        foreach ($marks as $mk) {
            if (!array_key_exists($mk->subject_id, $maxBySubject)) {
                $maxBySubject[$mk->subject_id] = MarkScheme::maxTotal($exam->id, $mk->subject_id);
            }
            $grade = $mk->$tex === null ? null : $markRepo->getGrade(
                GradeCalculator::percentage($mk->$tex, $maxBySubject[$mk->subject_id]),
                $classType->id
            );
            $examRepo->updateMark($mk->id, ['grade_id' => $grade ? $grade->id : null]);
        }

        self::recomputeRecords($exam, $classId, $sectionId, $year);
    }
}
