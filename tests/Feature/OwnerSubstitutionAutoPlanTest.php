<?php

namespace Tests\Feature;

use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Full-auto substitution flow (owner order 2026-10-29).
 *
 * The system arranges cover for a teacher's leave by itself and
 * SAVES it as a pending plan — no access, no bells. Under the
 * 2026-10-29 night order the plan is managed from the LEAVE page
 * and goes live when the leave is approved (or at submit for an
 * emergency); the Substitutions board is the record. These tests
 * pin the plan machinery itself — the pending state, the switch
 * endpoint's free-teacher rule, and the (still admin-only)
 * confirm endpoint's student bells — which the leave-page flow
 * is built on.
 */
class OwnerSubstitutionAutoPlanTest extends TestCase
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

    protected function ananya()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /** Ananya's NORMAL leave on Mon 2026-11-02 (8 Monday periods):
     *  the plan is prepared at submit and stays pending until the
     *  leave is approved. (An emergency would go live at submit —
     *  owner order 2026-10-29 night — leaving nothing pending to
     *  exercise here.) */
    protected function arrangePlan(): void
    {
        $this->actingAs($this->ananya())->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'AutoPlan probe',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $this->assertGreaterThan(0, Substitution::pending()
            ->where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-02')->count(), 'fixture: the system must have arranged a pending plan');
    }

    public function test_board_shows_the_pending_plan_as_a_record_and_confirm_stays_admin_only()
    {
        $this->arrangePlan();

        // The board shows the plan as a RECORD (owner order
        // 2026-10-29 night): no submit button, no switch controls,
        // for the Admin either — the leave page is the workplace.
        $page = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $page->assertOk();
        $page->assertSee('System-arranged plans — record');
        $page->assertSee('goes live when the leave is approved');
        $page->assertDontSee('Submit this plan');
        $page->assertDontSee('Switch to a free teacher');

        $principalPage = $this->actingAs($this->principal())->get(route('substitutions.index'));
        $principalPage->assertOk();
        $principalPage->assertSee('goes live when the leave is approved');
        $principalPage->assertDontSee('Submit this plan');

        // The confirm endpoint itself remains Admin-only machinery:
        // principal and teacher are refused.
        $this->actingAs($this->principal())->post(route('substitutions.confirm'), [
            'absent_teacher_id' => $this->ananya()->id, 'date' => '2026-11-02',
        ])->assertForbidden();
        $this->actingAs($this->ananya())->post(route('substitutions.confirm'), [
            'absent_teacher_id' => $this->ananya()->id, 'date' => '2026-11-02',
        ])->assertForbidden();
    }

    public function test_switch_accepts_a_free_teacher_and_rejects_the_absent_teacher()
    {
        $this->arrangePlan();
        $row = Substitution::pending()
            ->where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();

        // The absent teacher is never a valid switch target.
        $this->actingAs($this->admin())->post(route('substitutions.switch', $row->id), [
            'substitute_teacher_id' => $this->ananya()->id,
        ])->assertSessionHasErrors('substitute_teacher_id');
        $this->assertSame((int) $row->substitute_teacher_id, (int) $row->fresh()->substitute_teacher_id);

        // A genuinely free teacher switches cleanly and the row
        // stays pending. The endpoint's free list is the board's
        // own computation; probe candidates in id order (busy ones
        // are refused with errors and change nothing) until one is
        // accepted — with ~46 teachers and one Monday slot, a free
        // teacher always exists in this fixture.
        $switched = false;
        foreach (User::where('user_type', 'teacher')
            ->whereNotIn('id', [$this->ananya()->id, (int) $row->substitute_teacher_id])
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->get() as $candidate) {
            $response = $this->actingAs($this->admin())->post(route('substitutions.switch', $row->id), [
                'substitute_teacher_id' => $candidate->id,
            ]);
            if ((int) $row->fresh()->substitute_teacher_id === (int) $candidate->id) {
                $switched = true;
                break;
            }
        }
        $this->assertTrue($switched, 'at least one genuinely free teacher must be switchable');
        $this->assertSame('pending', $row->fresh()->status, 'a switch must not submit the plan');
    }

    public function test_confirm_bells_students_for_covered_and_free_periods()
    {
        $this->arrangePlan();

        // Simulate one period the plan could not cover: remove its
        // pending row, leaving a gap the students must hear about.
        $gapRow = Substitution::pending()
            ->where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-02')
            ->with('time_table.tt_record')->firstOrFail();
        $gapRecord = $gapRow->time_table->tt_record;
        $gapStudent = StudentRecord::where('grad', 0)
            ->when($gapRecord->section_id,
                fn ($q) => $q->where('section_id', $gapRecord->section_id),
                fn ($q) => $q->where('my_class_id', $gapRecord->my_class_id))
            ->firstOrFail();
        $gapRow->delete();

        $coveredRow = Substitution::pending()
            ->where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-02')
            ->with('time_table.tt_record')->firstOrFail();
        $coveredRecord = $coveredRow->time_table->tt_record;
        $coveredStudent = StudentRecord::where('grad', 0)
            ->when($coveredRecord->section_id,
                fn ($q) => $q->where('section_id', $coveredRecord->section_id),
                fn ($q) => $q->where('my_class_id', $coveredRecord->my_class_id))
            ->firstOrFail();

        $this->actingAs($this->admin())->post(route('substitutions.confirm'), [
            'absent_teacher_id' => $this->ananya()->id, 'date' => '2026-11-02',
        ])->assertRedirect();

        $this->assertTrue(UserNotification::where('user_id', $coveredStudent->user_id)
            ->where('type', 'period_substitute')->exists(),
            'students of a covered period must be belled at submit');
        $this->assertTrue(UserNotification::where('user_id', $gapStudent->user_id)
            ->where('type', 'period_free')->exists(),
            'students of an uncovered period must get the free-period bell at submit');

        // A second submit of the same group is refused honestly.
        $this->actingAs($this->admin())->post(route('substitutions.confirm'), [
            'absent_teacher_id' => $this->ananya()->id, 'date' => '2026-11-02',
        ])->assertSessionHas('flash_danger', 'That substitution plan has already been submitted.');
    }
}
