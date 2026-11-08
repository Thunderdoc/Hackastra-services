<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 5 Slice 3, P18 — shared small-phone tier + fit sweep.
 *
 *  - css-p18.fragment.css carries the ONE shared tier the audit
 *    §18 design specifies: a single @media (max-width:374px)
 *    block (qs.css's ladder previously stopped at 480/479px) —
 *    content padding 10px, card-body 12px, .pat-toolbar /
 *    action rows wrap full-width, and Batch 5's stat-card
 *    compact rules (38px icon, clamp(.88rem,4.1vw,1.5rem) +
 *    nowrap values) generalised from the two money grids to
 *    every .dashboard-stat-copy and the t9/t10/p96 KPI copies.
 *    The coordinator has appended the fragment to qs.css; the
 *    pins below extract that block from the shipped stylesheet.
 *  - The body{overflow-x:clip} safety net is deliberately NOT
 *    in the tier block: it landed separately, after the fit
 *    sweep, as defence-in-depth only (audit §18 design).
 *  - principal/home's four .pr-table approval tables ride the
 *    accepted scrollport pattern: each is wrapped in a
 *    .table-responsive div (blade-only, no table internals
 *    touched).
 *
 * Fit evidence: hk-tmp/round5/build/p18-fit-sweep.md — 15
 * pages × 360/320px, scrollWidth == innerWidth before and
 * after; computed content padding 22px -> 10px and card-body
 * 16px -> 12px once the tier applies (qs.css:793's late base
 * rule otherwise wins over the <=767/<=480 tightenings).
 *
 * Users resolve by email, never hardcoded ids.
 */
class OwnerRound5PhoneTierTest extends TestCase
{
    use DatabaseTransactions;

    protected function principal(): User
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function stylesheet(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    /**
     * The P18 tier as it ships. The tier was authored as a
     * build-time fragment under hk-tmp/ — a scratch path that
     * only ever existed on the author's machine, so these pins
     * errored in CI. The fragment has been appended to qs.css;
     * extract the ONE @media (max-width:374px) block from the
     * shipped stylesheet (balanced braces) and pin against it.
     */
    protected function fragment(): string
    {
        $css = $this->stylesheet();
        $this->assertSame(1, substr_count($css, '@media (max-width:374px)'),
            'qs.css must carry exactly one 374px tier block');
        $start = strpos($css, '@media (max-width:374px)');
        $depth = 0;
        $i = strpos($css, '{', $start);
        do {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $i++;
        } while ($depth > 0);

        return substr($css, $start, $i - $start);
    }

    protected function principalBlade(): string
    {
        return file_get_contents(resource_path('views/pages/principal/home.blade.php'));
    }

    /* ---------------- the 374px tier ---------------- */

    public function test_fragment_is_a_single_374px_media_block_with_no_base_rules()
    {
        // fragment() asserts qs.css carries exactly one such block.
        $block = $this->fragment();
        $this->assertSame(1, substr_count($block, '@media'), 'the tier must be exactly one media block');
        $this->assertStringContainsString('@media (max-width:374px){', $block);
        $this->assertStringStartsWith('@media (max-width:374px)', $block);

        // No base rules: the tier's signature rules must not also
        // live outside the block — a base copy would apply at every
        // width and silently defeat the tier (the old fragment pin
        // got this from the fragment being one bare block).
        $outside = str_replace($block, '', $this->stylesheet());
        $this->assertStringNotContainsString('.content-wrapper>.content{padding:10px}', $outside);
        $this->assertStringNotContainsString('.pat-toolbar{align-items:stretch;flex-direction:column;gap:8px}', $outside);
    }

    public function test_fragment_tightens_content_and_card_padding()
    {
        $css = $this->fragment();
        $this->assertStringContainsString('.content-wrapper>.content{padding:10px}', $css);
        $this->assertStringContainsString('.content .card-body{padding:12px}', $css);
    }

    public function test_fragment_wraps_toolbar_and_action_rows_full_width()
    {
        $css = $this->fragment();
        $this->assertStringContainsString('.pat-toolbar{align-items:stretch;flex-direction:column;gap:8px}', $css);
        $this->assertStringContainsString('.pat-toolbar-actions{width:100%}', $css);
        $this->assertStringContainsString('.pat-toolbar-actions .btn{flex:1 1 auto}', $css);
        $this->assertStringContainsString('.btn-group{flex-wrap:wrap}', $css);
    }

    public function test_fragment_generalises_batch5_stat_compact_rules()
    {
        $css = $this->fragment();
        // Batch 5's @575 card/icon shapes, now in the shared tier.
        $this->assertStringContainsString('.dashboard-stat-card{gap:8px;', $css);
        $this->assertStringContainsString('.dashboard-stat-icon{font-size:16px;height:38px;width:38px}', $css);
        // Batch 5's @767 money rule (fluid clamp + nowrap),
        // generalised from .acc-stat-grid/.dashboard-stats to
        // every stat copy and the t9/t10/p96 KPI copies.
        $this->assertStringContainsString(
            '.dashboard-stat-copy strong{font-size:clamp(.88rem,4.1vw,1.5rem);letter-spacing:-.01em;line-height:1.2;white-space:nowrap}',
            $css
        );
        $this->assertStringContainsString('.t9-kpi-copy strong,.t10-kpi-copy strong,.p96-kpi-copy strong{', $css);
        $this->assertStringContainsString('white-space:nowrap', $css);
    }

    public function test_fragment_does_not_carry_the_overflow_safety_net()
    {
        // body{overflow-x:clip} lands separately, later, by
        // design — the fragment must not pre-empt it (it would
        // mask wide elements instead of fixing them).
        $css = $this->fragment();
        $this->assertStringNotContainsString('overflow-x', $css);
        $this->assertStringNotContainsString('overflow:hidden', $css);
    }

    /* ---------------- principal/home scrollports ---------------- */

    public function test_principal_home_wraps_all_four_pr_tables_in_table_responsive()
    {
        $blade = $this->principalBlade();
        $this->assertSame(4, substr_count($blade, '<table class="pr-table"'));
        $this->assertSame(
            4,
            preg_match_all('/<div class="table-responsive">\s*<table class="pr-table"/', $blade),
            'every .pr-table must sit directly inside a .table-responsive scrollport'
        );
    }

    public function test_principal_home_renders_ok_for_principal()
    {
        $response = $this->actingAs($this->principal())->get(route('home'));
        $response->assertOk();
        $response->assertSee('School Command Center');
    }
}
