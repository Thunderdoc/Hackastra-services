<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Support\LeaveAutoCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Leave UX overhaul (2026-11-06, overnight beauty run): the
 * queue and My Leave pages were refined to the product's
 * one-glance standard — slim module banner, stat tiles, initials
 * avatar + role chip, day count with the partial fraction as a
 * chip, truncated reasons with the full text behind an expander,
 * and the apply form in labelled groups with a live day-count
 * hint. Presentation only: routes, fields, the decision flow
 * and the cover-plan mechanics are the pre-overhaul ones, and
 * these pins keep the new presentation (and the old contract)
 * from quietly disappearing in a future blade edit.
 */
class OwnerLeaveUxTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ananya(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_queue_row_is_one_glance_for_a_pending_leave_with_a_plan()
    {
        $ananya = $this->ananya();
        $leave = LeaveRequest::create([
            'user_id' => $ananya->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'One glance queue pin',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        LeaveAutoCover::arrange($leave, null, false, false);

        $html = $this->actingAs($this->admin())->get(route('leave.index'))
            ->assertOk()->getContent();

        // Slim banner + who-cell: initials avatar, name, role chip.
        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('lv-avatar', $html);
        $this->assertStringContainsString('>AS<', $html);
        $this->assertStringContainsString('lv-role-chip', $html);
        // Day count cell and the pending status chip.
        $this->assertStringContainsString('lv-days', $html);
        $this->assertStringContainsString('>Pending</span>', $html);
        // Cover state at a glance + the plan behind its expander.
        $this->assertStringContainsString('Plan ready', $html);
        $this->assertStringContainsString('See the plan (', $html);
        // The decision form is wired exactly as before.
        $this->assertStringContainsString('action="' . route('leave.decide', $leave->id) . '"', $html);
        $this->assertStringContainsString('name="decision_note"', $html);
    }

    public function test_queue_shows_the_partial_chip_with_its_day_fraction()
    {
        $ananya = $this->ananya();
        LeaveRequest::create([
            'user_id' => $ananya->id,
            'start_date' => '2026-11-03',
            'end_date' => '2026-11-03',
            'reason' => 'Partial chip pin',
            'leave_type' => 'normal',
            'status' => 'pending',
            'day_part' => 'first_half',
        ]);

        $resp = $this->actingAs($this->admin())->get(route('leave.index'));
        $resp->assertOk();
        $resp->assertSee('Half day (periods 1-4)');
        $resp->assertSee('0.5 day');
    }

    public function test_teacher_student_queue_renders_the_banner_without_a_summary()
    {
        // studentRequests() reuses the queue blade with NO summary
        // or filters — the banner must degrade, not error.
        $resp = $this->actingAs($this->ananya())->get(route('leave.student_requests'));
        $resp->assertOk();
        $resp->assertSee('hk-mod-banner', false);
        $resp->assertSee('No leave requests yet.');
    }

    public function test_my_page_balance_tiles_show_the_summary_numbers()
    {
        Setting::updateOrCreate(['type' => 'paid_leave_quota_days'], ['description' => '12']);
        $ananya = $this->ananya();
        LeaveRequest::create([
            'user_id' => $ananya->id,
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-06',
            'reason' => 'Balance tiles pin',
            'leave_type' => 'normal',
            'status' => 'approved',
            'decided_by' => $this->admin()->id,
        ]);

        $html = $this->actingAs($ananya)->get(route('leave.my'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Your leave balance', $html);
        $this->assertStringContainsString('Paid Leave Quota', $html);
        $this->assertStringContainsString('<strong>12</strong>', $html);
        $this->assertStringContainsString('Taken', $html);
        $this->assertStringContainsString('<strong>5</strong>', $html);
        $this->assertStringContainsString('Remaining', $html);
        $this->assertStringContainsString('<strong>7</strong>', $html);
        $this->assertStringContainsString('Extra (Unpaid)', $html);
        $this->assertStringContainsString('<strong>0</strong>', $html);
    }

    public function test_apply_form_is_grouped_with_a_live_hint_and_partial_controls()
    {
        $html = $this->actingAs($this->ananya())->get(route('leave.my'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('action="' . route('leave.store') . '"', $html);
        foreach (['Dates', 'Leave Details', 'Reason'] as $group) {
            $this->assertStringContainsString($group, $html);
        }
        $this->assertStringContainsString('id="lv_day_hint"', $html);
        $this->assertStringContainsString('id="period_range_row"', $html);
        foreach (['start_date', 'end_date', 'leave_type', 'day_part', 'period_from', 'period_to', 'reason'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
    }

    public function test_decision_still_posts_and_records_the_note()
    {
        $ananya = $this->ananya();
        $leave = LeaveRequest::create([
            'user_id' => $ananya->id,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'reason' => 'Decision path pin',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
            'decision_note' => 'Enjoy the break',
        ])->assertRedirect();

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame('Enjoy the break', $leave->decision_note);
        $this->assertNotNull($leave->decided_at);
    }
}
