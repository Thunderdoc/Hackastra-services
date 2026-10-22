<?php

namespace App\Support;

use App\Models\Exam;
use App\Models\ExamRecord;
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
        $markRepo = app(MarkRepo::class);
        $examRepo = app(ExamRepo::class);
        $classType = app(MyClassRepo::class)->findTypeByClass($classId);
        $tex = 'tex' . $exam->term;

        $marks = Mark::where([
            'exam_id' => $exam->id, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'subject_id' => $subjectId, 'year' => $year,
        ])->get();

        foreach ($marks as $mk) {
            $total = MarkScheme::subjectTotal($mk);
            $grade = $total === null ? null : $markRepo->getGrade($total, $classType->id);
            $examRepo->updateMark($mk->id, [
                $tex => $total,
                'grade_id' => $grade ? $grade->id : null,
            ]);
            $mk->$tex = $total;
        }

        // Second pass: positions read the freshly written totals.
        foreach ($marks as $mk) {
            $pos = $mk->$tex === null
                ? null
                : $markRepo->getSubPos($mk->student_id, $exam, $classId, $subjectId, $year);
            $examRepo->updateMark($mk->id, ['sub_pos' => $pos]);
        }
    }

    /**
     * Recompute exam_records (total, ave, class_ave, pos) for every
     * student record of one (exam, class, section, year). These are
     * the aggregates the report card footer and tabulation print.
     */
    public static function recomputeRecords(Exam $exam, $classId, $sectionId, $year)
    {
        $markRepo = app(MarkRepo::class);
        $examRepo = app(ExamRepo::class);

        $records = ExamRecord::where([
            'exam_id' => $exam->id, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'year' => $year,
        ])->get();

        foreach ($records as $exr) {
            $stId = $exr->student_id;
            $examRepo->updateRecord(['id' => $exr->id], [
                'total' => $markRepo->getExamTotalTerm($exam, $stId, $classId, $year),
                'ave' => $markRepo->getExamAvgTerm($exam, $stId, $classId, $sectionId, $year),
                'class_ave' => $markRepo->getClassAvg($exam, $classId, $year),
                'pos' => $markRepo->getPos($stId, $exam, $classId, $sectionId, $year),
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

        foreach ($marks as $mk) {
            $grade = $markRepo->getGrade($mk->$tex, $classType->id);
            $examRepo->updateMark($mk->id, ['grade_id' => $grade ? $grade->id : null]);
        }

        self::recomputeRecords($exam, $classId, $sectionId, $year);
    }
}
