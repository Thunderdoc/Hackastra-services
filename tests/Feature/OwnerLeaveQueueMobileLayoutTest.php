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
 * the requests TABLE itself as a text wall, so the queue became
 * one-glance cards that stack full-width — no table existed to
 * clip. The card pins replaced the old table pins at equal
 * strength.
 *
 * Re-expressed again 2026-11-07 (late, owner scope correction —
 * "yha ve toh mai proper table type saab bola tha"): the owner
 * ordered proper tables for BOTH leave queues, cards retired.
 * The layout pin below now asserts the table mechanism at equal
 * strength: the hk-std main-queue sheet in the DOM with a real
 * request row and its decision controls, no card markup, and
 * the retained sub-992px breakpoint under which the tabular
 * parts inside the expanders (plan rows, week grid) go compact
 * — one line for date + time, one for class, one for subject,
 * the substitute's name in bold, and the switch select +
 * button on one row. Phone safety now comes from the
 * .table-responsive inner scroll around the sheet plus those
 * compact rules. Real-width proof is the 360px/320px renders
 * attached to the segment.
 *
 * Re-expressed 2026-11-08 (panel redesign, owner screenshots):
 * the expanders no longer live inside the narrow Actions cell
 * at ANY width — their content opens in a full-width panel row
 * under the request (see OwnerLeaveQueueTablesTest). The phone
 * pin below is re-expressed at equal strength: under the same
 * sub-992px breakpoint the panel tables now stay tables and
 * scroll inside their .table-responsive wraps at readable
 * min-widths; the stacked-block transformation with its
 * ::before column labels is retired (it was the fixed-layout
 * squeeze that collided in the first place).
 */
class OwnerLeaveQueueMobileLayoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_queue_page_carries_the_table_layout()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        \App\Models\LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'Table layout check',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $resp = $this->actingAs($admin)->get(route('leave.index'));
        $resp->assertOk();
        // The table mechanism: the hk-std main-queue sheet, the
        // request rendered as a row with its decision controls,
        // and the page stylesheet's phone breakpoint for the
        // tabular parts inside the expanders.
        $resp->assertSee('lv-sq-table lv-mq-table', false);
        $resp->assertSee('Ananya Sharma');
        $resp->assertSee('name="decision_note"', false);
        $resp->assertSee('@media (max-width: 991.98px)', false);
        // The card markup is retired on this surface (the CSS
        // class definitions in the page style are not markup).
        $resp->assertDontSee('class="lv-req-card"', false);
        $resp->assertDontSee('class="lv-req-list"', false);
        // The 2026-10-30 10-column requests table stays gone.
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

    public function test_panel_tables_stay_tables_on_phone_via_inner_scroll()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $resp = $this->actingAs($admin)->get(route('leave.index'));
        $resp->assertOk();
        // The breakpoint survives; under it the panel tables keep
        // their columns at readable min-widths inside their
        // .table-responsive wraps instead of restacking.
        $resp->assertSee('@media (max-width: 991.98px)', false);
        $resp->assertSee('.lv-plan-table { min-width: 680px; }', false);
        $resp->assertSee('.lv-week-table { min-width: 620px; }', false);
        $resp->assertSee('.lv-alloc-table { min-width: 540px; }', false);
        // The retired stacked-block rules (and their ::before
        // column labels) are gone from the page stylesheet.
        $resp->assertDontSee('lv-plan-table td:nth-child(3)::before', false);
        $resp->assertDontSee('content: "Substitute: "', false);
    }
}
