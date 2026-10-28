<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 14) — manual-assign
 * warning RENDERING. Round 6 implemented the warnings (warn-only:
 * the duty IS created) and asserted the session flash strings, but
 * never proved the rendered outcome on the duties page. This round
 * locks the rendering, including the two notes' exact composition:
 *
 * Flash composition (ExamDutyController::assign): 'Invigilator
 * saved.' + (own-subject ? " Note: this is the teacher's own
 * subject for this class." : "") + (on-leave ? " Note: this
 * teacher is on approved leave covering this date." : "").
 * Toasts render via partials/js/custom_js: flash({msg : '...',
 * type : 'success'}). Blade escapes the apostrophe in "teacher's"
 * to &#039; in the rendered toast, so the own-subject assertions
 * target the escaped form (round-10 lesson: exact toast calls,
 * escape=false; bare assertSee on generic words is vacuous).
 *
 * Fixtures: exam 1 is the global Term 1 exam; class 1 is Prep;
 * subject 1 is Prep English, whose teacher is Ananya Sharma
 * (teacher@hackstra.app, id 4) — so assigning Ananya to a
 * subject-1 sitting triggers the own-subject note, while assigning
 * Priya Nair (teacher2@teacher.com, id 12) does not. Sittings use
 * a future date (2027-02-06) so no past-sitting rule interferes,
 * and each test runs inside its own transaction.
 */
class OwnerExamDateSheetR14Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ananya()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function priya()
    {
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function sitting(array $over = [])
    {
        return ExamDate::create(array_merge([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2027-02-06',
            'start_time' => '09:00:00',
        ], $over));
    }

    protected function leave($userId)
    {
        return LeaveRequest::create([
            'user_id' => $userId,
            'start_date' => '2027-02-05',
            'end_date' => '2027-02-07',
            'reason' => 'probe',
            'leave_type' => 'normal',
            'status' => 'approved',
        ]);
    }

    protected function assign($sittingId, $teacherId)
    {
        $this->actingAs($this->admin());
        return $this->from(route('exam_duties.index'))
            ->post(route('exam_duties.assign'), [
                'exam_date_id' => $sittingId,
                'teacher_id' => $teacherId,
            ]);
    }

    protected function dutiesPage()
    {
        return $this->get(route('exam_duties.index'));
    }

    public function test_manual_assign_on_leave_teacher_renders_leave_warning()
    {
        $sitting = $this->sitting();
        $this->leave($this->priya()->id);

        $response = $this->assign($sitting->id, $this->priya()->id);
        $response->assertRedirect();
        $this->assertSame(
            'Invigilator saved. Note: this teacher is on approved leave covering this date.',
            $response->getSession()->get('flash_success')
        );
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)
            ->where('teacher_id', $this->priya()->id)->count());

        $this->dutiesPage()->assertSee(
            "flash({msg : 'Invigilator saved. Note: this teacher is on approved leave covering this date.', type : 'success'})",
            false
        );
    }

    public function test_manual_assign_own_subject_teacher_renders_subject_warning()
    {
        $sitting = $this->sitting();

        $response = $this->assign($sitting->id, $this->ananya()->id);
        $response->assertRedirect();
        $this->assertSame(
            "Invigilator saved. Note: this is the teacher's own subject for this class.",
            $response->getSession()->get('flash_success')
        );
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)
            ->where('teacher_id', $this->ananya()->id)->count());

        // Rendered toast carries Blade's &#039; for the apostrophe.
        $this->dutiesPage()->assertSee(
            "flash({msg : 'Invigilator saved. Note: this is the teacher&#039;s own subject for this class.', type : 'success'})",
            false
        );
    }

    public function test_manual_assign_plain_renders_no_warning()
    {
        $sitting = $this->sitting();

        $response = $this->assign($sitting->id, $this->priya()->id);
        $response->assertRedirect();
        $this->assertSame('Invigilator saved.', $response->getSession()->get('flash_success'));
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)
            ->where('teacher_id', $this->priya()->id)->count());

        $page = $this->dutiesPage();
        $page->assertSee("flash({msg : 'Invigilator saved.', type : 'success'})", false);
        $page->assertDontSee('Note:');
    }

    public function test_manual_assign_own_subject_teacher_on_leave_renders_both_warnings()
    {
        $sitting = $this->sitting();
        $this->leave($this->ananya()->id);

        $response = $this->assign($sitting->id, $this->ananya()->id);
        $response->assertRedirect();
        $this->assertSame(
            "Invigilator saved. Note: this is the teacher's own subject for this class. Note: this teacher is on approved leave covering this date.",
            $response->getSession()->get('flash_success')
        );
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)
            ->where('teacher_id', $this->ananya()->id)->count());

        $this->dutiesPage()->assertSee(
            "flash({msg : 'Invigilator saved. Note: this is the teacher&#039;s own subject for this class. Note: this teacher is on approved leave covering this date.', type : 'success'})",
            false
        );
    }
}
