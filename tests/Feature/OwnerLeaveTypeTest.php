<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-28: Normal vs Emergency at apply time.
 * Emergency is auto-approved within seconds (synchronously at
 * submit) and the would-be approver is informed by bell; normal
 * goes through the usual approver.
 */
class OwnerLeaveTypeTest extends TestCase
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

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    public function test_student_emergency_leave_is_auto_approved_at_submit()
    {
        $response = $this->actingAs($this->student())->post(route('leave.store'), [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Emergency probe',
            'leave_type' => 'emergency',
        ]);
        $response->assertRedirect();
        $row = LeaveRequest::where('reason', 'Emergency probe')->firstOrFail();
        $this->assertSame('emergency', $row->leave_type);
        $this->assertSame('approved', $row->status);
        $this->assertNotNull($row->decided_at);
        $this->assertNull($row->decided_by);
        $this->assertStringContainsString('Auto-approved', $row->decision_note);
        // The section teacher is informed, not asked.
        $this->assertTrue(UserNotification::where('user_id', $this->teacher()->id)
            ->where('type', 'leave_emergency')->exists());
    }

    public function test_staff_emergency_leave_is_auto_approved_and_admin_informed()
    {
        $this->actingAs($this->librarian())->post(route('leave.store'), [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Staff emergency probe',
            'leave_type' => 'emergency',
        ])->assertRedirect();
        $row = LeaveRequest::where('reason', 'Staff emergency probe')->firstOrFail();
        $this->assertSame('approved', $row->status);
        $this->assertTrue(UserNotification::where('user_id', $this->admin()->id)
            ->where('type', 'leave_emergency')->exists());
    }

    public function test_invalid_leave_type_is_refused()
    {
        $response = $this->actingAs($this->student())->post(route('leave.store'), [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Bad type probe',
            'leave_type' => 'urgent',
        ]);
        $response->assertSessionHasErrors('leave_type');
        $this->assertFalse(LeaveRequest::where('reason', 'Bad type probe')->exists());
    }

    public function test_teacher_queue_shows_emergency_approved_and_normal_pending()
    {
        LeaveRequest::create([
            'user_id' => $this->student()->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'Normal queue probe',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        LeaveRequest::create([
            'user_id' => $this->student()->id,
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-12',
            'reason' => 'Emergency queue probe',
            'leave_type' => 'emergency',
            'status' => 'approved',
            'decided_at' => now(),
            'decision_note' => 'Auto-approved (emergency).',
        ]);

        $html = $this->actingAs($this->teacher())->get(route('leave.student_requests'))->assertOk()->getContent();
        $this->assertStringContainsString('Emergency queue probe', $html);
        $this->assertStringContainsString('Normal queue probe', $html);
        // The pending normal row still carries a decide button.
        $this->assertStringContainsString('>Approve<', $html);
    }
}
