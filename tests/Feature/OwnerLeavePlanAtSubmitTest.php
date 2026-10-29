<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\UserNotification;
use App\Support\LeaveCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Plan-at-submit (owner order 2026-10-29 night, live queue
 * screenshot: "yahi pe toh system allocate karega").
 *
 * A NORMAL teacher leave now gets its substitution plan prepared
 * by the system the moment it is submitted, so the Admin's leave
 * queue shows the allocation itself — who covers which periods —
 * instead of a bare "N without cover" warning. The plan is
 * provisional: pending rows, no bells, no access. Approval
 * reconciles it (including dropping a pick who has since come
 * onto approved leave) and announces it; rejection deletes it.
 */
class OwnerLeavePlanAtSubmitTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function submitNormalLeave(User $teacher, string $date, string $reason): LeaveRequest
    {
        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => $date,
            'end_date' => $date,
            'reason' => $reason,
            'leave_type' => 'normal',
        ])->assertRedirect();

        return LeaveRequest::where('user_id', $teacher->id)
            ->where('reason', $reason)->firstOrFail();
    }

    public function test_normal_submit_prepares_the_plan_quietly()
    {
        $teacher = $this->teacher();
        $total = LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02');
        $this->assertGreaterThan(0, $total);

        $leave = $this->submitNormalLeave($teacher, '2026-11-02', 'Plan at submit');
        $this->assertSame('pending', $leave->status);

        $rows = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->get();
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame($total, $rows->count()
            + LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02'));
        foreach ($rows as $row) {
            $this->assertSame('pending', $row->status);
            $this->assertNull($row->created_by);
            $this->assertNotSame((int) $teacher->id, (int) $row->substitute_teacher_id);
        }
        // Quiet: nobody is belled about a plan for an unapproved
        // leave — not the Admin, not the substitutes.
        $this->assertFalse(UserNotification::where('user_id', $this->admin()->id)
            ->where('type', 'substitution_plan_ready')->exists());
        $this->assertFalse(UserNotification::where('type', 'substitution_assigned')
            ->whereIn('user_id', $rows->pluck('substitute_teacher_id')->all())->exists());
    }

    public function test_queue_shows_plan_ready_with_substitute_names()
    {
        $teacher = $this->teacher();
        $leave = $this->submitNormalLeave($teacher, '2026-11-02', 'Plan on the queue');

        $firstSub = Substitution::with('substitute')
            ->where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();

        $page = $this->actingAs($this->admin())->get(route('leave.index'));
        $page->assertOk();
        $page->assertSee('Plan ready');
        $page->assertSee(optional($firstSub->substitute)->name);
        // The row's view data carries the planned count too.
        $found = null;
        foreach ($page->viewData('requests') as $r) {
            if ((int) $r->id === (int) $leave->id) { $found = $r; break; }
        }
        $this->assertNotNull($found);
        $this->assertGreaterThan(0, (int) $found->planned_periods);
    }

    public function test_approval_reconcile_drops_a_pick_who_is_on_approved_leave()
    {
        $teacher = $this->teacher();
        $total = LeaveCover::uncoveredCount($teacher->id, '2026-11-09', '2026-11-09');
        $this->assertGreaterThan(0, $total);

        $leave = $this->submitNormalLeave($teacher, '2026-11-09', 'Plan reconcile');
        $planRow = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-09')->firstOrFail();
        $stalePick = (int) $planRow->substitute_teacher_id;

        // The picked substitute comes onto APPROVED leave for the
        // same date through a data-level path that never runs the
        // cover chain (an import, a silent chain failure — the
        // chain's call sites swallow failures by design).
        LeaveRequest::create([
            'user_id' => $stalePick,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'reason' => 'Data-level approved leave',
            'leave_type' => 'normal',
            'status' => 'approved',
            'decided_by' => $this->admin()->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ])->assertRedirect();

        // The stale pick serves nothing for this leave any more,
        // and the books still balance exactly.
        $this->assertSame(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-09')
            ->where('substitute_teacher_id', $stalePick)->count());
        $rows = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-09')->count();
        $this->assertSame($total, $rows
            + LeaveCover::uncoveredCount($teacher->id, '2026-11-09', '2026-11-09'));
    }

    public function test_rejection_deletes_the_prepared_plan()
    {
        $teacher = $this->teacher();
        $leave = $this->submitNormalLeave($teacher, '2026-11-16', 'Plan rejected');
        $this->assertGreaterThan(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-16')->count());

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'rejected',
        ])->assertRedirect();

        $this->assertSame(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-16')->count());
    }

    public function test_student_normal_leave_prepares_nothing()
    {
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $this->actingAs($student)->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'Student plan guard',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $this->assertSame(0, Substitution::count());
    }
}
