<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-28: (1) teachers never saw My Leave — the
 * only menu item lived in the non-teacher branch; (2) students
 * apply for leave too, decided by their SECTION teacher (admin
 * keeps oversight). Tests cover apply/approve/deny paths.
 */
class OwnerStudentLeaveTest extends TestCase
{
    use DatabaseTransactions;

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function otherTeacher()
    {
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function makeStudentLeave()
    {
        return LeaveRequest::create([
            'user_id' => $this->student()->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Student leave probe',
            'status' => 'pending',
        ]);
    }

    public function test_student_can_apply_for_leave()
    {
        $response = $this->actingAs($this->student())->post(route('leave.store'), [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Student apply probe',
        ]);
        $response->assertRedirect();
        $this->assertTrue(LeaveRequest::where('user_id', $this->student()->id)
            ->where('reason', 'Student apply probe')->exists());
    }

    public function test_student_sees_only_own_requests()
    {
        $own = $this->makeStudentLeave();
        $other = LeaveRequest::create([
            'user_id' => $this->librarian()->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Staff leave probe',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->student())->get(route('leave.my'));
        $response->assertOk();
        $response->assertSee('Student leave probe');
        $response->assertDontSee('Staff leave probe');
    }

    public function test_section_teacher_can_approve_student_leave()
    {
        // student@ = Prep A (section 1), whose teacher is teacher@.
        $leave = $this->makeStudentLeave();

        $response = $this->actingAs($this->teacher())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ]);
        $response->assertRedirect();
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame($this->teacher()->id, $leave->fresh()->decided_by);
    }

    public function test_non_section_teacher_cannot_decide_student_leave()
    {
        $leave = $this->makeStudentLeave();

        $response = $this->actingAs($this->otherTeacher())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ]);
        $response->assertRedirect(route('dashboard'));
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_teacher_cannot_decide_staff_leave()
    {
        $leave = LeaveRequest::create([
            'user_id' => $this->librarian()->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Staff leave probe 2',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->teacher())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ]);
        $response->assertRedirect(route('dashboard'));
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_admin_can_still_decide_student_leave()
    {
        $leave = $this->makeStudentLeave();

        $response = $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'rejected',
            'decision_note' => 'Admin oversight probe',
        ]);
        $response->assertRedirect();
        $this->assertSame('rejected', $leave->fresh()->status);
    }

    public function test_parent_cannot_apply_for_leave()
    {
        $response = $this->actingAs($this->parent())->post(route('leave.store'), [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Parent probe',
        ]);
        $response->assertRedirect(route('dashboard'));
        $this->assertFalse(LeaveRequest::where('reason', 'Parent probe')->exists());
    }

    public function test_teacher_queue_lists_only_own_sections_student_leaves()
    {
        $leave = $this->makeStudentLeave();

        $response = $this->actingAs($this->teacher())->get(route('leave.student_requests'));
        $response->assertOk();
        $response->assertSee('Student leave probe');

        $response = $this->actingAs($this->otherTeacher())->get(route('leave.student_requests'));
        $response->assertOk();
        $response->assertDontSee('Student leave probe');
    }

    public function test_menus_show_leave_items()
    {
        $teacherPage = $this->actingAs($this->teacher())->get('/dashboard');
        $teacherPage->assertOk();
        $teacherPage->assertSee('My Leave');
        $teacherPage->assertSee('Student Leave Requests');

        $studentPage = $this->actingAs($this->student())->get('/dashboard');
        $studentPage->assertOk();
        $studentPage->assertSee('My Leave');
    }
}
