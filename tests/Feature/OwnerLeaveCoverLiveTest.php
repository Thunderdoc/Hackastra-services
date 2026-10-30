<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Support\LeaveCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles agent C round 4 follow-up (allotment agent F round 3
 * question): does the leave queue's cover attribution need the
 * same live-teacher filter F added to sections/staffing?
 *
 * Answer implemented here: YES at display level only.
 * teacherIdFor is deliberately untouched (it feeds validator /
 * generation). LeaveCover now gates on live status — type
 * 'teacher' AND status null-or-active — so a demoted,
 * suspended, terminated or deleted teacher's leave row shows a
 * dash, never a stale count and never a "Covered" badge.
 * The caller (LeaveRequestController::attachCoverInfo) already
 * dashed demoted (non-teacher type) and deleted (null user)
 * rows; the hole this closes is the suspended/terminated
 * teacher, whose type is still 'teacher'.
 */
class OwnerLeaveCoverLiveTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_live_teacher_baseline_unchanged()
    {
        $t = $this->teacher();
        $this->assertNotEmpty(LeaveCover::allocationLines($t));
        // Proven anchor from OwnerLeaveAllocationTest: Ananya has
        // 8 uncovered periods on Monday 2026-11-02, no subs exist.
        $this->assertSame(8, LeaveCover::uncoveredCount($t->id, '2026-11-02', '2026-11-02'));
    }

    public function test_non_live_teacher_shows_dash_not_counts()
    {
        $t = $this->teacher();

        $t->update(['status' => 'suspended']);
        $this->assertSame([], LeaveCover::allocationLines($t->fresh()));
        $this->assertNull(LeaveCover::uncoveredCount($t->id, '2026-11-02', '2026-11-02'));

        $t->update(['status' => 'terminated']);
        $this->assertSame([], LeaveCover::allocationLines($t->fresh()));
        $this->assertNull(LeaveCover::uncoveredCount($t->id, '2026-11-02', '2026-11-02'));

        // Demoted: type changed, status active again.
        $t->update(['status' => 'active', 'user_type' => 'accountant']);
        $this->assertSame([], LeaveCover::allocationLines($t->fresh()));
        $this->assertNull(LeaveCover::uncoveredCount($t->id, '2026-11-02', '2026-11-02'));

        // Deleted / never existed: no user row, no count.
        $this->assertNull(LeaveCover::uncoveredCount(999999, '2026-11-02', '2026-11-02'));
    }

    public function test_queue_badges_follow_live_status()
    {
        $t = $this->teacher();
        LeaveRequest::create([
            'user_id' => $t->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
            'reason' => 'R4 live-filter probe', 'leave_type' => 'normal', 'status' => 'pending',
        ]);

        // Live: the badge names the real gap.
        $this->actingAs($this->admin())->get(route('leave.index'))
            ->assertOk()->assertSee('8 without cover');

        // Suspended: neither badge — the row shows a dash instead.
        // (Scoped to the badge markup since 2026-10-30: the queue's
        // coverage FILTER legitimately contains the word "Covered"
        // as an option label; the pin's intent is the row's badge.)
        $t->update(['status' => 'suspended']);
        $this->actingAs($this->admin())->get(route('leave.index'))
            ->assertOk()->assertSee('Ananya Sharma')
            ->assertDontSee('without cover')->assertDontSee('>Covered</span>');
    }
}
