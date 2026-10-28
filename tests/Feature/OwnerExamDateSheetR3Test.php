<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exam timetable round 3 (2026-10-28): the generate write loop
 * runs inside ONE transaction (parent decision). Round 2 proved
 * the old behaviour with a real stray row: a generate that died
 * mid-loop left exactly one partial row behind. Now a mid-loop
 * failure rolls everything back to zero rows and the Admin gets
 * a clean refusal instead of a half-written sheet.
 *
 * The failure is forced with a test-only ExamDate::creating
 * listener that throws on the 2nd insert (Prep has 6 subjects,
 * so the 1st row would otherwise commit). No ExamDate listeners
 * exist in the app, so flushEventListeners() in finally is safe
 * and the listener can never leak into another test.
 */
class OwnerExamDateSheetR3Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function freshExam()
    {
        return Exam::create([
            'name' => 'R3 Examination',
            'term' => 'Term 1',
            'year' => '2026-2027',
            'results_published' => false,
            'my_class_id' => 0,
        ]);
    }

    protected function generatePayload($examId)
    {
        return [
            'exam_id' => $examId,
            'scope' => 'one',
            'class_id' => 1,
            'start_date' => now()->addDays(10)->format('Y-m-d'),
            'exam_days' => 6,
            'papers_per_day' => 1,
            'session1_time' => '09:00',
            'mode' => 'skip',
        ];
    }

    public function test_generate_rolls_back_all_rows_on_mid_loop_failure()
    {
        $exam = $this->freshExam();
        ExamDate::creating(function () {
            static $n = 0;
            if (++$n === 2) {
                throw new \RuntimeException('forced mid-generate failure');
            }
        });
        try {
            $response = $this->actingAs($this->admin())
                ->from(route('exam_dates.manage', ['exam_id' => $exam->id]))
                ->post(route('exam_dates.generate'), $this->generatePayload($exam->id));

            $response->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id]));
            $response->assertSessionHas('flash_danger', 'Date sheet generation failed — nothing was saved. Please try again.');
            // The 1st subject's row was written before the throw —
            // the transaction must have undone it.
            $this->assertSame(0, ExamDate::where('exam_id', $exam->id)->count());
        } finally {
            ExamDate::flushEventListeners();
        }
    }

    public function test_generate_success_still_writes_all_rows()
    {
        $exam = $this->freshExam();
        $expected = \App\Models\Subject::where('my_class_id', 1)->count();
        $this->assertGreaterThan(1, $expected);

        $response = $this->actingAs($this->admin())
            ->post(route('exam_dates.generate'), $this->generatePayload($exam->id));

        $response->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id]));
        $response->assertSessionHas('flash_success');
        $this->assertSame($expected, ExamDate::where('exam_id', $exam->id)->count());
    }

    public function test_generate_failure_leaves_no_audit_row_for_the_failed_run()
    {
        $exam = $this->freshExam();
        $before = \App\Models\AuditLog::where('action', 'exams.date_sheet_generated')->count();
        ExamDate::creating(function () {
            static $n = 0;
            if (++$n === 2) {
                throw new \RuntimeException('forced mid-generate failure');
            }
        });
        try {
            $this->actingAs($this->admin())
                ->from(route('exam_dates.manage', ['exam_id' => $exam->id]))
                ->post(route('exam_dates.generate'), $this->generatePayload($exam->id));
        } finally {
            ExamDate::flushEventListeners();
        }
        $this->assertSame($before, \App\Models\AuditLog::where('action', 'exams.date_sheet_generated')->count());
    }
}
