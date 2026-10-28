<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 19: the accountant is the one staff role whose
 * leave surface was never probed. Expected shape (gates read from
 * routes/web.php + LeaveRequestController before writing any test):
 * - leave.index (Admin queue) is teamOrPrincipal:SAWrite-gated, so
 *   the accountant is refused (302 to login via middleware).
 * - leave.my / leave.store are applicant-gated and 'accountant' is
 *   in APPLICANTS, so the accountant applies + views own requests,
 *   and emergency auto-approves with bells to admins + principals.
 * - leave.decide refuses everyone who is not admin, section-teacher
 *   (student leaves only), or principal — so the accountant cannot
 *   decide anyone's leave, including their own.
 */
class OwnerAccountantLeaveRound19Test extends TestCase
{
    use DatabaseTransactions;

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_accountant_leave_index_queue_is_closed()
    {
        $this->actingAs($this->accountant())
            ->get(route('leave.index'))
            ->assertRedirect(route('login'));
    }

    public function test_accountant_leave_student_requests_is_closed()
    {
        $this->actingAs($this->accountant())
            ->get(route('leave.student_requests'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_own_leave_page_opens()
    {
        $this->actingAs($this->accountant())
            ->get(route('leave.my'))
            ->assertOk();
    }

    public function test_accountant_normal_leave_applies_and_lists()
    {
        $this->actingAs($this->accountant())->post(route('leave.store'), [
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-17',
            'reason' => 'Accountant normal probe',
            'leave_type' => 'normal',
        ])->assertRedirect();

        $row = LeaveRequest::where('reason', 'Accountant normal probe')->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame('normal', $row->leave_type);
        $this->assertSame($this->accountant()->id, $row->user_id);

        $this->actingAs($this->accountant())
            ->get(route('leave.my'))
            ->assertOk()
            ->assertSee('Accountant normal probe');
    }

    public function test_accountant_emergency_leave_auto_approves_and_bells_deciders()
    {
        $this->actingAs($this->accountant())->post(route('leave.store'), [
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-18',
            'reason' => 'Accountant emergency probe',
            'leave_type' => 'emergency',
        ])->assertRedirect();

        $row = LeaveRequest::where('reason', 'Accountant emergency probe')->firstOrFail();
        $this->assertSame('emergency', $row->leave_type);
        $this->assertSame('approved', $row->status);
        $this->assertNotNull($row->decided_at);
        $this->assertNull($row->decided_by);
        $this->assertStringContainsString('Auto-approved', $row->decision_note);

        // Staff emergency informs every would-be decider: admins
        // AND principals (parent fix, C round 5).
        $this->assertTrue(UserNotification::where('user_id', $this->admin()->id)
            ->where('type', 'leave_emergency')->exists());
        $this->assertTrue(UserNotification::where('user_id', $this->principal()->id)
            ->where('type', 'leave_emergency')->exists());
    }

    public function test_accountant_cannot_decide_another_users_leave()
    {
        $row = LeaveRequest::create([
            'user_id' => $this->teacher()->id,
            'start_date' => '2026-11-19',
            'end_date' => '2026-11-19',
            'reason' => 'Teacher leave accountant must not decide',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->accountant())
            ->post(route('leave.decide', $row), [
                'status' => 'approved',
                'decision_note' => 'Accountant overreach',
            ])->assertRedirect(route('dashboard'));

        $this->assertSame('pending', $row->fresh()->status);
        $this->assertNull($row->fresh()->decided_by);
    }

    public function test_accountant_cannot_decide_own_leave()
    {
        $row = LeaveRequest::create([
            'user_id' => $this->accountant()->id,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'reason' => 'Accountant own leave self-decide probe',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->accountant())
            ->post(route('leave.decide', $row), [
                'status' => 'approved',
                'decision_note' => 'Self approval',
            ])->assertRedirect(route('dashboard'));

        $this->assertSame('pending', $row->fresh()->status);
        $this->assertNull($row->fresh()->decided_by);
    }

    public function test_admin_queue_opens_and_admin_decides_accountant_leave()
    {
        $row = LeaveRequest::create([
            'user_id' => $this->accountant()->id,
            'start_date' => '2026-11-23',
            'end_date' => '2026-11-23',
            'reason' => 'Accountant leave for admin decision',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        // Positive controls: the Admin queue opens, and the Admin
        // (not the accountant) decides the accountant's request.
        $this->actingAs($this->admin())
            ->get(route('leave.index'))
            ->assertOk();

        $this->actingAs($this->admin())
            ->post(route('leave.decide', $row), [
                'status' => 'approved',
                'decision_note' => 'Approved by admin',
            ])->assertRedirect();

        $fresh = $row->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->admin()->id, $fresh->decided_by);
    }
}
