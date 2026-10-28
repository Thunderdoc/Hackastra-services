<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 5) — invigilation pool vs
 * approved leave, plus admit-card edge cases.
 *
 * Before this round, auto-assign never looked at leave: a teacher
 * on approved leave covering a sitting date could be handed that
 * duty and belled about a paper they cannot invigilate. Manual
 * assignment deliberately stays available for record-keeping
 * (same rule as past sittings).
 */
class OwnerExamDateSheetR5Test extends TestCase
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

    protected function otherTeacher()
    {
        return User::where('user_type', 'teacher')->where('id', '!=', $this->teacher()->id)->first();
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

    protected function dutyNotifications($teacherId)
    {
        return UserNotification::where('user_id', $teacherId)->where('type', 'duty_assigned')->count();
    }

    public function test_auto_assign_skips_every_teacher_on_approved_leave()
    {
        $sitting = $this->sitting();

        // Every pool teacher is on approved leave covering the date.
        foreach (Invigilation::pool() as $t) {
            $this->leave($t->id);
        }

        $this->actingAs($this->admin());
        $response = $this->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect();

        $this->assertSame(0, ExamDuty::where('exam_date_id', $sitting->id)->count());
        $this->assertSame(0, UserNotification::where('type', 'duty_assigned')->count());
        // The admin must be told WHY it could not fill — a bare
        // "every teacher already has a duty" would mislead here.
        $this->assertStringContainsString('approved leave', $response->getSession()->get('flash_danger') ?? '');
    }

    public function test_auto_assign_ignores_pending_rejected_and_non_covering_leaves()
    {
        $sitting = $this->sitting();

        $this->leave($this->teacher()->id, ['status' => 'pending']);
        $this->leave($this->otherTeacher()->id, ['status' => 'rejected']);
        $third = User::where('user_type', 'teacher')
            ->whereNotIn('id', [$this->teacher()->id, $this->otherTeacher()->id])->first();
        $this->leave($third->id, ['start_date' => '2027-02-01', 'end_date' => '2027-02-02']);

        $this->actingAs($this->admin());
        $this->post(route('exam_duties.auto'), ['exam_id' => 1])->assertRedirect();

        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)->count());
    }

    public function test_is_on_approved_leave_predicate()
    {
        $this->leave($this->teacher()->id);
        $this->leave($this->otherTeacher()->id, ['status' => 'pending']);

        $this->assertTrue(Invigilation::isOnApprovedLeave($this->teacher()->id, '2027-02-06'));
        // Inclusive both ends: first and last leave days count.
        $this->assertTrue(Invigilation::isOnApprovedLeave($this->teacher()->id, '2027-02-05'));
        $this->assertTrue(Invigilation::isOnApprovedLeave($this->teacher()->id, '2027-02-07'));
        $this->assertFalse(Invigilation::isOnApprovedLeave($this->teacher()->id, '2027-02-08'));
        $this->assertFalse(Invigilation::isOnApprovedLeave($this->otherTeacher()->id, '2027-02-06'));
    }

    public function test_my_card_with_no_date_sheet_shows_all_subjects_dateless()
    {
        // No exam_dates are created in this transaction, and no
        // exam timetable rows exist for exam 1 — the pure fallback.
        $student = User::where('email', 'student@hackstra.app')->first();

        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.my_card', [1, $student->id]));

        $response->assertStatus(200);
        foreach (['English Language', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $name) {
            $response->assertSee($name);
        }
        $response->assertSee('—', false);
    }

    public function test_bulk_print_single_streamed_student_via_student_param()
    {
        $pcb = StudentRecord::where('my_class_id', 13)
            ->whereHas('section', function ($q) {
                $q->where('stream', 'pcb');
            })->first();
        $this->assertNotNull($pcb, 'seed must hold a Class 12 PCB student');

        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, 13]) . '?student=' . $pcb->user_id);

        $response->assertStatus(200);
        // PCB tags: common (English, Hindi) + Physics/Chemistry
        // (pcm,pcb) + Biology (pcb). Mathematics is pcm-only, so a
        // PCB card correctly excludes it — the test first listed it
        // by mistake and the app proved the mapping right.
        foreach (['English Language', 'Hindi', 'Physics', 'Chemistry', 'Biology'] as $name) {
            $response->assertSee($name);
        }
        foreach (['Mathematics', 'Accountancy', 'Business Studies', 'Economics', 'History', 'Political Science', 'Geography'] as $name) {
            $response->assertDontSee($name);
        }
    }

    public function test_bulk_print_empty_class_does_not_500()
    {
        $empty = MyClass::create(['name' => 'Probe Empty Class']);

        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, $empty->id]));

        $response->assertStatus(200);
        $response->assertSee('No students found', false);
    }
}
