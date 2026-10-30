<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\StudentRecord;
use App\Repositories\MarkRepo;
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
            'principal_signature' => Qs::getSetting('principal_signature'),
        ];
    }

    /**
     * Batch M4 — the ANNUAL / cumulative assembly (design §3.6,
     * owner decision D3). One pipeline feeds the annual card's two
     * renderings (marks.annual on screen, marks.print_annual in
     * print) through marks/partials/annual_report_card.
     *
     * DATA RULES — every number traces to stored data; nothing is
     * recomputed from components and no new storage exists:
     *  - A term cell is the mark row's tex{term} for that term's
     *    exam — the exact value the term card prints. NULL (or no
     *    mark row) is "no total for that term" and prints '–'.
     *  - ANNUAL SCORE per subject = the MEAN of the term totals
     *    that are present. A term with no total is EXCLUDED from
     *    the mean — never counted as 0. Because the grade bands
     *    are defined on the whole-mark 0–100 scale (and every term
     *    total is a whole mark), the annual score is the mean
     *    rounded to the nearest whole mark; the grade is read from
     *    that score via MarkRepo::getGrade (class-type bands,
     *    global fallback, total < 1 ⇒ no grade — the same rules
     *    the term pipeline applies).
     *  - SUBJECT POSITION (annual) = the pipeline's own ordinal
     *    rule (MarkRepo::getSubPos: rank in the DESC-sorted peer
     *    values, ties share the position) applied to the class's
     *    annual scores for the subject — class-wide, exactly the
     *    peer group getSubPos uses per term.
     *  - FOOTER: total = Σ annual subject scores; overall annual
     *    average = mean of the annual subject scores (1 decimal,
     *    the exam_records.ave convention); class annual average =
     *    the mean of the whole class's overall annual averages
     *    (the getClassAvg peer group); annual position = the
     *    ordinal rule over overall annual averages within the
     *    student's class + section (the getPos peer group).
     *
     * @param  StudentRecord $sr         the student's record
     * @param  string        $year       the session year
     * @param  iterable      $exams      the year's exams (any order;
     *                                   rendered in term order)
     * @param  iterable      $subjects   subjects of the student's class
     * @param  iterable      $marks      the student's mark rows (year)
     * @param  iterable      $classMarks mark rows of the student's
     *                                   whole class for the year
     * @param  int           $classTypeId class type for grade bands
     * @return array{columns: array, rows: array, footer: array,
     *               class_teacher: ?string, principal_name: ?string,
     *               stamp: ?string}
     */
    /**
     * Owner ask 2026-10-25 — automate the report-card footer: when
     * no manual comment was entered, the printed card carries a
     * comment derived from the student's own results instead of a
     * blank line. Manual comments always win (the partial checks
     * them first); these only fill the gap. A NULL average means no
     * marks were entered, so no comment is invented — the partial
     * falls back to the blank line.
     */
    public static function autoTeacherComment($exr): string
    {
        $ave = $exr->ave ?? null;
        if ($ave === null || $ave === '') {
            return '';
        }
        $ave = (float) $ave;
        if ($ave >= 90) {
            return 'Outstanding performance across all subjects. Keep up the excellent work.';
        }
        if ($ave >= 75) {
            return 'Very good performance. Consistent effort — keep it up.';
        }
        if ($ave >= 60) {
            return 'Good performance. A little more focus will bring even better results.';
        }
        if ($ave >= 50) {
            return 'Satisfactory performance. More regular study and practice will help improve.';
        }
        return 'Needs more effort and regular practice. Please meet the class teacher to plan improvement.';
    }

    public static function autoPrincipalComment($exr): string
    {
        $pos = $exr->pos ?? null;
        if ($pos !== null && (int) $pos === 1) {
            return 'First in class — an excellent achievement. Keep it up.';
        }
        if ($pos !== null && (int) $pos <= 3) {
            return 'Among the top students of the class. Well done.';
        }
        $ave = $exr->ave ?? null;
        if ($ave === null || $ave === '') {
            return '';
        }
        $ave = (float) $ave;
        if ($ave >= 75) {
            return 'Very good progress this term. Keep working hard.';
        }
        if ($ave >= 50) {
            return 'Good progress this term. Keep working hard.';
        }
        return 'Progress needs attention this term. The school will support improvement.';
    }

    public static function buildAnnual(StudentRecord $sr, $year, $exams, $subjects, $marks, $classMarks, $classTypeId)
    {
        $markRepo = app(MarkRepo::class);

        $exams = collect($exams)->sortBy('term')->values();
        $termOfExam = [];
        $columns = [];
        foreach ($exams as $ex) {
            $termOfExam[$ex->id] = (int) $ex->term;
            $columns[] = [
                'key' => 'T' . $ex->id,
                'label' => strtoupper(self::ordinal($ex->term)) . ' TERM',
                'exam' => $ex,
            ];
        }

        // Annual subject score for ANY student of the class, from
        // their mark rows: subject_id => annual score or NULL.
        // A student's total in a term is the mark row's tex{term}.
        $annualOf = function ($rows) use ($termOfExam) {
            $totals = []; // subject_id => [term totals present]
            foreach ($rows as $mk) {
                if (!isset($termOfExam[$mk->exam_id])) {
                    continue;
                }
                $tex = 'tex' . $termOfExam[$mk->exam_id];
                $v = $mk->$tex;
                if ($v !== null) {
                    $totals[$mk->subject_id][] = (int) $v;
                }
            }
            $annual = [];
            foreach ($totals as $subjectId => $present) {
                $annual[$subjectId] = (int) round(array_sum($present) / count($present));
            }
            return $annual;
        };

        // The student's own term cells, per subject.
        $ownTerms = []; // subject_id => [column key => total|null]
        foreach ($marks as $mk) {
            if (!isset($termOfExam[$mk->exam_id])) {
                continue;
            }
            $tex = 'tex' . $termOfExam[$mk->exam_id];
            $v = $mk->$tex;
            $ownTerms[$mk->subject_id]['T' . $mk->exam_id] = $v === null ? null : (int) $v;
        }
        $ownAnnual = $annualOf($marks);

        // Peer annuals: class-wide per subject (positions), and
        // overall annual averages per student (footer).
        $peerAnnual = [];   // subject_id => [annual scores]
        $peerOverall = [];  // student_id => overall annual average
        $sectionOverall = []; // same, class + section only
        foreach (collect($classMarks)->groupBy('student_id') as $studentId => $rows) {
            $annual = $annualOf($rows);
            foreach ($annual as $subjectId => $score) {
                $peerAnnual[$subjectId][] = $score;
            }
            if (!empty($annual)) {
                $overall = round(array_sum($annual) / count($annual), 1);
                $peerOverall[$studentId] = $overall;
                $first = $rows->first();
                if ($first && (int) $first->section_id === (int) $sr->section_id) {
                    $sectionOverall[$studentId] = $overall;
                }
            }
        }
        foreach ($peerAnnual as $subjectId => $scores) {
            rsort($peerAnnual[$subjectId]);
        }
        $sectionRanked = array_values($sectionOverall);
        rsort($sectionRanked);

        // Each subject's scheme maximum across the year's exams —
        // the annual average is graded on its percentage of that
        // maximum, like every term grade (see MarkComputation).
        $annualMax = [];
        foreach ($marks as $mk) {
            $m = MarkScheme::maxTotal($mk->exam_id, $mk->subject_id);
            if ($m > 0) {
                $annualMax[$mk->subject_id] = max($annualMax[$mk->subject_id] ?? 0, $m);
            }
        }

        $rows = [];
        foreach ($subjects as $sub) {
            $annual = $ownAnnual[$sub->id] ?? null;
            $grade = $annual === null ? null : $markRepo->getGrade(
                GradeCalculator::percentage($annual, $annualMax[$sub->id] ?? 100),
                $classTypeId
            );

            $pos = null;
            if ($annual !== null && !empty($peerAnnual[$sub->id])) {
                $found = array_search($annual, $peerAnnual[$sub->id]);
                $pos = $found === false ? null : $found + 1;
            }

            $rows[] = [
                'subject' => $sub,
                'terms' => $ownTerms[$sub->id] ?? [],
                'annual' => $annual,
                'grade' => $grade ? $grade->name : null,
                'remark' => $grade ? $grade->remark : null,
                'pos' => $pos,
            ];
        }

        $ownScores = array_values($ownAnnual);
        $footer = [
            'total' => empty($ownScores) ? null : array_sum($ownScores),
            'overall' => empty($ownScores) ? null : round(array_sum($ownScores) / count($ownScores), 1),
            'class_ave' => empty($peerOverall) ? null : round(array_sum($peerOverall) / count($peerOverall), 1),
            'pos' => null,
        ];
        if ($footer['overall'] !== null && !empty($sectionRanked)) {
            $found = array_search($footer['overall'], $sectionRanked);
            $footer['pos'] = $found === false ? null : $found + 1;
        }

        // Class Teacher / Principal / Stamp: the same sources and
        // the same never-invent rules as the term card (see build()).
        $teacher = null;
        if ($sr->section && $sr->section->teacher) {
            $teacher = $sr->section->teacher->name;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'footer' => $footer,
            'class_teacher' => $teacher,
            'principal_name' => Qs::getSetting('principal_name'),
            'stamp' => Qs::getSetting('school_stamp'),
            'principal_signature' => Qs::getSetting('principal_signature'),
        ];
    }

    /** Plain ordinal word for a term number (1 => FIRST). */
    protected static function ordinal($n)
    {
        $words = [1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth', 6 => 'Sixth'];
        return $words[(int) $n] ?? ('Term ' . $n);
    }
}
