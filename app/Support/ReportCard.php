<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\StudentRecord;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Batch M3 — report-card data assembly.
 *
 * ONE pipeline feeds both renderings of the official term card:
 * the on-screen marksheet (marks.show) and the printed document
 * (marks.print). Both controllers build the card through here and
 * both blades render the same partial
 * (marks/partials/report_card), so screen and print can never drift.
 *
 * Score columns are DYNAMIC: they come from MarkScheme for each
 * subject of the exam — the configured components (name + max), or,
 * for legacy-scheme subjects, the classic CA1 / CA2 / CA TOTAL /
 * EXAM columns over t1 / t2 / tca / exm. The grid's header is the
 * union of the columns in play; a subject that lacks a column
 * prints '–' in it (design §3.5).
 *
 * Totals, grades and positions are NOT recomputed here: the total
 * is the mark row's tex{term} and the footer comes from the
 * exam_records row — the exact values MarkComputation wrote and
 * every other surface (tabulation, dashboards) displays.
 *
 * NULL DOCTRINE: a NULL cell/total is "not entered" and renders
 * '–'; a stored 0 is a real zero and renders 0.
 */
class ReportCard
{
    /**
     * Build the card structure for one student + one exam.
     *
     * @param  StudentRecord $sr       the student's record (section/class)
     * @param  Exam          $exam     the exam (one term)
     * @param  iterable      $subjects subjects of the student's class
     * @param  iterable      $marks    the student's mark rows (any exams;
     *                                 rows for other exams are ignored)
     * @return array{columns: array, rows: array, exam_marks: array,
     *               class_teacher: ?string, principal_name: ?string,
     *               stamp: ?string}
     */
    public static function build(StudentRecord $sr, Exam $exam, $subjects, $marks)
    {
        $tex = 'tex' . $exam->term;

        $examMarks = new EloquentCollection();
        $bySubject = [];
        foreach ($marks as $mk) {
            if ((int) $mk->exam_id === (int) $exam->id) {
                $examMarks->push($mk);
                $bySubject[$mk->subject_id] = $mk;
            }
        }

        $columns = [];
        $rows = [];

        foreach ($subjects as $sub) {
            $mk = $bySubject[$sub->id] ?? null;
            $cells = [];

            if (MarkScheme::isConfigured($exam->id, $sub->id)) {
                foreach (MarkScheme::components($exam->id, $sub->id) as $c) {
                    $key = $c['short_code'];
                    if (!isset($columns[$key])) {
                        $columns[$key] = [
                            'key' => $key,
                            'label' => strtoupper($c['name']) . ' (' . $c['max_marks'] . ')',
                        ];
                    }
                    $cells[$key] = $mk ? MarkScheme::scoreFor($mk, $c) : null;
                }
            } else {
                // Legacy scheme: the historical CA layout, unchanged —
                // CA1 / CA2 over t1 / t2, their TOTAL over tca, EXAM
                // over exm. A missing mark row or NULL column is
                // "not entered" and renders '–'.
                $legacy = [
                    ['key' => 'CA1', 'label' => 'CA1 (20)', 'value' => $mk ? $mk->t1 : null],
                    ['key' => 'CA2', 'label' => 'CA2 (20)', 'value' => $mk ? $mk->t2 : null],
                    ['key' => 'CATOT', 'label' => 'CA TOTAL (40)', 'value' => $mk ? $mk->tca : null],
                    ['key' => 'EXM', 'label' => 'EXAM (60)', 'value' => $mk ? $mk->exm : null],
                ];
                foreach ($legacy as $col) {
                    if (!isset($columns[$col['key']])) {
                        $columns[$col['key']] = ['key' => $col['key'], 'label' => $col['label']];
                    }
                    $cells[$col['key']] = $col['value'] === null ? null : (int) $col['value'];
                }
            }

            $total = $mk ? $mk->$tex : null;

            $rows[] = [
                'subject' => $sub,
                'mark' => $mk,
                'cells' => $cells,
                'total' => $total === null ? null : (int) $total,
                'grade' => ($mk && $mk->grade) ? $mk->grade->name : null,
                'remark' => ($mk && $mk->grade) ? $mk->grade->remark : null,
                'pos' => $mk && $mk->sub_pos !== null ? (int) $mk->sub_pos : null,
            ];
        }

        // Class Teacher: the teacher of the student's own section
        // (sections.teacher_id). Unset => the card prints a blank
        // signature line, never a guessed name (design §3.5).
        $teacher = null;
        if ($sr->section && $sr->section->teacher) {
            $teacher = $sr->section->teacher->name;
        }

        return [
            'columns' => array_values($columns),
            'rows' => $rows,
            'exam_marks' => $examMarks,
            'class_teacher' => $teacher,
            'principal_name' => Qs::getSetting('principal_name'),
            'stamp' => Qs::getSetting('school_stamp'),
        ];
    }
}
