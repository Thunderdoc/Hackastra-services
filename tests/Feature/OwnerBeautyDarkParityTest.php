<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Beauty Batch 1 — dark-parity sweep (2026-11-07, audit group G):
 * the "half white" disease in its remaining pockets. Every fix is
 * dark-scoped (html.hk-dark); each test below pins BOTH halves of
 * the contract — the dark rule exists AND the light rule it
 * overrides still stands verbatim (light mode is owner-approved
 * separately and must not move).
 *   G1 builder rb5 chrome · G2 slot-preference Pick boxes/chips ·
 *   G3 settings alert-light · G4 hk_date popup · G5 library book
 *   titles · G6 backup page · G8 class-overview chips · G9 the
 *   .table-responsive #ddd top border under card titles.
 */
class OwnerBeautyDarkParityTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function bladeFile(string $path): string
    {
        return file_get_contents(resource_path('views/' . $path));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ---------- G1: Report Builder ---------- */

    public function test_builder_chrome_has_a_dark_block_and_its_light_design_stands()
    {
        $blade = $this->bladeFile('pages/support_team/reports/builder.blade.php');

        // Dark: cards, column items, docbar + workspace desk take
        // the app dark tokens; the paper (#previewFrame) is never
        // recolored — it stays out of the dark block entirely.
        $this->assertStringContainsString(
            'html.hk-dark .rb5-left .card { background: #26272a; border-color: #36383c;', $blade);
        $this->assertStringContainsString(
            'html.hk-dark .rb5-col-item { background: #202124; border-color: #36383c; }', $blade);
        $this->assertStringContainsString(
            'html.hk-dark .rb5-docbar { background: #26272a; border-color: #36383c; }', $blade);
        $this->assertStringContainsString(
            'html.hk-dark .rb2-col-field label { color: #a5a8ad; }', $blade);
        $this->assertStringNotContainsString('html.hk-dark #previewFrame', $blade);
        $this->assertStringNotContainsString('html.hk-dark .rb5-workspace { background: #fff', $blade);

        // Light: the rb5 base design stands verbatim.
        $this->assertStringContainsString(
            '.rb5-col-item { border: 1px solid #dfe8f3; border-radius: 9px; padding: 8px 10px; margin-bottom: 8px; background: #fbfcfe; }',
            $blade);
        $this->assertStringContainsString(
            '.rb5-left .card { background: #fff;', $blade);
    }

    /* ---------- G2: Slot Preferences ---------- */

    public function test_slot_preferences_boxes_and_chips_have_dark_variants()
    {
        $blade = $this->bladeFile('pages/support_team/timetables/slot_preferences.blade.php');

        $this->assertStringContainsString(
            'html.hk-dark .sp-chip{background:rgba(52,120,246,.14);color:#9ec2ff;border-color:rgba(52,120,246,.35)}',
            $blade);
        $this->assertStringContainsString(
            'html.hk-dark .sp-cell .sp-box{background:#1e1f21;border-color:#3e4044;color:#a5a8ad}',
            $blade);
        // Hover never un-blues a chosen slot.
        $this->assertStringContainsString(
            'html.hk-dark .sp-cell:hover .sp-in:not(:checked) + .sp-box{border-color:#3478f6;color:#9ec2ff}',
            $blade);

        // Light: base chip/box + the blue selected state stand.
        $this->assertStringContainsString(
            '.sp-chip{display:inline-flex;align-items:center;gap:6px;background:#eef4ff;color:#2e5ea8;',
            $blade);
        $this->assertStringContainsString(
            '.sp-cell .sp-in:checked + .sp-box{background:#2e6be6;border-color:#2e6be6;color:#fff;',
            $blade);
    }

    /* ---------- G3 + G4 + G5 + G8 + G9: the qs.css append ---------- */

    public function test_settings_open_banner_alert_light_has_a_dark_variant()
    {
        // settings.blade.php:43 is the app's only alert-light.
        $this->assertStringContainsString(
            'html.hk-dark .alert-light{background:#2d2e31;border-color:#404246!important;color:#d7dade}',
            $this->css());
        $settings = $this->bladeFile('pages/super_admin/settings.blade.php');
        $this->assertStringContainsString('alert alert-light border', $settings);
    }

    public function test_hk_date_popup_has_a_dark_variant_and_its_light_base_stands()
    {
        $css = $this->css();
        $this->assertStringContainsString(
            'html.hk-dark .hkdp-pop{background:#26272a;color:#e7e8e9;border-color:#36383c;', $css);
        $this->assertStringContainsString(
            'html.hk-dark .hkdp-day{color:#e7e8e9}', $css);
        $this->assertStringContainsString(
            'html.hk-dark .hkdp-nav{background:#2f3135;color:#7db3ff}', $css);

        // Light: the partial's own white popup is untouched.
        $partial = $this->bladeFile('partials/hk_date.blade.php');
        $this->assertStringContainsString(
            '.hkdp-pop { position: fixed; z-index: 2050; background: #fff; color: #10263b;',
            $partial);
    }

    public function test_library_book_titles_are_readable_in_dark()
    {
        $css = $this->css();
        $this->assertStringContainsString(
            'html.hk-dark .qs-book-cell strong{color:#f4f4f5}', $css);
        // The light navy stays for light mode.
        $this->assertStringContainsString('.qs-book-cell strong{color:#172536}', $css);
    }

    public function test_class_overview_chips_have_dark_tints()
    {
        $css = $this->css();
        $this->assertStringContainsString(
            'html.hk-dark .l12-subj{background:rgba(124,93,250,.20);color:#c9b3f7}', $css);
        $this->assertStringContainsString(
            'html.hk-dark .l12-risk-zero{background:#2f3135;color:#a5a8ad}', $css);
        $this->assertStringContainsString(
            'html.hk-dark .l12-risk-on{background:rgba(214,69,69,.18);color:#f2a9a9}', $css);
        // Light pills stand.
        $this->assertStringContainsString('.l12-risk-zero{background:#eef1f5;color:#5b6b7f}', $css);
        $this->assertStringContainsString('.l12-subj{background:#f2eafd;', $css);
    }

    public function test_card_table_top_border_uses_the_dark_divider_colour()
    {
        // G9: Limitless' sibling rule
        // (.card-header:not([class*=bg-]) + div[class*=table-responsive],
        // border-top:1px solid #ddd at specificity (0,3,1)) is the
        // bright line under the nurse home's Recent Visits title.
        // A plain .table-responsive override (0,2,1) loses to it,
        // so the fix mirrors the vendor selector shapes.
        $css = $this->css();
        $this->assertStringContainsString(
            'html.hk-dark .card-header:not([class*=bg-]) + div[class*=table-responsive]', $css);
        $this->assertStringContainsString(
            'html.hk-dark .card-footer + [class*=table-responsive]{border-top-color:#36383c}', $css);
    }

    /* ---------- G6: Backup page ---------- */

    public function test_backup_page_cards_and_note_have_dark_variants()
    {
        $blade = $this->bladeFile('pages/support_team/backup/index.blade.php');
        $this->assertStringContainsString(
            'html.hk-dark .bk-card { background: #26272a; border-color: #36383c; color: #e7e8e9; }',
            $blade);
        $this->assertStringContainsString(
            'html.hk-dark .bk-note { background: #3a3016; border-color: #5a4a1e; color: #f0dfa8; }',
            $blade);
        // Light stands.
        $this->assertStringContainsString('.bk-card { background: #fff;', $blade);
        $this->assertStringContainsString('.bk-note { background: #fff8e1;', $blade);
    }

    /* ---------- Smoke: the four pages still render ---------- */

    public function test_the_batch_pages_render_for_their_roles()
    {
        $this->actingAs($this->user('admin@hackstra.app'))
            ->get('/reports')->assertOk()->assertSee('rb5-col-item', false);
        $this->actingAs($this->user('teacher@hackstra.app'))
            ->get('/timetables/slot-preferences')->assertOk()->assertSee('sp-box', false);
        $this->actingAs($this->user('admin@hackstra.app'))
            ->get('/super_admin/settings')->assertOk()->assertSee('alert alert-light border', false);
        $this->actingAs($this->user('librarian@hackstra.app'))
            ->get('/books')->assertOk();
    }
}
