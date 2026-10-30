<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Support\LeaveAutoCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30 (second screenshot): the leave queue
 * row itself must stay compact — Allocated Classes collapses
 * to a one-line summary ("Class teacher · 9 sections") with
 * the full list only inside the expander, and the Uncovered
 * cell shows ONLY its badge in the row; the explanation and
 * the covering teachers' names live inside the plan view.
 */
class OwnerLeaveQueueCompactCellsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_queue_row_is_compact_and_the_details_live_in_the_expanders()
    {
        $ananya = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();

        $leave = LeaveRequest::create([
            'user_id' => $ananya->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'compact cells pin',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        LeaveAutoCover::arrange($leave, null, false, false);

        $html = $this->actingAs($admin)->get(route('leave.index'))
            ->assertOk()->getContent();

        // Allocated Classes: the compact summary is the row…
        $this->assertStringContainsString('Class teacher · 9 sections', $html);
        // …and the full list still exists, inside its expander.
        $this->assertStringContainsString('Full allocation', $html);
        $this->assertStringContainsString('Class teacher: Prep', $html);

        // Uncovered: badge in the row, then the details expander
        // carries the explanation and the covering names.
        $badgePos = strpos($html, 'Plan ready');
        $detailsPos = strpos($html, '<details', (int) $badgePos);
        $namesPos = strpos($html, 'Covered by:');
        $this->assertNotFalse($badgePos, 'Plan-ready badge missing');
        $this->assertNotFalse($detailsPos, 'Plan expander missing');
        $this->assertNotFalse($namesPos, 'Covering names missing');
        $this->assertLessThan($namesPos, $detailsPos,
            'The covering names must render INSIDE the plan/details expander, not in the row');
        $this->assertStringContainsString('See the plan (', $html);
        // The old row-crammed "By: name, name +N more" line is gone.
        $this->assertStringNotContainsString('>By: ', $html);
    }
}
