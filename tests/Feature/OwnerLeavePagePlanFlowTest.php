<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Everything happens on the leave page (owner order 2026-10-29
 * night): the Substitutions board no longer creates or submits
 * anything — it is the record. The plan prepared at submit is
 * reviewed and switched from the Leave Requests queue, approving
 * the leave makes the plan live (substitutes belled + access,
 * students belled), and an emergency plan goes live at submit
 * because an emergency has no approval step.
 */
class OwnerLeavePagePlanFlowTest extends TestCase
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

    protected function submitLeave(User $teacher, string $date, string $reason, string $type = 'normal'): LeaveRequest
    {
        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => $date,
            'end_date' => $date,
            'reason' => $reason,
            'leave_type' => $type,
        ])->assertRedirect();

        return LeaveRequest::where('user_id', $teacher->id)
            ->where('reason', $reason)->firstOrFail();
    }

    public function test_approving_the_leave_makes_the_plan_live()
    {
        $teacher = $this->teacher();
        $leave = $this->submitLeave($teacher, '2026-11-02', 'Flow approval');
        $rows = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->get();
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame(0, $rows->where('status', 'confirmed')->count());

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ])->assertRedirect();

        // Every planned row is live now — no second submit anywhere.
        $this->assertSame(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->where('status', 'pending')->count());
        $this->assertGreaterThan(0, Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->where('status', 'confirmed')->count());
        // Substitutes were belled by the approval itself, and the
        // obsolete "go submit the plan" bell never fired.
        $this->assertTrue(UserNotification::where('type', 'substitution_assigned')
            ->whereIn('user_id', $rows->pluck('substitute_teacher_id')->all())->exists());
        $this->assertFalse(UserNotification::where('user_id', $this->admin()->id)
            ->where('type', 'substitution_plan_ready')->exists());
        // Students of a covered period heard who is coming.
        $this->assertTrue(UserNotification::where('type', 'period_substitute')->exists());
    }

    public function test_emergency_plan_goes_live_at_submit()
    {
        $teacher = $this->teacher();
        $this->submitLeave($teacher, '2026-11-02', 'Flow emergency', 'emergency');

        $rows = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->get();
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame(0, $rows->where('status', 'pending')->count());
        $this->assertTrue(UserNotification::where('type', 'substitution_assigned')
            ->whereIn('user_id', $rows->pluck('substitute_teacher_id')->all())->exists());
        $this->assertFalse(UserNotification::where('user_id', $this->admin()->id)
            ->where('type', 'substitution_plan_ready')->exists());
    }

    public function test_queue_shows_the_plan_with_switch_forms()
    {
        $teacher = $this->teacher();
        $this->submitLeave($teacher, '2026-11-02', 'Flow queue plan');

        $page = $this->actingAs($this->admin())->get(route('leave.index'));
        $page->assertOk();
        $page->assertSee('Plan ready');
        $page->assertSee('See the plan');
        // A switch form points at the board's switch endpoint.
        $row = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();
        $page->assertSee(route('substitutions.switch', $row->id), false);
    }

    public function test_principal_can_switch_a_pick_from_the_leave_page()
    {
        $teacher = $this->teacher();
        $this->submitLeave($teacher, '2026-11-02', 'Flow principal switch');
        $row = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();

        $board = new \App\Http\Controllers\SupportTeam\SubstitutionController();
        $free = $board->freeTeachersFor($row);
        $this->assertTrue($free->isNotEmpty(), 'fixture: no free teacher to switch to');
        $target = $free->first();

        $this->actingAs($this->principal())->post(route('substitutions.switch', $row->id), [
            'substitute_teacher_id' => $target->id,
        ])->assertRedirect();
        $this->assertSame((int) $target->id, (int) $row->fresh()->substitute_teacher_id);
    }

    public function test_board_is_a_record_only()
    {
        $teacher = $this->teacher();
        $this->submitLeave($teacher, '2026-11-02', 'Flow board record');

        $board = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $board->assertOk();
        $board->assertSee('System-arranged plans — record');
        $board->assertDontSee('Arrange Substitution');
        $board->assertDontSee('Submit this plan');
        $board->assertDontSee('Switch to a free teacher');

        // The old create page points at the leave flow now.
        $create = $this->actingAs($this->admin())->get(route('substitutions.create'));
        $create->assertRedirect(route('substitutions.index'));
        $create->assertSessionHas('flash_danger');
    }
}
