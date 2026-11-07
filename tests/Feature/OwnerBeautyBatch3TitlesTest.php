<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Beauty Batch 3 (2026-11-07, audit B group — titles, headers &
 * layout spacing):
 * (1) ONE title per page. The shared page header owns the title
 *     (settled Loop 46 doctrine — see partials/header.blade.php);
 *     the slim module banner no longer repeats it, and the worst
 *     in-page doubles (marks selector ×3 counting the card head,
 *     health records ×3, generate's private h2, slot preferences'
 *     in-card h5, the audit log's private .au-head hero) are gone.
 *     These pins count the header's title span
 *     (<span class="font-weight-semibold">…</span>) — it must
 *     appear exactly once — and require the banner's h2 markup
 *     (hk-mod-title) to be absent on the de-duplicated pages.
 * (2) The spacing half lives in qs.css (append-only Batch 3
 *     block): link-wrapped stat tiles fill their columns (B2),
 *     KPI ladders stop orphaning tiles at 1280 (B3), phone tile
 *     rows go 2-up (B4), settings sections land below the sticky
 *     chips bar (B11). Pinned as stylesheet strings below.
 * (3) Timetables index class cards cap their section chips at
 *     one row (6 + "+N more") so a 13-section class no longer
 *     towers over its row (B13).
 * (4) The marks manage action bar names the current unit beside
 *     its Change button instead of floating it in a dead band (B8).
 * Presentation only throughout: no form, field, route or POST
 * behaviour changes anywhere in this batch.
 */
class OwnerBeautyBatch3TitlesTest extends TestCase
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

    private function headerTitleCount(string $html, string $title): int
    {
        return substr_count($html, '<span class="font-weight-semibold">' . $title . '</span>');
    }

    /* ---------- (1) one title per page ---------- */

    public function test_marks_selector_renders_its_title_exactly_once()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('marks.index'))->assertOk()->getContent();

        $this->assertSame(1, $this->headerTitleCount($html, 'Manage Exam Marks'));
        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertSame(0, substr_count($html, 'hk-mod-title'));
    }

    public function test_health_records_renders_its_title_exactly_once()
    {
        $html = $this->actingAs($this->user('nurse@hackstra.app'))
            ->get(route('health_records.index'))->assertOk()->getContent();

        $this->assertSame(1, $this->headerTitleCount($html, 'Health Records'));
        $this->assertSame(0, substr_count($html, 'hk-mod-title'));
        // The card's own third repetition of the title is gone.
        $this->assertSame(0, substr_count($html, '<h5 class="card-title">Health Records</h5>'));
    }

    public function test_timetables_index_renders_its_title_exactly_once()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.index'))->assertOk()->getContent();

        $this->assertSame(1, $this->headerTitleCount($html, 'Manage Timetables'));
        $this->assertSame(0, substr_count($html, 'hk-mod-title'));
    }

    public function test_workload_renders_its_title_exactly_once()
    {
        // tt.workload is the teacher's own workload page
        // (WorkloadController@mine) — admins are refused by design.
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('tt.workload'))->assertOk()->getContent();

        $this->assertSame(1, $this->headerTitleCount($html, 'Workload Analysis'));
        $this->assertSame(0, substr_count($html, 'hk-mod-title'));
    }

    public function test_generate_renders_its_title_exactly_once()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.generate.form'))->assertOk()->getContent();

        $this->assertSame(1, $this->headerTitleCount($html, 'Auto-Generate Timetables'));
        // The .gen-head band's private h2 duplicate is gone; its
        // one-line brief stays.
        $this->assertSame(0, substr_count($html, '<h2>Auto-Generate Timetables</h2>'));
        $this->assertStringContainsString('Tell the system how your school day runs', $html);
    }

    public function test_slot_preferences_renders_its_title_exactly_once()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('tt.slot_preferences'))->assertOk()->getContent();

        $this->assertSame(1, $this->headerTitleCount($html, 'My Slot Preferences'));
        $this->assertSame(0, substr_count($html, '<h5>My Slot Preferences</h5>'));
    }

    public function test_audit_log_uses_the_shared_banner_and_one_title()
    {
        $html = $this->actingAs($this->user('superadmin@hackstra.app'))
            ->get(route('audit_logs.index'))->assertOk()->getContent();

        $this->assertSame(1, $this->headerTitleCount($html, 'Audit Log'));
        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertSame(0, substr_count($html, 'card au-head'));
        $this->assertStringContainsString('Export CSV', $html);
    }

    public function test_search_results_has_a_single_title_surface()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('dashboard.search', ['q' => 'Aarav']))->assertOk()->getContent();

        // The page's own hero carries the meaningful heading; the
        // shared header band (and its generic "Search" title) is
        // hidden via the hide_page_header opt-out.
        $this->assertStringContainsString('Results for', $html);
        $this->assertSame(0, $this->headerTitleCount($html, 'Search'));
    }

    /* ---------- (3) timetables chip cap ---------- */

    public function test_timetables_index_caps_section_chips_at_one_row()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.index'))->assertOk()->getContent();

        // Class 12 carries 13 sections in the seed: its grid card
        // shows 6 chips + the overflow marker, never 13 chips.
        $this->assertStringContainsString('ttc-chip ttc-chip-more', $html);
        $this->assertStringContainsString('+7 more', $html);
    }

    /* ---------- (4) marks manage action bar ---------- */

    public function test_marks_manage_action_bar_names_the_current_unit()
    {
        // Ananya's fixture unit: exam 1 / Class 12 / section 76 /
        // subject 79 (English Language).
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('marks.manage', [1, 13, 76, 79]))->assertOk()->getContent();

        $this->assertStringContainsString('lb3-unit', $html);
        $this->assertStringContainsString('English Language', $html);
        $this->assertStringContainsString('l115-change-btn', $html);
    }

    /* ---------- (2) stylesheet pins ---------- */

    public function test_stylesheet_carries_the_batch3_spacing_rules()
    {
        $css = $this->css();

        // B2 — link-wrapped stat tiles fill their flex columns.
        $this->assertStringContainsString('.dashboard-stats .l100-stat-link{flex:1 1 auto;width:100%}', $css);
        // B3 — teacher KPI stays 4-across at ≤1399; the 5-tile
        // grids rebalance on a 6-track grid.
        $this->assertStringContainsString('.t10-kpi-grid{grid-template-columns:repeat(4,minmax(0,1fr))}', $css);
        $this->assertStringContainsString('.p96-kpi-grid>*:nth-child(5)', $css);
        // B4 — phone tile rows go 2-up below the sm breakpoint.
        $this->assertStringContainsString('.dashboard-stats>[class*=col]{flex:0 0 50%;max-width:50%}', $css);
        // B5 — panel header links never wrap; the events panel
        // sizes to its content.
        $this->assertStringContainsString('.dashboard-view-all{white-space:nowrap;flex-shrink:0}', $css);
        $this->assertStringContainsString('.dashboard-upcoming.l17-grow{flex:0 1 auto}', $css);
        // B6 — SA home lists are not clipped at an arbitrary cap.
        $this->assertStringContainsString('.l106-platform .l17-list-scroll{max-height:none}', $css);
        // B9 — the lone teacher-home quote becomes a full-width strip.
        $this->assertStringContainsString('.t10-welcome .t10-quote{flex:1 1 auto;flex-direction:row;align-items:center}', $css);
        // B11 — settings sections land below the sticky chips bar.
        $this->assertStringContainsString('.pat-form-section[id^="set-"]{scroll-margin-top:185px}', $css);
    }
}
