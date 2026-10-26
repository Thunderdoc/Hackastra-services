<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 duties-visibility batch:
 * duties were real rows but invisible to teachers. Now a manual
 * or auto assignment rings the teacher's bell, My Duties lists
 * only the teacher's own duties, and the home panel shows them.
 */
class OwnerWave20Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function otherTeacher()
    {
        return User::where('user_type', 'teacher')->where('id', '!=', $this->teacher()->id)->first();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
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

    protected function dutyNotifications($teacherId)
    {
        return UserNotification::where('user_id', $teacherId)->where('type', 'duty_assigned')->count();
    }

    public function test_manual_assign_rings_teacher_bell()
    {
        $sitting = $this->sitting();
        $this->actingAs($this->admin());
        $this->post(route('exam_duties.assign'), [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $this->teacher()->id,
        ])->assertRedirect();

        $this->assertSame(1, $this->dutyNotifications($this->teacher()->id));
        $note = UserNotification::where('user_id', $this->teacher()->id)->where('type', 'duty_assigned')->first();
        $this->assertSame(route('exam_duties.my'), $note->link);
        $this->assertStringContainsString('2027-02-06', $note->body);
    }

    public function test_reassign_same_teacher_rings_no_second_bell()
    {
        $sitting = $this->sitting();
        $this->actingAs($this->admin());
        $this->post(route('exam_duties.assign'), ['exam_date_id' => $sitting->id, 'teacher_id' => $this->teacher()->id]);
        $this->post(route('exam_duties.assign'), ['exam_date_id' => $sitting->id, 'teacher_id' => $this->teacher()->id]);
        $this->assertSame(1, $this->dutyNotifications($this->teacher()->id));
    }

    public function test_auto_assign_rings_bells()
    {
        $this->sitting();
        $this->sitting(['my_class_id' => 2, 'subject_id' => 7, 'exam_date' => '2027-02-08']);
        $this->actingAs($this->admin());
        $this->post(route('exam_duties.auto'), ['exam_id' => 1])->assertRedirect();

        $this->assertSame(2, ExamDuty::count());
        $this->assertSame(2, UserNotification::where('type', 'duty_assigned')->count());
    }

    public function test_my_duties_shows_only_own_duties()
    {
        $mine = $this->sitting();
        $theirs = $this->sitting(['my_class_id' => 2, 'subject_id' => 7, 'exam_date' => '2027-03-06']);
        ExamDuty::create(['exam_date_id' => $mine->id, 'teacher_id' => $this->teacher()->id, 'assigned_by' => $this->admin()->id]);
        ExamDuty::create(['exam_date_id' => $theirs->id, 'teacher_id' => $this->otherTeacher()->id, 'assigned_by' => $this->admin()->id]);

        $this->actingAs($this->teacher());
        $this->get(route('exam_duties.my'))->assertOk()
            ->assertSee('2027-02-06', false)
            ->assertDontSee('2027-03-06', false);
    }

    public function test_my_duties_refuses_non_teachers()
    {
        $student = User::where('email', 'student@hackstra.app')->first();
        $this->actingAs($student);
        $this->get(route('exam_duties.my'))->assertRedirect(route('dashboard'));
    }

    public function test_home_panel_shows_upcoming_duties()
    {
        $sitting = $this->sitting();
        ExamDuty::create(['exam_date_id' => $sitting->id, 'teacher_id' => $this->teacher()->id, 'assigned_by' => $this->admin()->id]);

        $this->actingAs($this->teacher());
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('My Invigilation Duties', false)
            ->assertSee('2027-02-06', false);
    }
}
