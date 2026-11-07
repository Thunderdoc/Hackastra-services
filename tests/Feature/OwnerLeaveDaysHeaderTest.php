<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner screenshot 2026-10-30: the leave queue's DAYS header
 * stacked letter-by-letter (D-A-Y-S) inside a 4% fixed-layout
 * column. The column was widened (5.5%) and the short headers
 * made nowrap, so a header word could never stack again.
 *
 * Re-expressed 2026-11-06 (card rebuild): the requests table —
 * and with it the DAYS column — is gone by owner order; the
 * queue is one-glance cards now. The same failure (a label
 * crushed letter-by-letter) is made impossible structurally:
 * the day count is one atomic segment of the card's meta line
 * and the stylesheet holds it (and the other one-line fields)
 * at white-space: nowrap. This pin keeps that protection.
 */
class OwnerLeaveDaysHeaderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_day_count_renders_as_one_nowrap_meta_segment()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-03',
            'reason' => 'Days meta pin',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $page = $this->actingAs($admin)->get(route('leave.index'));
        $page->assertOk();
        $html = $page->getContent();
        // The count and its unit render as one un-splittable
        // piece inside the meta segment…
        $this->assertStringContainsString('lv-days', $html);
        $this->assertStringContainsString('<b>2</b> days', $html);
        // …and the stylesheet forbids wrapping inside it.
        $this->assertStringContainsString('white-space: nowrap', $html);
    }
}
