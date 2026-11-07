<?php

namespace Tests\Feature;

use App\Models\AssessmentPeriod;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamSubjectComponent;
use App\Models\Mark;
use App\Models\MarkSubmission;
use App\Models\StudentRecord;
use App\Support\InternalMarksImporter;
use App\Support\MarkComputation;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exam Patterns Phase 1 (R1 §4) — T12–T15: Internal marks from
 * unit tests. Fixture built per test: a Term period (legacy 2)
 * with Internal max 40 for English (79), fed by Unit Test 1
 * (max 20, period sort 10) and Unit Test 2 (max 25, sort 20),
 * Class 12 section 76. Student A scores 16/20 and 15/25 —
 * 80% and 60%: all ⇒ 70% ⇒ 28, best-1 ⇒ 80% ⇒ 32,
 * latest-1 (by period sort) ⇒ 60% ⇒ 24.
 */
class OwnerExamPatternInternalTest extends TestCase
{
    use DatabaseTransactions;

    protected $target;
    protected $ut1;
    protected $ut2;
    protected $internal;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function buildPattern(): void
    {
        $year = '2026-2027';
        $term = AssessmentPeriod::create([
            'year' => $year, 'name' => 'T12 Term', 'kind' => 'term', 'feeds_period_id' => null,
            'counts_toward_annual' => true, 'sort_order' => 40, 'legacy_term' => 2, 'is_active' => true,
        ]);
        $ut1p = AssessmentPeriod::create([
            'year' => $year, 'name' => 'T12 Unit 1', 'kind' => 'unit', 'feeds_period_id' => $term->id,
            'counts_toward_annual' => false, 'sort_order' => 10, 'legacy_term' => null, 'is_active' => true,
        ]);
        $ut2p = AssessmentPeriod::create([
            'year' => $year, 'name' => 'T12 Unit 2', 'kind' => 'unit', 'feeds_period_id' => $term->id,
            'counts_toward_annual' => false, 'sort_order' => 20, 'legacy_term' => null, 'is_active' => true,
        ]);
        $mkExam = function ($name, $period) {
            return Exam::create([
                'name' => $name, 'term' => 2, 'year' => '2026-2027',
                'my_class_id' => 13, 'period_id' => $period->id,
            ]);
        };
        $this->target = $mkExam('T12 Term Exam', $term);
        $this->ut1 = $mkExam('T12 Unit Exam 1', $ut1p);
        $this->ut2 = $mkExam('T12 Unit Exam 2', $ut2p);

        ExamSubjectComponent::create(['exam_id' => $this->target->id, 'subject_id' => 79, 'name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 60, 'sort' => 1]);
        $this->internal = ExamSubjectComponent::create(['exam_id' => $this->target->id, 'subject_id' => 79, 'name' => 'Internal Assessment', 'short_code' => 'INT', 'max_marks' => 40, 'sort' => 2]);
        ExamSubjectComponent::create(['exam_id' => $this->ut1->id, 'subject_id' => 79, 'name' => 'Test', 'short_code' => 'TST', 'max_marks' => 20, 'sort' => 1]);
        ExamSubjectComponent::create(['exam_id' => $this->ut2->id, 'subject_id' => 79, 'name' => 'Test', 'short_code' => 'TST', 'max_marks' => 25, 'sort' => 1]);

        foreach ([$this->target, $this->ut1, $this->ut2] as $exam) {
            $this->actingAs($this->admin())->post(route('marks.selector'), [
                'exam_id' => $exam->id, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => 79,
            ]);
        }
    }

    protected function students()
    {
        return StudentRecord::where('section_id', 76)->orderBy('id')->take(3)->get();
    }

    protected function setUnitScore($exam, $userId, $score): void
    {
        $mark = Mark::where([
            'exam_id' => $exam->id, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => 79, 'year' => '2026-2027', 'student_id' => $userId,
        ])->firstOrFail();
        $component = MarkScheme::components($exam->id, 79)[0];
        MarkScheme::saveComponentScores($mark, [$component['id'] => $score]);
    }

    protected function submitUnit($exam): void
    {
        MarkSubmission::create([
            'exam_id' => $exam->id, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => 79, 'submitted_by' => $this->teacher()->id,
        ]);
    }

    protected function internalScore($userId)
    {
        $mark = Mark::where([
            'exam_id' => $this->target->id, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => 79, 'year' => '2026-2027', 'student_id' => $userId,
        ])->first();
        $component = InternalMarksImporter::internalComponent($this->target->id, 79);
        return $mark ? MarkScheme::scoreFor($mark, $component) : null;
    }

    protected function importUnits($payload)
    {
        return $this->actingAs($this->teacher())->post(
            route('marks.import_internal', [$this->target->id, 13, 76, 79]),
            $payload
        );
    }

    /* ---------------- T12: the maths pin ---------------- */

    public function test_t12_unit_import_maths()
    {
        $this->buildPattern();
        [$sA, $sB, $sC] = $this->students()->all();

        $this->setUnitScore($this->ut1, $sA->user_id, 16); // 80%
        $this->setUnitScore($this->ut2, $sA->user_id, 15); // 60%
        // sB: a mark row with NOTHING entered (NULL total) — no entry.
        // sC: no unit scores at all.
        $this->submitUnit($this->ut1);
        $this->submitUnit($this->ut2);

        $ids = ['source_kind' => 'units', 'unit_exam_ids' => [$this->ut1->id, $this->ut2->id]];

        $this->importUnits($ids + ['mode' => 'all'])->assertRedirect();
        $this->assertSame(28, $this->internalScore($sA->user_id), 'all: (80+60)/2 = 70% of 40');
        $this->assertNull($this->internalScore($sB->user_id), 'NULL unit total contributes no entry');
        $this->assertNull($this->internalScore($sC->user_id), 'no unit marks — Internal stays blank');

        $this->importUnits($ids + ['mode' => 'best', 'avg_n' => 1])->assertRedirect();
        $this->assertSame(32, $this->internalScore($sA->user_id), 'best-1: 80% of 40');

        $this->importUnits($ids + ['mode' => 'latest', 'avg_n' => 1])->assertRedirect();
        $this->assertSame(24, $this->internalScore($sA->user_id), 'latest-1 by period sort: 60% of 40');
    }

    /* ---------------- T13: submitted-source eligibility ---------------- */

    public function test_t13_only_submitted_sources_count()
    {
        $this->buildPattern();
        [$sA] = $this->students()->all();
        $this->setUnitScore($this->ut1, $sA->user_id, 16);
        $this->setUnitScore($this->ut2, $sA->user_id, 15);
        // Neither unit submitted: the fill is refused with the reason.
        $this->importUnits(['source_kind' => 'units', 'unit_exam_ids' => [$this->ut1->id, $this->ut2->id], 'mode' => 'all'])
            ->assertSessionHas('pop_error');
        $this->assertNull($this->internalScore($sA->user_id));

        // Panel lists the unsubmitted source as excluded.
        $page = $this->actingAs($this->teacher())->get(route('marks.manage', [$this->target->id, 13, 76, 79]));
        $page->assertOk();
        $page->assertSee('Unit Tests (feeds this term)');
        $page->assertSee('Not submitted — will be excluded');

        // Submit only UT1: importing both uses UT1 alone (80% ⇒ 32).
        $this->submitUnit($this->ut1);
        $this->importUnits(['source_kind' => 'units', 'unit_exam_ids' => [$this->ut1->id, $this->ut2->id], 'mode' => 'all'])
            ->assertRedirect();
        $this->assertSame(32, $this->internalScore($sA->user_id), 'unsubmitted UT2 excluded from the average');
    }

    /* ---------------- T14: gates ---------------- */

    public function test_t14_import_gates()
    {
        $this->buildPattern();
        [$sA] = $this->students()->all();
        $this->setUnitScore($this->ut1, $sA->user_id, 16);
        $this->submitUnit($this->ut1);
        $payload = ['source_kind' => 'units', 'unit_exam_ids' => [$this->ut1->id], 'mode' => 'all'];

        // Non-effective teacher: 403.
        $other = User::where('email', 'teacher1@teacher.com')->firstOrFail();
        $this->actingAs($other)->post(route('marks.import_internal', [$this->target->id, 13, 76, 79]), $payload)
            ->assertForbidden();

        // Submitted target unit: the lifecycle guard refuses.
        $this->actingAs($this->teacher())->post(route('marks.submit', [$this->target->id, 13, 76, 79]))
            ->assertRedirect();
        $this->importUnits($payload)->assertForbidden();
        $this->assertNull($this->internalScore($sA->user_id));
    }

    public function test_t14_import_refused_before_exam_date_gate_opens()
    {
        $this->buildPattern();
        [$sA] = $this->students()->all();
        $this->setUnitScore($this->ut1, $sA->user_id, 16);
        $this->submitUnit($this->ut1);
        ExamDate::create([
            'exam_id' => $this->target->id, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => '2027-07-16',
        ]);
        $this->importUnits(['source_kind' => 'units', 'unit_exam_ids' => [$this->ut1->id], 'mode' => 'all'])
            ->assertForbidden();
    }

    public function test_t14_legacy_scheme_target_shows_no_unit_source()
    {
        // Exam 1 + Mathematics (81): no configured scheme on exam 1
        // for this class-13 subject, so there is no Internal
        // component and the importer card never renders.
        $page = $this->actingAs($this->admin())->get(route('marks.manage', [1, 13, 76, 81]));
        $page->assertOk();
        $page->assertDontSee('Unit Tests (feeds this term)');
    }

    /* ---------------- T15: recompute equivalence ---------------- */

    public function test_t15_import_recompute_matches_hand_entered()
    {
        $this->buildPattern();
        [$sA] = $this->students()->all();
        $this->setUnitScore($this->ut1, $sA->user_id, 16);
        $this->setUnitScore($this->ut2, $sA->user_id, 15);
        $this->submitUnit($this->ut1);
        $this->submitUnit($this->ut2);

        // Theory by hand for both paths: 50 of 60.
        $mark = Mark::where([
            'exam_id' => $this->target->id, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => 79, 'year' => '2026-2027', 'student_id' => $sA->user_id,
        ])->firstOrFail();
        $theory = MarkScheme::components($this->target->id, 79)[0];
        MarkScheme::saveComponentScores($mark, [$theory['id'] => 50]);

        $this->importUnits(['source_kind' => 'units', 'unit_exam_ids' => [$this->ut1->id, $this->ut2->id], 'mode' => 'all'])
            ->assertRedirect();
        $this->assertSame(28, $this->internalScore($sA->user_id));

        $mark->refresh();
        $viaImport = [
            'tex2' => $mark->tex2, 'grade_id' => $mark->grade_id, 'sub_pos' => $mark->sub_pos,
        ];
        $record = \App\Models\ExamRecord::where([
            'exam_id' => $this->target->id, 'student_id' => $sA->user_id, 'year' => '2026-2027',
        ])->firstOrFail();
        $recordViaImport = ['total' => $record->total, 'ave' => $record->ave, 'pos' => $record->pos];
        $this->assertSame(78, (int) $viaImport['tex2'], 'Theory 50 + Internal 28');

        // Hand-entered path: wipe the Internal cell, type 28 in
        // directly, run the same recompute a typed save runs.
        \App\Models\MarkComponent::where('mark_id', $mark->id)
            ->where('component_id', $this->internal->id)->delete();
        MarkScheme::saveComponentScores($mark, [$this->internal->id => 28]);
        MarkComputation::recomputeSubject($this->target, 13, 76, 79, '2026-2027');
        MarkComputation::recomputeRecords($this->target, 13, 76, '2026-2027');

        $mark->refresh();
        $this->assertSame($viaImport, [
            'tex2' => $mark->tex2, 'grade_id' => $mark->grade_id, 'sub_pos' => $mark->sub_pos,
        ], 'imported Internal recomputes exactly like a hand-entered one');
        $record->refresh();
        $this->assertSame($recordViaImport, ['total' => $record->total, 'ave' => $record->ave, 'pos' => $record->pos]);
    }
}
