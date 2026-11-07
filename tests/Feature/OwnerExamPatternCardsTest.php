<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Http\Controllers\SupportTeam\MarkController;
use App\Models\AssessmentPeriod;
use App\Models\Exam;
use App\Models\ExamSubjectComponent;
use App\Models\Mark;
use App\Models\StudentRecord;
use App\Repositories\MyClassRepo;
use App\Support\MarkComputation;
use App\Support\MarkScheme;
use App\Support\ReportCard;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exam Patterns Phase 1 — T16–T18: report-card byte parity for
 * legacy schools, annual exclusion of unit periods, and student
 * visibility of unit exams.
 *
 * T16's expected arrays were captured from ReportCard::build /
 * buildAnnual on the CURRENT (pre-Phase-1) code against the
 * seeded DB (student 2269, Class 11, exam 1) before any Phase-1
 * change was made, and are embedded verbatim below.
 */
class OwnerExamPatternCardsTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function annualCardData($studentId, $year)
    {
        $controller = app(MarkController::class);
        $m = new \ReflectionMethod($controller, 'annualCardData');
        $m->setAccessible(true);
        return $m->invoke($controller, $studentId, $year);
    }

    protected function normalizeBuild(array $card): array
    {
        return [
            'columns' => $card['columns'],
            'rows' => array_map(function ($r) {
                return [
                    'subject_id' => $r['subject']->id,
                    'cells' => $r['cells'],
                    'total' => $r['total'],
                    'grade' => $r['grade'],
                    'remark' => $r['remark'],
                    'pos' => $r['pos'],
                ];
            }, $card['rows']),
            'class_teacher' => $card['class_teacher'],
        ];
    }

    protected function normalizeAnnual(array $card): array
    {
        return [
            'columns' => array_map(function ($c) {
                return ['key' => $c['key'], 'label' => $c['label'], 'exam_id' => $c['exam']->id];
            }, $card['columns']),
            'rows' => array_map(function ($r) {
                return [
                    'subject_id' => $r['subject']->id,
                    'terms' => $r['terms'],
                    'annual' => $r['annual'],
                    'grade' => $r['grade'],
                    'remark' => $r['remark'],
                    'pos' => $r['pos'],
                ];
            }, $card['rows']),
            'footer' => $card['footer'],
            'class_teacher' => $card['class_teacher'],
        ];
    }

    /* ---------------- T16: byte parity ---------------- */

    public function test_t16_report_card_byte_parity_for_seeded_data()
    {
        $expectedBuild = [
            'columns' => [
    [
        'key' => 'CA1',
        'label' => 'CA1 (20)',
    ],
    [
        'key' => 'CA2',
        'label' => 'CA2 (20)',
    ],
    [
        'key' => 'CATOT',
        'label' => 'CA TOTAL (40)',
    ],
    [
        'key' => 'EXM',
        'label' => 'EXAM (60)',
    ],
    [
        'key' => 'TH',
        'label' => 'THEORY (60)',
    ],
    [
        'key' => 'INT',
        'label' => 'INTERNAL ASSESSMENT (30)',
    ],
    [
        'key' => 'VIVA',
        'label' => 'VIVA (10)',
    ],
],
            'rows' => [
    [
        'subject_id' => 66,
        'cells' => [
            'CA1' => 9,
            'CA2' => 8,
            'CATOT' => 17,
            'EXM' => 54,
        ],
        'total' => 71,
        'grade' => 'A',
        'remark' => 'Excellent',
        'pos' => null,
    ],
    [
        'subject_id' => 61,
        'cells' => [
            'TH' => 36,
            'INT' => 18,
            'VIVA' => 6,
        ],
        'total' => 60,
        'grade' => 'B',
        'remark' => 'Very Good',
        'pos' => null,
    ],
    [
        'subject_id' => 65,
        'cells' => [
            'CA1' => 12,
            'CA2' => 11,
            'CATOT' => 23,
            'EXM' => 29,
        ],
        'total' => 52,
        'grade' => 'C',
        'remark' => 'Good',
        'pos' => null,
    ],
    [
        'subject_id' => 62,
        'cells' => [
            'CA1' => 20,
            'CA2' => 14,
            'CATOT' => 34,
            'EXM' => 33,
        ],
        'total' => 67,
        'grade' => 'B',
        'remark' => 'Very Good',
        'pos' => null,
    ],
    [
        'subject_id' => 63,
        'cells' => [
            'CA1' => 10,
            'CA2' => 9,
            'CATOT' => 19,
            'EXM' => 35,
        ],
        'total' => 54,
        'grade' => 'C',
        'remark' => 'Good',
        'pos' => null,
    ],
    [
        'subject_id' => 64,
        'cells' => [
            'CA1' => 11,
            'CA2' => 19,
            'CATOT' => 30,
            'EXM' => 47,
        ],
        'total' => 77,
        'grade' => 'A',
        'remark' => 'Excellent',
        'pos' => null,
    ],
],
            'class_teacher' => 'Arun Prakash',
        ];
        $expectedAnnual = [
            'columns' => [
    [
        'key' => 'T1',
        'label' => 'FIRST TERM',
        'exam_id' => 1,
    ],
],
            'rows' => [
    [
        'subject_id' => 66,
        'terms' => [
            'T1' => 71,
        ],
        'annual' => 71,
        'grade' => 'A',
        'remark' => 'Excellent',
        'pos' => 137,
    ],
    [
        'subject_id' => 61,
        'terms' => [
            'T1' => 60,
        ],
        'annual' => 60,
        'grade' => 'B',
        'remark' => 'Very Good',
        'pos' => 216,
    ],
    [
        'subject_id' => 65,
        'terms' => [
            'T1' => 52,
        ],
        'annual' => 52,
        'grade' => 'C',
        'remark' => 'Good',
        'pos' => 256,
    ],
    [
        'subject_id' => 62,
        'terms' => [
            'T1' => 67,
        ],
        'annual' => 67,
        'grade' => 'B',
        'remark' => 'Very Good',
        'pos' => 170,
    ],
    [
        'subject_id' => 63,
        'terms' => [
            'T1' => 54,
        ],
        'annual' => 54,
        'grade' => 'C',
        'remark' => 'Good',
        'pos' => 252,
    ],
    [
        'subject_id' => 64,
        'terms' => [
            'T1' => 77,
        ],
        'annual' => 77,
        'grade' => 'A',
        'remark' => 'Excellent',
        'pos' => 86,
    ],
],
            'footer' => [
    'total' => 381,
    'overall' => 63.5,
    'class_ave' => 70.5,
    'pos' => 35,
],
            'class_teacher' => 'Arun Prakash',
        ];

        $sr = StudentRecord::where('user_id', 2269)->firstOrFail();
        $mcRepo = app(MyClassRepo::class);
        $subjects = $mcRepo->findSubjectByClass($sr->my_class_id);
        $exam = Exam::findOrFail(1);
        $marks = Mark::where(['student_id' => 2269, 'year' => '2026-2027'])->get();

        $card = ReportCard::build($sr, $exam, $subjects, $marks);
        $this->assertSame($expectedBuild, $this->normalizeBuild($card), 'term card must be byte-identical');

        // Annual through the Phase-1 assembly point (annualCardData
        // now filters/sorts by period) must equal the pre-change
        // buildAnnual output for the legacy pattern.
        $d = $this->annualCardData(2269, '2026-2027');
        $this->assertSame($expectedAnnual, $this->normalizeAnnual($d['card']), 'annual card must be byte-identical');
    }

    /* ---------------- T17: annual excludes unit periods ---------------- */

    public function test_t17_annual_excludes_unit_exam_totals()
    {
        $sr = StudentRecord::where('section_id', 76)->orderBy('id')->firstOrFail();

        $before = $this->annualCardData($sr->user_id, '2026-2027');
        $this->assertCount(1, $before['card']['columns']);
        $rowBefore = collect($before['card']['rows'])->firstWhere('subject.id', 79);
        $termTotal = $rowBefore['annual'];
        $this->assertNotNull($termTotal, 'fixture: the student has a Term 1 total for English');

        // A unit exam feeding Term 1, with a real total entered.
        $termPeriod = AssessmentPeriod::where('year', '2026-2027')->where('legacy_term', 1)->firstOrFail();
        $unitPeriod = AssessmentPeriod::create([
            'year' => '2026-2027', 'name' => 'T17 Unit 1', 'kind' => 'unit',
            'feeds_period_id' => $termPeriod->id, 'counts_toward_annual' => false,
            'sort_order' => 5, 'legacy_term' => null, 'is_active' => true,
        ]);
        $unitExam = Exam::create([
            'name' => 'T17 Unit Exam', 'term' => 1, 'year' => '2026-2027',
            'my_class_id' => 13, 'period_id' => $unitPeriod->id,
        ]);
        ExamSubjectComponent::create(['exam_id' => $unitExam->id, 'subject_id' => 79, 'name' => 'Test', 'short_code' => 'TST', 'max_marks' => 20, 'sort' => 1]);
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => $unitExam->id, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => 79,
        ]);
        $unitScore = $termTotal === 7 ? 9 : 7;
        $mark = Mark::where([
            'exam_id' => $unitExam->id, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => 79, 'year' => '2026-2027', 'student_id' => $sr->user_id,
        ])->firstOrFail();
        $component = MarkScheme::components($unitExam->id, 79)[0];
        MarkScheme::saveComponentScores($mark, [$component['id'] => $unitScore]);
        MarkComputation::recomputeSubject($unitExam, 13, 76, 79, '2026-2027');
        $this->assertSame($unitScore, (int) $mark->fresh()->tex1, 'the unit exam carries its own total');

        $after = $this->annualCardData($sr->user_id, '2026-2027');
        $this->assertCount(1, $after['card']['columns'], 'annual columns are exactly the term periods');
        $this->assertSame(1, $after['card']['columns'][0]['exam']->id);
        $rowAfter = collect($after['card']['rows'])->firstWhere('subject.id', 79);
        $this->assertSame($termTotal, $rowAfter['annual'], 'annual score is the mean of term totals only — the unit total never enters it');
    }

    /* ---------------- T18: student visibility ---------------- */

    public function test_t18_published_unit_exam_produces_no_student_card()
    {
        $student = User::findOrFail(2269);
        $termPeriod = AssessmentPeriod::where('year', '2026-2027')->where('legacy_term', 1)->firstOrFail();
        $unitPeriod = AssessmentPeriod::create([
            'year' => '2026-2027', 'name' => 'T18 Unit 1', 'kind' => 'unit',
            'feeds_period_id' => $termPeriod->id, 'counts_toward_annual' => false,
            'sort_order' => 5, 'legacy_term' => null, 'is_active' => true,
        ]);
        Exam::create([
            'name' => 'T18 Unit Exam', 'term' => 1, 'year' => '2026-2027',
            'my_class_id' => 0, 'period_id' => $unitPeriod->id, 'results_published' => true,
        ]);

        // Only the unit exam is published: the student gets the
        // honest not-published page, not a unit card.
        $res = $this->actingAs($student)->get(route('marks.show', [Qs::hash(2269), '2026-2027']));
        $res->assertOk();
        $res->assertViewIs('pages.support_team.marks.not_published');
        $res->assertDontSee('T18 Unit Exam');

        // Publishing the term exam shows its card — and only its card.
        Exam::where('id', 1)->update(['results_published' => true]);
        $res = $this->actingAs($student)->get(route('marks.show', [Qs::hash(2269), '2026-2027']));
        $res->assertOk();
        $res->assertSee('Term 1 Examination');
        $res->assertDontSee('T18 Unit Exam');
    }
}
