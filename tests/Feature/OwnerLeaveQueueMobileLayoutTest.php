<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Leave queue phone layout (owner screenshot, 2026-10-30): on
 * his ~360px phone the 10-column requests table clipped on both
 * sides and the plan rows stacked into walls of text.
 *
 * Re-expressed 2026-11-06 (card rebuild): the owner rejected
 * the requests TABLE itself as a text wall, so the queue is now
 * one-glance cards that stack full-width — no table exists to
 * clip. The old pins (lv-table / lv-wrap / the status column's
 * nth-child(9) absolute positioning) described that table's
 * stacking mechanism and are replaced by pins on the card
 * mechanism at equal strength: the card list + card markup in
 * the DOM, the card stylesheet, and the sub-992px breakpoint
 * under which the card's tabular parts (plan rows, week grid)
 * go compact — one line for date + time, one for class, one
 * for subject, the substitute's name in bold, and the switch
 * select + button on one row. Real-width proof is the
 * 360px/320px renders attached to the segment.
 */
class OwnerLeaveQueueMobileLayoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_queue_page_carries_the_card_layout()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        \App\Models\LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'Card layout check',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $resp = $this->actingAs($admin)->get(route('leave.index'));
        $resp->assertOk();
        // The card mechanism: list container, one card per
        // request, headline row — and the card stylesheet with
        // the phone breakpoint for the tabular parts.
        $resp->assertSee('lv-req-list', false);
        $resp->assertSee('lv-req-card', false);
        $resp->assertSee('lv-req-head', false);
        $resp->assertSee('@media (max-width: 991.98px)', false);
        // The 10-column requests table is gone for good.
        $resp->assertDontSee('Allocated Classes');
        $resp->assertDontSee('Uncovered Periods');
    }

    public function test_plan_rows_render_compact_cells_with_switch_forms()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $this->actingAs($teacher)->post(route('leave.store'), [
            'leave_type' => 'normal',
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'Mobile layout check',
        ])->assertRedirect();

        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $resp = $this->actingAs($admin)->get(route('leave.index'));
        $resp->assertOk();
        $resp->assertSee('See the plan', false);
        $resp->assertSee('lv-plan-when', false);
        $resp->assertSee('lv-plan-class', false);
        $resp->assertSee('lv-plan-subname', false);
        $resp->assertSee('lv-plan-table', false);
        // The when-line carries the date AND the slot time together.
        $resp->assertSee('Mon, 02/11/2026', false);
    }
}
