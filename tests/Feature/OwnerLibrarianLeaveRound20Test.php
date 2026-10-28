<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 20: the librarian is the LAST staff role whose
 * leave surface was never probed (teachers, students, admins,
 * principals, accountants all locked in earlier rounds). Expected
 * shape (gates read from routes/web.php + LeaveRequestController
 * before writing any test):
 * - leave.index (Admin queue) is teamOrPrincipal:SAWrite-gated, so
 *   the librarian is refused (302 to login via middleware).
 * - leave.my / leave.store are applicant-gated and 'librarian' is
 *   in APPLICANTS, so the librarian applies + views own requests,
 *   and emergency auto-approves with bells to admins + principals.
 * - leave.decide refuses everyone who is not admin, section-teacher
 *   (student leaves only), or principal — so the librarian cannot
 *   decide anyone's leave, including their own.
 */
class OwnerLibrarianLeaveRound20Test extends TestCase
{
    use DatabaseTransactions;

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
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

    public function test_librarian_leave_index_queue_is_closed()
    {
        $this->actingAs($this->librarian())
            ->get(route('leave.index'))
            ->assertRedirect(route('login'));
    }

    public function test_librarian_leave_student_requests_is_closed()
    {
        $this->actingAs($this->librarian())
            ->get(route('leave.student_requests'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_librarian_own_leave_page_opens()
    {
        $this->actingAs($this->librarian())
            ->get(route('leave.my'))
            ->assertOk();
    }

    public function test_librarian_normal_leave_applies_and_lists()
    {
        $this->actingAs($this->librarian())->post(route('leave.store'), [
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-17',
            'reason' => 'Librarian normal probe',
            'leave_type' => 'normal',
        ])->assertRedirect();

        $row = LeaveRequest::where('reason', 'Librarian normal probe')->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame('normal', $row->leave_type);
        $this->assertSame($this->librarian()->id, $row->user_id);

        $this->actingAs($this->librarian())
            ->get(route('leave.my'))
            ->assertOk()
            ->assertSee('Librarian normal probe');
    }

    public function test_librarian_emergency_leave_auto_approves_and_bells_deciders()
    {
        $this->actingAs($this->librarian())->post(route('leave.store'), [
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-18',
            'reason' => 'Librarian emergency probe',
            'leave_type' => 'emergency',
        ])->assertRedirect();

        $row = LeaveRequest::where('reason', 'Librarian emergency probe')->firstOrFail();
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

    public function test_librarian_cannot_decide_another_users_leave()
    {
        $row = LeaveRequest::create([
            'user_id' => $this->teacher()->id,
            'start_date' => '2026-11-19',
            'end_date' => '2026-11-19',
            'reason' => 'Teacher leave librarian must not decide',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->librarian())
            ->post(route('leave.decide', $row), [
                'status' => 'approved',
                'decision_note' => 'Librarian overreach',
            ])->assertRedirect(route('dashboard'));

        $this->assertSame('pending', $row->fresh()->status);
        $this->assertNull($row->fresh()->decided_by);
    }

    public function test_librarian_cannot_decide_own_leave()
    {
        $row = LeaveRequest::create([
            'user_id' => $this->librarian()->id,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'reason' => 'Librarian own leave self-decide probe',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->librarian())
            ->post(route('leave.decide', $row), [
                'status' => 'approved',
                'decision_note' => 'Self approval',
            ])->assertRedirect(route('dashboard'));

        $this->assertSame('pending', $row->fresh()->status);
        $this->assertNull($row->fresh()->decided_by);
    }

    public function test_admin_queue_opens_and_admin_decides_librarian_leave()
    {
        $row = LeaveRequest::create([
            'user_id' => $this->librarian()->id,
            'start_date' => '2026-11-23',
            'end_date' => '2026-11-23',
            'reason' => 'Librarian leave for admin decision',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        // Positive controls: the Admin queue opens, and the Admin
        // (not the librarian) decides the librarian's request.
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
