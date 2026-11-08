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
 *
 * Re-expressed 2026-11-06 (card rebuild): the queue row is
 * now a one-glance CARD and the allocation summary sentence
 * became compact fact CHIPS inside the card's Details
 * expander (owner's card order — no sentence walls). The
 * compactness contract is unchanged: the facts are one glance
 * inside the expander, the full list stays behind "Full
 * allocation", the cover badge leads the cover row, and the
 * explanation + covering names live inside the plan view.
 *
 * Re-expressed again 2026-11-08 (owner's hotfix-F order: the
 * in-cell expanders crushed every detail into the narrow
 * Actions column — "bekar, cluttered"): the expanders are
 * GONE. The details now live in a full-width panel row under
 * the request row; the allocation renders as a proper TABLE
 * (the "Full allocation" text wall is deleted by the same
 * order); the chips, the cover badge and the covering-names-
 * inside-the-detail-area contract are unchanged.
 */
class OwnerLeaveQueueCompactCellsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_queue_card_is_compact_and_the_details_live_in_the_expanders()
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

        // Allocation: the summary's facts are compact chips
        // inside the panel's Details section (the sentence form
        // was the 2026-10-30 design; the chips are its card-era
        // equivalent — same facts, same source string)…
        $this->assertStringContainsString('lv-alloc-chip', $html);
        $this->assertStringContainsString('Class teacher', $html);
        $this->assertStringContainsString('9 sections', $html);
        $this->assertStringContainsString('Teaches 5 subjects', $html);
        $this->assertStringContainsString('6 section overrides', $html);
        // …and the full list still exists, as a proper table
        // inside the panel (2026-11-08: the "Full allocation"
        // text-wall paragraph is deleted by owner order).
        $this->assertStringContainsString('lv-alloc-table', $html);
        $this->assertStringNotContainsString('Full allocation', $html);
        $this->assertStringNotContainsString('Class teacher: Prep', $html);

        // Uncovered: badge in the request row, then the
        // full-width panel row carries the explanation and the
        // covering names.
        $badgePos = strpos($html, 'Plan ready');
        $detailsPos = strpos($html, 'lv-panel-row', (int) $badgePos);
        $namesPos = strpos($html, 'Covered by:');
        $this->assertNotFalse($badgePos, 'Plan-ready badge missing');
        $this->assertNotFalse($detailsPos, 'Panel row missing');
        $this->assertNotFalse($namesPos, 'Covering names missing');
        $this->assertLessThan($namesPos, $detailsPos,
            'The covering names must render INSIDE the panel row, not in the request row');
        $this->assertStringContainsString('See the plan (', $html);
        // The old row-crammed "By: name, name +N more" line is gone.
        $this->assertStringNotContainsString('>By: ', $html);
    }
}
