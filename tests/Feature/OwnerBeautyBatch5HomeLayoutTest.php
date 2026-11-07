<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Beauty Batch 5 (2026-11-07, audit home-layout family B2-B6/B9/B13
 * remainders + the Batch-4 accountant sighting):
 * Batch 3's stylesheet block already carried the B2/B3/B5/B6/B9/B13
 * layout rules; this batch capture-verified them on every role home
 * (nurse tiles span the row, teacher KPI 4-across + quote strip,
 * parent/accountant grids balanced 3+2, admin View-All on one line
 * + content-height Upcoming panel, SA lists ending on whole rows,
 * timetables chips capped) and closed what was genuinely left:
 * (1) B4 — below 576px the shared .dashboard-stat-card was still a
 *     tall column tile inside the 2-up phone grid (~250-290px tall,
 *     ~40% empty bottom on Students list / Library books). It now
 *     goes compact: tight padding/gaps, min-height and the
 *     space-between spread released, smaller icon.
 * (2) Money figures — long rupee values in half-width tiles wrapped
 *     the rupee sign onto its own line and burst past the tile edge
 *     on the accountant home at 360px. Inside the accountant grid
 *     and the dashboard-stats rows the value now takes a fluid size
 *     and never wraps: exact figure, fully readable at 360/320,
 *     never truncated.
 * (3) The accountant grid's 5th tile was reset to a half-width
 *     orphan below 576px by Batch 3's span reset (written for the
 *     1-track parent ladder); the accountant grid is 2-track at
 *     every phone width, so its span-2 last tile is restored.
 * Presentation only: no content, data or behaviour changes.
 */
class OwnerBeautyBatch5HomeLayoutTest extends TestCase
{
    use DatabaseTransactions;

    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_stylesheet_carries_the_batch5_rules()
    {
        $css = $this->css();

        // B4 — compact stat cards on phones (tight column: the value
        // keeps the tile's full width; a one-row layout was measured
        // and rejected — a money figure needs ~97px, the row layout
        // left the copy column only 70px at 360px).
        $this->assertStringContainsString('.dashboard-stat-card{gap:8px;justify-content:flex-start;min-height:0;padding:14px}', $css);
        $this->assertStringContainsString('.dashboard-stat-icon{font-size:16px;height:38px;width:38px}', $css);

        // Money figures — fluid size, never wrap, never truncated.
        $this->assertStringContainsString('.acc-stat-grid .dashboard-stat-copy strong,.dashboard-stats .dashboard-stat-copy strong{font-size:clamp(.88rem,4.1vw,1.5rem);letter-spacing:-.01em;white-space:nowrap}', $css);

        // Accountant grid — the 5th tile spans the full row again.
        $this->assertStringContainsString('.acc-stat-grid>*:nth-child(5){grid-column:span 2}', $css);
    }

    public function test_batch5_rules_come_after_the_batch3_resets_they_refine()
    {
        $css = $this->css();

        $batch3Reset = strpos($css, '.acc-stat-grid>*:nth-child(n){grid-column:auto}');
        $batch5Span = strrpos($css, '.acc-stat-grid>*:nth-child(5){grid-column:span 2}');

        $this->assertNotFalse($batch3Reset);
        $this->assertNotFalse($batch5Span);
        // Equal specificity: only source order lets the span-2 win.
        $this->assertGreaterThan($batch3Reset, $batch5Span);
    }

    public function test_accountant_home_still_renders_its_money_tiles()
    {
        $html = $this->actingAs($this->user('accountant@hackstra.app'))
            ->get('/home')->assertOk()->getContent();

        $this->assertStringContainsString('acc-stat-grid', $html);
        $this->assertStringContainsString('dashboard-stat-copy', $html);
        $this->assertStringContainsString('Collected This Month', $html);
        $this->assertStringContainsString('Payments Today', $html);
    }

    public function test_students_list_still_renders_its_stat_tiles()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('students.list', 1))->assertOk()->getContent();

        $this->assertStringContainsString('dashboard-stats', $html);
        $this->assertStringContainsString('dashboard-stat-card', $html);
        $this->assertStringContainsString('Total Students', $html);
    }
}
