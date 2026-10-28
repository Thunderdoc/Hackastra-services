<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Repositories\MyClassRepo;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exam timetable round 4 (2026-10-28): the Manage-page save()
 * upsert loop runs inside ONE transaction (parent decision,
 * same reasoning as the generate loop in round 3). A mid-save
 * failure used to leave a partially saved single-class sheet
 * behind. Now it rolls back to the pre-save state and the Admin
 * gets a clean refusal instead.
 *
 * The failure is forced with a test-only ExamDate::creating
 * listener that throws on the 2nd insert. No ExamDate listeners
 * exist in the app, so flushEventListeners() in finally is safe
 * and the listener can never leak into another test.
 *
 * Dates are always future (save refuses NEW past dates) and
 * distinct per subject (save refuses two papers sharing one
 * (date, session)), so the only failure in the rollback tests
 * is the forced throw.
 */
class OwnerExamDateSheetR4Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function freshExam()
    {
        return Exam::create([
            'name' => 'R4 Examination',
            'term' => 'Term 1',
            'year' => '2026-2027',
            'results_published' => false,
            'my_class_id' => 0,
        ]);
    }

    /** Prep subjects in save() iteration order (name order). */
    protected function prepSubjects()
    {
        return (new MyClassRepo)->findSubjectByClass(1);
    }

    /** One distinct future date per subject, 09:00 sessions. */
    protected function savePayload($examId, array $overrides = [])
    {
        $dates = [];
        $i = 0;
        foreach ($this->prepSubjects() as $subject) {
            $dates[$subject->id] = $overrides[$subject->id] ?? [
                'exam_date' => now()->addDays(10 + $i)->format('Y-m-d'),
                'start_time' => '09:00',
                'note' => '',
            ];
            $i++;
        }
        return [
            'exam_id' => $examId,
            'class_id' => 1,
            'dates' => $dates,
        ];
    }

    public function test_save_rolls_back_all_inserts_on_mid_save_failure()
    {
        $exam = $this->freshExam();
        ExamDate::creating(function () {
            static $n = 0;
            if (++$n === 2) {
                throw new \RuntimeException('forced mid-save failure');
            }
        });
        try {
            $response = $this->actingAs($this->admin())
                ->from(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => 1]))
                ->post(route('exam_dates.save'), $this->savePayload($exam->id));

            $response->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => 1]));
            $response->assertSessionHas('flash_danger', 'Date sheet save failed — nothing was saved. Please try again.');
            // The 1st subject's row was written before the throw —
            // the transaction must have undone it.
            $this->assertSame(0, ExamDate::where('exam_id', $exam->id)->count());
        } finally {
            ExamDate::flushEventListeners();
        }
    }

    public function test_save_rolls_back_deletes_on_mid_save_failure()
    {
        $exam = $this->freshExam();
        $subjects = $this->prepSubjects();
        $first = $subjects->first();
        // A pre-existing row for the FIRST iterated subject; the
        // payload empties its date (the delete action), so the
        // delete executes before any create fires.
        ExamDate::create([
            'exam_id' => $exam->id,
            'my_class_id' => 1,
            'subject_id' => $first->id,
            'exam_date' => now()->addDays(30)->format('Y-m-d'),
            'start_time' => '09:00',
            'note' => null,
        ]);
        ExamDate::creating(function () {
            static $n = 0;
            if (++$n === 2) {
                throw new \RuntimeException('forced mid-save failure');
            }
        });
        try {
            $payload = $this->savePayload($exam->id, [
                $first->id => ['exam_date' => '', 'start_time' => '', 'note' => ''],
            ]);
            $this->actingAs($this->admin())
                ->from(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => 1]))
                ->post(route('exam_dates.save'), $payload);
        } finally {
            ExamDate::flushEventListeners();
        }
        // The delete rolled back: the pre-existing row survives,
        // and no new row was committed either.
        $this->assertSame(1, ExamDate::where('exam_id', $exam->id)->count());
        $this->assertNotNull(ExamDate::where('exam_id', $exam->id)->where('subject_id', $first->id)->first());
    }

    public function test_save_success_still_writes_all_rows()
    {
        $exam = $this->freshExam();
        $expected = $this->prepSubjects()->count();
        $this->assertGreaterThan(1, $expected);

        $response = $this->actingAs($this->admin())
            ->post(route('exam_dates.save'), $this->savePayload($exam->id));

        $response->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => 1]));
        $response->assertSessionHas('flash_success');
        $this->assertSame($expected, ExamDate::where('exam_id', $exam->id)->count());
    }

    public function test_save_failure_leaves_no_audit_row_for_the_failed_run()
    {
        $exam = $this->freshExam();
        $before = \App\Models\AuditLog::where('action', 'exams.date_sheet_saved')->count();
        ExamDate::creating(function () {
            static $n = 0;
            if (++$n === 2) {
                throw new \RuntimeException('forced mid-save failure');
            }
        });
        try {
            $this->actingAs($this->admin())
                ->from(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => 1]))
                ->post(route('exam_dates.save'), $this->savePayload($exam->id));
        } finally {
            ExamDate::flushEventListeners();
        }
        $this->assertSame($before, \App\Models\AuditLog::where('action', 'exams.date_sheet_saved')->count());
    }
}
