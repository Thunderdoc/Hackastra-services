<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\Support\Notify;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles agent C round 5: principal bell + emergency-leave notification gap.
 *
 * Pattern: every claim is proven by a request, not by reading code.
 * All fixtures roll back via DatabaseTransactions.
 */
class OwnerPrincipalEmergencyBellTest extends TestCase
{
    use DatabaseTransactions;

    protected function byEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function admin() { return $this->byEmail('admin@hackstra.app'); }
    protected function teacher() { return $this->byEmail('teacher@hackstra.app'); }
    protected function principal() { return $this->byEmail('principal@hackstra.app'); }
    protected function student() { return $this->byEmail('student@hackstra.app'); }

    protected function emergencyPayload($from = '2026-11-02', $to = '2026-11-03')
    {
        return [
            'start_date' => $from,
            'end_date' => $to,
            'reason' => 'Round 5 probe emergency',
            'leave_type' => 'emergency',
        ];
    }

    /**
     * FIXED by parent 2026-10-28 (C round-5 finding): staff
     * emergency leave now notifies admins AND the principal —
     * both deciders — via User::whereIn('user_type',
     * ['admin', 'principal']). Student emergency leaves still
     * bell only the section teacher (single decider), by design.
     */
    public function test_staff_emergency_leave_notifies_admin_and_principal()
    {
        $this->actingAs($this->teacher())
            ->post(route('leave.store'), $this->emergencyPayload())
            ->assertRedirect();

        $leave = LeaveRequest::where('user_id', $this->teacher()->id)
            ->where('leave_type', 'emergency')->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertSame('approved', $leave->status);

        // Admin is informed (current behaviour, correct).
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->admin()->id,
            'type' => 'leave_emergency',
        ]);

        // Principal IS informed (fixed by parent 2026-10-28: staff
        // emergency leaves bell admins AND the principal, both
        // deciders — C round-5 finding).
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->principal()->id,
            'type' => 'leave_emergency',
        ]);

        // The applicant is not belled about their own leave.
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $this->teacher()->id,
            'type' => 'leave_emergency',
        ]);
    }

    /**
     * Current behaviour, documented: a student emergency leave bells
     * ONLY the section teacher. Admins and the principal — who can
     * also decide student leaves as oversight — get nothing. Whether
     * that matches the "inform the approver" design is a parent call;
     * this test pins the behaviour so the decision is explicit.
     */
    public function test_student_emergency_leave_notifies_section_teacher_only()
    {
        $this->actingAs($this->student())
            ->post(route('leave.store'), $this->emergencyPayload('2026-11-04', '2026-11-05'))
            ->assertRedirect();

        $leave = LeaveRequest::where('user_id', $this->student()->id)
            ->where('leave_type', 'emergency')->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertSame('approved', $leave->status);

        // Section teacher of Prep A is Ananya (teacher@).
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->teacher()->id,
            'type' => 'leave_emergency',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $this->admin()->id,
            'type' => 'leave_emergency',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $this->principal()->id,
            'type' => 'leave_emergency',
        ]);
    }

    /**
     * Bell isolation: the topbar renders Notify::latestFor(Auth::id),
     * so a principal's bell can only ever carry their own rows —
     * proven here with one item per role, not by reading the query.
     */
    public function test_principal_bell_shows_only_own_items()
    {
        Notify::toUser($this->admin()->id, 'probe_role', 'Admin only item');
        Notify::toUser($this->principal()->id, 'probe_role', 'Principal own item');

        $items = Notify::latestFor($this->principal()->id, 10);
        $this->assertCount(1, $items);
        $this->assertSame('Principal own item', $items->first()->title);
        $this->assertSame(1, Notify::unreadCount($this->principal()->id));

        // And the admin's bell is likewise uncontaminated.
        $adminItems = Notify::latestFor($this->admin()->id, 10);
        $this->assertTrue($adminItems->every(fn ($n) => $n->user_id === $this->admin()->id));
    }

    /**
     * Normal (non-emergency) staff leave notifies nobody — the
     * approver sees it in the queue instead. Guards against a fix
     * that over-broadly bells every leave submission.
     */
    public function test_normal_staff_leave_notifies_nobody()
    {
        $payload = $this->emergencyPayload('2026-11-09', '2026-11-10');
        $payload['leave_type'] = 'normal';

        $this->actingAs($this->teacher())
            ->post(route('leave.store'), $payload)
            ->assertRedirect();

        $leave = LeaveRequest::where('user_id', $this->teacher()->id)
            ->where('leave_type', 'normal')->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertSame('pending', $leave->status);

        $this->assertSame(0, UserNotification::where('type', 'leave_emergency')->count());
    }
}
