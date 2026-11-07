<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 16 (2026-11-01): Workload Analysis redesign (owner-approved
 * sample). Presentation only — the tiles, segment bars and rows
 * must carry the controller's own figures byte-identically: her
 * real week is 36 periods with Monday at the 8-period cap.
 */
class OwnerWorkloadLuxeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tiles_and_bars_carry_the_controllers_own_numbers()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $response = $this->actingAs($teacher)->get(route('tt.workload'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(36, (int) $response->viewData('total'));
        // Visual-coherence round (2026-11-06): the tiles are now
        // shared dashboard-stat-cards under the slim module
        // banner — same figures, the total rides the stat <strong>.
        $this->assertStringContainsString('<strong>36 <small>/ 40 cap</small></strong>', $html);
        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('dashboard-stat-card', $html);
        $this->assertStringNotContainsString('l16-tile', $html);
        $this->assertStringContainsString('90% of your weekly limit', $html);
        $this->assertStringContainsString('Sections You Lead', $html);

        // Filled segments across the week = her total; warm
        // segments = the days at/over the cap, computed from the
        // controller's own by_day (the mockup's Thu/Fri split was
        // illustrative — the real grid decides).
        $filled = substr_count($html, 'l16-seg blue') + substr_count($html, 'l16-seg warm');
        $this->assertSame(36, $filled);
        $capDay = (int) $response->viewData('cap_day');
        $expectedWarm = 0;
        foreach ((array) $response->viewData('by_day') as $c) {
            if ($c >= $capDay) { $expectedWarm += $c; }
        }
        $this->assertGreaterThan(0, $expectedWarm);
        $this->assertSame($expectedWarm, substr_count($html, 'l16-seg warm'));
        $this->assertStringContainsString('Full day', $html);
        $this->assertStringContainsString('Within cap', $html);

        // One periods-go row per by_subject group + the total footer.
        $groups = count((array) $response->viewData('by_subject'));
        $this->assertSame($groups, substr_count($html, 'l16-prow'));
        $this->assertStringContainsString('Total teaching load', $html);
        $this->assertStringContainsString('36 periods / week', $html);

        // Equal-height panels: both columns live in one stretch grid.
        $this->assertStringContainsString('l16-cols', $html);

        // The filled segments must survive dark mode: the dark
        // base rule for .l16-seg once out-specified .blue/.warm
        // and every segment rendered the same grey. The fills are
        // restated at winning specificity in the stylesheet.
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('html.hk-dark .l16-seg.blue{background:#2f7fe0}', $css);
        $this->assertStringContainsString('html.hk-dark .l16-seg.warm{background:#e85d3d}', $css);
    }

    public function test_unallotted_teacher_gets_tiles_and_the_honest_empty_state()
    {
        $new = User::factory()->create(['user_type' => 'teacher']);
        $html = $this->actingAs($new)->get(route('tt.workload'))->assertOk()->getContent();
        $this->assertStringContainsString('No periods are allotted to you yet', $html);
        $this->assertStringContainsString('<strong>0 <small>/ 40 cap</small></strong>', $html);
        $this->assertStringNotContainsString('l16-prow', $html);
    }
}
