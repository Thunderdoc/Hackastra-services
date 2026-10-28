<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 6) — leave warnings that
 * inform without blocking.
 *
 * Round 5 made auto-assign skip approved-leave teachers, but two
 * silences remained: a manual assignment of an on-leave teacher
 * gave no signal at all, and a sitting filled by someone else
 * while other teachers were on leave said nothing about leave.
 * Both are fixed as warnings only — manual assignment stays
 * allowed for deliberate record-keeping, and auto-assign still
 * fills whenever any teacher is available.
 */
class OwnerExamDateSheetR6Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
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

    protected function leave($userId, array $over = [])
    {
        return LeaveRequest::create(array_merge([
            'user_id' => $userId,
            'start_date' => '2027-02-05',
            'end_date' => '2027-02-07',
            'reason' => 'probe',
            'leave_type' => 'normal',
            'status' => 'approved',
        ], $over));
    }

    public function test_manual_assign_of_on_leave_teacher_warns_but_still_saves()
    {
        $sitting = $this->sitting();
        $this->leave($this->teacher()->id);

        $this->actingAs($this->admin());
        $response = $this->post(route('exam_duties.assign'), [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $this->teacher()->id,
        ]);
        $response->assertRedirect();

        // Warned, not refused: the duty row exists.
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)
            ->where('teacher_id', $this->teacher()->id)->count());
        $this->assertStringContainsString('approved leave',
            $response->getSession()->get('flash_success') ?? '');
    }

    public function test_manual_assign_without_leave_carries_no_leave_warning()
    {
        $sitting = $this->sitting();

        $this->actingAs($this->admin());
        $response = $this->post(route('exam_duties.assign'), [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $this->teacher()->id,
        ]);
        $response->assertRedirect();

        $this->assertStringNotContainsString('approved leave',
            $response->getSession()->get('flash_success') ?? '');
    }

    public function test_auto_assign_flash_names_leave_when_filled_despite_leave()
    {
        $sitting = $this->sitting();
        // One pool teacher on leave; the rest are free, so the
        // sitting still fills — by someone else.
        $this->leave($this->teacher()->id);

        $this->actingAs($this->admin());
        $response = $this->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect();

        $duty = ExamDuty::where('exam_date_id', $sitting->id)->first();
        $this->assertNotNull($duty, 'sitting must still fill');
        $this->assertNotSame($this->teacher()->id, (int) $duty->teacher_id,
            'the on-leave teacher must not hold the duty');
        $this->assertStringContainsString('filled while other teachers were on approved leave',
            $response->getSession()->get('flash_success') ?? '');
    }

    public function test_auto_assign_result_counts_filled_despite_leave()
    {
        $this->sitting();
        $this->leave($this->teacher()->id);

        $result = Invigilation::autoAssign(1, $this->admin()->id);

        $this->assertSame(1, $result['assigned']);
        $this->assertSame(1, $result['filled_despite_leave']);
    }

    public function test_auto_assign_flash_silent_about_leave_when_nobody_on_leave()
    {
        $this->sitting();

        $this->actingAs($this->admin());
        $response = $this->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect();

        $this->assertStringNotContainsString('approved leave',
            $response->getSession()->get('flash_success') ?? '');
    }
}
