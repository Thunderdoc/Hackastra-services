<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Leave queue phone layout (owner screenshot, 2026-10-30): on
 * his ~360px phone the 10-column requests table clipped on both
 * sides and the plan rows stacked into walls of text. The fix
 * is presentation only: below 992px the same single DOM stacks
 * each request into a card and each plan row into compact lines
 * (date + time / class / subject / bold substitute / one-row
 * switch). These pins keep the layout mechanism — the lv
 * classes, the stacking breakpoint and the compact plan cells —
 * from quietly disappearing in a future blade edit. Real-width
 * proof is the 360px/320px renders attached to the segment.
 */
class OwnerLeaveQueueMobileLayoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_queue_page_carries_the_stacking_layout()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();

        $resp = $this->actingAs($admin)->get(route('leave.index'));
        $resp->assertOk();
        $resp->assertSee('lv-table', false);
        $resp->assertSee('lv-wrap', false);
        $resp->assertSee('@media (max-width: 991.98px)', false);
        $resp->assertSee('nth-child(9)', false);
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
