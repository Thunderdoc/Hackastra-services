<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\StaffAttendance;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 13 (HR-lite,
 * CASE_STUDY gap 12): the Admin marks a staff attendance register
 * that locks once marked, staff apply for leave, the Admin
 * decides — never their own request — and the teacher home shows
 * the teacher's own requests.
 */
class OwnerWave13Test extends TestCase
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

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->first();
    }

    public function test_admin_marks_staff_attendance_and_day_locks()
    {
        $this->actingAs($this->admin());
        $t = $this->teacher();
        $this->post(route('staff_attendance.store'), [
            'date' => '2026-10-26',
            'status' => [$t->id => 'present', $this->accountant()->id => 'late'],
        ])->assertRedirect();
        $this->assertSame(2, StaffAttendance::where('date', '2026-10-26')->count());
        $this->assertSame('late', StaffAttendance::where('date', '2026-10-26')
            ->where('user_id', $this->accountant()->id)->first()->status);

        // Re-marking the same day is refused — count unchanged.
        $this->post(route('staff_attendance.store'), [
            'date' => '2026-10-26',
            'status' => [$t->id => 'absent'],
        ])->assertRedirect();
        $this->assertSame(2, StaffAttendance::where('date', '2026-10-26')->count());
        $this->assertSame('present', StaffAttendance::where('date', '2026-10-26')
            ->where('user_id', $t->id)->first()->status);

        $this->get(route('staff_attendance.index', ['date' => '2026-10-26']))->assertOk()
            ->assertSee('already marked');
    }

    public function test_teacher_cannot_mark_staff_attendance()
    {
        $this->actingAs($this->teacher());
        $this->get(route('staff_attendance.index'))->assertRedirect();
    }

    public function test_leave_apply_approve_and_self_decision_refused()
    {
        $this->actingAs($this->teacher());
        $this->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-04',
            'reason' => 'Family function',
        ])->assertRedirect();
        $lr = LeaveRequest::where('user_id', $this->teacher()->id)->first();
        $this->assertNotNull($lr);
        $this->assertSame('pending', $lr->status);
        $this->assertSame(3, $lr->days);

        $this->actingAs($this->admin());
        $this->post(route('leave.decide', $lr->id), [
            'status' => 'approved', 'decision_note' => 'Enjoy.',
        ])->assertRedirect();
        $this->assertSame('approved', $lr->fresh()->status);
        $this->assertSame($this->admin()->id, (int) $lr->fresh()->decided_by);

        // A decided request cannot be decided again.
        $this->post(route('leave.decide', $lr->id), ['status' => 'rejected'])
            ->assertRedirect();
        $this->assertSame('approved', $lr->fresh()->status);
    }

    public function test_admin_cannot_decide_own_leave_request()
    {
        $lr = LeaveRequest::create([
            'user_id' => $this->admin()->id,
            'start_date' => '2026-11-09', 'end_date' => '2026-11-09',
            'reason' => 'Admin personal day', 'status' => 'pending',
        ]);
        $this->actingAs($this->admin());
        $this->post(route('leave.decide', $lr->id), ['status' => 'approved'])
            ->assertRedirect();
        $this->assertSame('pending', $lr->fresh()->status);
    }

    public function test_leave_validation_rejects_inverted_dates()
    {
        $this->actingAs($this->teacher());
        $this->post(route('leave.store'), [
            'start_date' => '2026-11-05',
            'end_date' => '2026-11-02',
            'reason' => 'Backwards',
        ])->assertSessionHasErrors(['end_date']);
        $this->assertSame(0, LeaveRequest::where('reason', 'Backwards')->count());
    }

    public function test_my_leave_shows_only_own_requests_and_home_lists_them()
    {
        LeaveRequest::create([
            'user_id' => $this->teacher()->id,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
            'reason' => 'Teacher winter break', 'status' => 'pending',
        ]);
        LeaveRequest::create([
            'user_id' => $this->accountant()->id,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-01',
            'reason' => 'Accountant personal', 'status' => 'pending',
        ]);

        $this->actingAs($this->teacher());
        $this->get(route('leave.my'))->assertOk()
            ->assertSee('Teacher winter break')
            ->assertDontSee('Accountant personal');
        $this->get(route('home'))->assertOk()
            ->assertSee('My Leave Requests')
            ->assertSee('Teacher winter break');
    }

    public function test_accountant_can_apply_for_leave()
    {
        $this->actingAs($this->accountant());
        $this->get(route('leave.my'))->assertOk();
        $this->post(route('leave.store'), [
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-16',
            'reason' => 'Accountant day off',
        ])->assertRedirect();
        $this->assertNotNull(LeaveRequest::where('reason', 'Accountant day off')->first());
    }
}
