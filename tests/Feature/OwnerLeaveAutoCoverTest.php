<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\UserNotification;
use App\Support\LeaveAutoCover;
use App\Support\LeaveCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Auto-arranged leave cover (owner order 2026-10-29).
 *
 * The owner settled the auto-cover question: the SYSTEM arranges
 * substitutes for a teacher's leave — emergency leave immediately
 * at submit, normal leave when the Admin/Principal approves it
 * (the admin still approves the leave itself). Uncoverable periods
 * raise an urgent bell to admins + principal instead of vanishing.
 * These tests pin that behaviour end-to-end on the seeded demo
 * timetable (Ananya — teacher@ — teaches on Mondays).
 */
class OwnerLeaveAutoCoverTest extends TestCase
{
    use DatabaseTransactions;

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

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    public function test_emergency_teacher_leave_auto_arranges_cover_immediately()
    {
        $teacher = $this->teacher();
        $expected = LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02');
        $this->assertNotNull($expected);
        $this->assertGreaterThan(0, $expected);

        $response = $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'AutoCover emergency',
            'leave_type' => 'emergency',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('flash_success', 'Emergency leave auto-approved.');

        $rows = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->get();
        $after = LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02');
        // The books balance: every Monday period is either covered
        // by an auto row or still honestly counted uncovered.
        $this->assertSame($expected, $rows->count() + $after);
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $this->assertSame('Auto-arranged (leave)', $row->reason);
            $this->assertNull($row->created_by);
            $this->assertNotSame((int) $teacher->id, (int) $row->substitute_teacher_id);
        }
        // Owner order 2026-10-29 (full-auto flow): the arrangement
        // is a pending PLAN — the rows are 'pending', substitutes
        // are NOT belled yet, and the Admin holds a plan-ready bell.
        foreach ($rows as $row) {
            $this->assertSame('pending', $row->status);
        }
        $this->assertFalse(UserNotification::where('type', 'substitution_assigned')
            ->whereIn('user_id', $rows->pluck('substitute_teacher_id')->all())
            ->exists());
        $this->assertTrue(UserNotification::where('user_id', $this->admin()->id)
            ->where('type', 'substitution_plan_ready')->exists());

        // The Admin submits the plan — NOW the substitutes are
        // belled and the rows go live.
        $this->actingAs($this->admin())->post(route('substitutions.confirm'), [
            'absent_teacher_id' => $teacher->id, 'date' => '2026-11-02',
        ])->assertRedirect();
        $this->assertTrue(UserNotification::where('type', 'substitution_assigned')
            ->whereIn('user_id', $rows->pluck('substitute_teacher_id')->all())
            ->exists());
        $this->assertSame(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->where('status', 'pending')->count());
    }

    public function test_admin_approval_arranges_but_rejection_does_not()
    {
        $teacher = $this->teacher();
        $expected = LeaveCover::uncoveredCount($teacher->id, '2026-11-09', '2026-11-09');
        $this->assertGreaterThan(0, $expected);

        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'reason' => 'AutoCover normal',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $leave = LeaveRequest::where('user_id', $teacher->id)
            ->where('reason', 'AutoCover normal')->firstOrFail();
        $this->assertSame('pending', $leave->status);
        // Pending: nothing arranged yet — the admin still decides first.
        $this->assertSame(0, Substitution::where('absent_teacher_id', $teacher->id)->count());

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ])->assertRedirect();
        $rows = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-09')->count();
        $after = LeaveCover::uncoveredCount($teacher->id, '2026-11-09', '2026-11-09');
        $this->assertSame($expected, $rows + $after);
        $this->assertGreaterThan(0, $rows);
        $this->assertSame((int) $this->admin()->id, (int) Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-09')->first()->created_by);

        // Rejection arranges nothing.
        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-16',
            'reason' => 'AutoCover rejected',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $leave2 = LeaveRequest::where('user_id', $teacher->id)
            ->where('reason', 'AutoCover rejected')->firstOrFail();
        $this->actingAs($this->admin())->post(route('leave.decide', $leave2->id), [
            'status' => 'rejected',
        ])->assertRedirect();
        $this->assertSame(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-16')->count());
    }

    public function test_uncoverable_leave_alerts_admin_and_principal()
    {
        $teacher = $this->teacher();
        $expected = LeaveCover::uncoveredCount($teacher->id, '2026-11-23', '2026-11-23');
        $this->assertGreaterThan(0, $expected);

        // Every other live teacher is on approved leave that Monday,
        // so the candidate pool is empty by construction.
        $others = User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->where('id', '!=', $teacher->id)->get();
        foreach ($others as $other) {
            LeaveRequest::create([
                'user_id' => $other->id,
                'start_date' => '2026-11-23',
                'end_date' => '2026-11-23',
                'reason' => 'AutoCover pool block',
                'leave_type' => 'normal',
                'status' => 'approved',
                'decided_by' => $this->admin()->id,
                'decided_at' => now(),
            ]);
        }

        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2026-11-23',
            'end_date' => '2026-11-23',
            'reason' => 'AutoCover nobody free',
            'leave_type' => 'emergency',
        ])->assertRedirect();

        $this->assertSame(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-23')->count());
        foreach ([$this->admin()->id, $this->principal()->id] as $uid) {
            $this->assertTrue(UserNotification::where('user_id', $uid)
                ->where('type', 'leave_coverage_unresolved')->exists(),
                'unresolved-coverage bell missing for user ' . $uid);
        }
    }

    public function test_arrange_is_idempotent_and_skips_covered_periods()
    {
        $teacher = $this->teacher();
        $leave = LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-11-30',
            'end_date' => '2026-11-30',
            'reason' => 'AutoCover idempotent',
            'leave_type' => 'emergency',
            'status' => 'approved',
            'decided_at' => now(),
        ]);

        $first = LeaveAutoCover::arrange($leave, null);
        $countAfterFirst = Substitution::where('absent_teacher_id', $teacher->id)->count();
        $this->assertSame($first['arranged'], $countAfterFirst);

        $second = LeaveAutoCover::arrange($leave, null);
        $this->assertSame(0, $second['arranged']);
        $this->assertSame($countAfterFirst, Substitution::where('absent_teacher_id', $teacher->id)->count());
    }

    public function test_student_and_non_teacher_staff_leaves_never_arrange()
    {
        $this->actingAs($this->student())->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'AutoCover student emergency',
            'leave_type' => 'emergency',
        ])->assertRedirect();
        $this->assertSame(0, Substitution::count());

        $this->actingAs($this->accountant())->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'AutoCover accountant',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $leave = LeaveRequest::where('user_id', $this->accountant()->id)
            ->where('reason', 'AutoCover accountant')->firstOrFail();
        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ])->assertRedirect();
        $this->assertSame(0, Substitution::count());
    }
}
