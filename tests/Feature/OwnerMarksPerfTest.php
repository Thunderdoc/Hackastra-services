<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamRecord;
use App\Models\Mark;
use App\Models\MarkSubmission;
use App\Repositories\MarkRepo;
use App\Repositories\MyClassRepo;
use App\Support\GradeCalculator;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Marks save performance + semantics pin (hotfix 2026-11-08).
 *
 * The owner's live complaint: the entry grid's save sat on
 * "Submitting…" for seconds. Root cause: MarkComputation's
 * recomputes called the MarkRepo aggregate helpers once per mark /
 * per student — and getPos() re-ran a per-student total for EVERY
 * student in the section — so one 40-student save cost ~2,632
 * queries (~10.5 s in-process, far worse over the network to the
 * live DB). The recomputes now bulk-load and derive in memory.
 *
 * This test pins BOTH halves of the fix on the fixture unit
 * (exam 1, class 13, section 76):
 *  1. a query budget for the save (< 400; measured ~162 after the
 *     fix, 2,632 before) and for submit (< 60; measured ~13);
 *  2. byte-exact derived state — every mark's tex/grade/sub_pos
 *     and every exam record's total/ave/class_ave/pos recomputed
 *     independently through the ORIGINAL per-row MarkRepo helpers
 *     (the ground truth) and compared against what the bulk path
 *     stored. Any future "optimisation" that changes a value, a
 *     tie rule or a null quirk fails here.
 *
 * Scenario A is the configured scheme (English 79, Ananya's unit);
 * scenario B is a legacy-scheme subject (Hindi 80) saved by Admin.
 */
class OwnerMarksPerfTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function ensureRows($subjectId)
    {
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => $subjectId,
        ]);
        $this->assertGreaterThan(0, Mark::where([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => $subjectId, 'year' => '2026-2027',
        ])->count(), 'fixture: selector must create the unit mark rows');
    }

    protected function unitMarks($subjectId)
    {
        return Mark::where([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => $subjectId, 'year' => '2026-2027',
        ])->orderBy('id')->get();
    }

    /**
     * Assert every stored derived value of the unit (and of the
     * section's exam records) equals the per-row MarkRepo ground
     * truth for the given subject.
     */
    protected function assertDerivedStateMatchesGroundTruth($subjectId)
    {
        $exam = Exam::findOrFail(1);
        $markRepo = app(MarkRepo::class);
        $classType = app(MyClassRepo::class)->findTypeByClass(13);
        $tex = 'tex' . $exam->term;

        foreach ($this->unitMarks($subjectId) as $mk) {
            $expectedTotal = MarkScheme::subjectTotal($mk);
            $this->assertSame(
                $expectedTotal === null ? null : (int) $expectedTotal,
                $mk->$tex === null ? null : (int) $mk->$tex,
                'tex mismatch for mark ' . $mk->id
            );
            $expectedGrade = $expectedTotal === null ? null : $markRepo->getGrade(
                GradeCalculator::percentage($expectedTotal, MarkScheme::maxTotal(1, $subjectId)),
                $classType->id
            );
            $this->assertSame(
                $expectedGrade ? (int) $expectedGrade->id : null,
                $mk->grade_id === null ? null : (int) $mk->grade_id,
                'grade mismatch for mark ' . $mk->id
            );
            $expectedPos = $expectedTotal === null ? null
                : $markRepo->getSubPos($mk->student_id, $exam, 13, $subjectId, '2026-2027');
            $this->assertSame(
                $expectedPos === null ? null : (int) $expectedPos,
                $mk->sub_pos === null ? null : (int) $mk->sub_pos,
                'sub_pos mismatch for mark ' . $mk->id
            );
        }

        $expectedClassAve = $markRepo->getClassAvg($exam, 13, '2026-2027');
        $records = ExamRecord::where([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76, 'year' => '2026-2027',
        ])->orderBy('id')->get();
        $this->assertGreaterThan(0, $records->count(), 'fixture: exam records must exist');
        foreach ($records as $exr) {
            $this->assertEquals(
                $markRepo->getExamTotalTerm($exam, $exr->student_id, 13, '2026-2027'),
                $exr->total,
                'record total mismatch for student ' . $exr->student_id
            );
            $this->assertEquals(
                $markRepo->getExamAvgTerm($exam, $exr->student_id, 13, 76, '2026-2027'),
                $exr->ave,
                'record ave mismatch for student ' . $exr->student_id
            );
            $this->assertEquals($expectedClassAve, $exr->class_ave, 'record class_ave mismatch');
            $this->assertSame(
                (int) $markRepo->getPos($exr->student_id, $exam, 13, 76, '2026-2027'),
                (int) $exr->pos,
                'record pos mismatch for student ' . $exr->student_id
            );
        }
    }

    public function test_configured_save_is_bulk_and_matches_ground_truth()
    {
        $this->ensureRows(79);
        $marks = $this->unitMarks(79);

        // Deterministic, varied, in-range scores; every 13th student
        // is left fully blank (null-total edge: no tex, no grade,
        // no position, excluded from averages).
        $payload = [];
        foreach (MarkScheme::configuredComponents(1, 79) as $comp) {
            foreach ($marks as $mk) {
                $payload['comp_' . $mk->id . '_' . $comp->id] = $mk->id % 13 === 0
                    ? ''
                    : (int) (($mk->id * 2 + $comp->id * 3) % ($comp->max_marks + 1));
            }
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($this->teacher())
            ->put(route('marks.update', [1, 13, 76, 79]), $payload)
            ->assertOk();
        $saveQueries = $queries;
        $this->assertLessThan(400, $saveQueries,
            'marks save regressed to per-student queries (' . $saveQueries . ')');

        $this->assertDerivedStateMatchesGroundTruth(79);

        // Submit stays a light endpoint and locks the unit.
        $queries = 0;
        $this->actingAs($this->teacher())->post(route('marks.submit', [1, 13, 76, 79]))
            ->assertRedirect();
        $this->assertLessThan(60, $queries,
            'marks submit regressed (' . $queries . ' queries)');
        $this->assertSame(1, MarkSubmission::forUnit(1, 76, 79) ? 1 : 0);
        $this->actingAs($this->teacher())
            ->put(route('marks.update', [1, 13, 76, 79]), $payload)
            ->assertForbidden();
    }

    public function test_legacy_save_matches_ground_truth()
    {
        $this->ensureRows(80);
        $marks = $this->unitMarks(80);

        $payload = [];
        foreach ($marks as $mk) {
            $blank = $mk->id % 13 === 0;
            $payload['t1_' . $mk->id] = $blank ? '' : (int) ($mk->id % 21);
            $payload['t2_' . $mk->id] = $blank ? '' : (int) (($mk->id * 3) % 21);
            $payload['exm_' . $mk->id] = $blank ? '' : (int) (($mk->id * 7) % 61);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($this->admin())
            ->put(route('marks.update', [1, 13, 76, 80]), $payload)
            ->assertOk();
        $this->assertLessThan(400, $queries,
            'legacy marks save regressed to per-student queries (' . $queries . ')');

        $this->assertDerivedStateMatchesGroundTruth(80);
    }
}
