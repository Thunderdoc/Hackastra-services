<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Beauty Batch 2 — ONE BUTTON SYSTEM (2026-11-07, audit group A).
 * One canonical primary (the --qs-primary-action token, the Login
 * Sign In family) on every primary action in both themes; the
 * located inconsistencies of the A group are pinned shut:
 *   A1 three blues · A2 timetables header hierarchy · A3 disabled
 *   danger reads grey · A4 users pills retired · A5 Submit is blue
 *   · A6 duplicate Back controls · A7 parent fee CTA contrast ·
 *   A8 selector renders its disabled "Not Enabled" state · A9
 *   marks strip wraps at 360px · A10 health create placement ·
 *   A12 library actions on one row.
 * Presentation only — labels, hrefs, form actions and POST
 * contracts are untouched everywhere below.
 */
class OwnerBeautyButtonSystemTest extends TestCase
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

    /* ---------- A1: one canonical primary ---------- */

    public function test_primary_button_is_the_token_everywhere_not_just_in_content()
    {
        $css = $this->css();

        // The token itself is the Login family blue.
        $this->assertStringContainsString('--qs-primary-action:#2867dc', $css);
        // Global (not .content-scoped): page-header actions and the
        // guest shell no longer fall back to the vendor sky #2196f3.
        $this->assertStringContainsString(
            '.btn-primary{background:var(--qs-primary-action);border-color:var(--qs-primary-action);color:#fff}',
            $css);
        $this->assertStringContainsString(
            '.btn-primary:hover,.btn-primary:focus{background:#1f56bd;border-color:#1f56bd;color:#fff}',
            $css);
        // The in-content hover is restated to the same deeper tone.
        $this->assertStringContainsString(
            '.content .btn-primary:hover,.content .btn-primary:focus{background:#1f56bd;border-color:#1f56bd}',
            $css);
    }

    public function test_guest_submits_share_the_primary_family()
    {
        // Forgot password was the bright-cyan outlier (bg-blue).
        $forgot = $this->bladeFile('auth/passwords/email.blade.php');
        $this->assertStringContainsString('btn btn-primary btn-block', $forgot);
        $this->assertStringNotContainsString('bg-blue', $forgot);

        // The (closed) register page carried the same outlier class.
        $this->assertStringNotContainsString('bg-blue', $this->bladeFile('auth/register.blade.php'));

        // Admission enquiry already used btn-primary — the global
        // rule above is what unifies its colour with Login's.
        $this->assertStringContainsString(
            'btn btn-primary btn-block',
            $this->bladeFile('pages/other/admission_enquiry.blade.php'));
    }

    /* ---------- A2: timetables header hierarchy ---------- */

    public function test_timetables_header_has_one_primary_and_two_quiet_secondaries()
    {
        $blade = $this->bladeFile('pages/support_team/timetables/index.blade.php');

        $this->assertStringContainsString(
            'route(\'tt.generate.form\') }}" class="btn btn-primary"><i class="icon-magic-wand mr-1"></i> Auto-Generate',
            $blade);
        $this->assertStringContainsString(
            'href="#tt-create" class="btn btn-light"><i class="icon-plus2 mr-1"></i> Create Timetable',
            $blade);
        $this->assertStringContainsString(
            'route(\'rooms.index\') }}" class="btn btn-light"><i class="icon-home4 mr-1"></i> Rooms',
            $blade);
    }

    /* ---------- A3: disabled danger reads as disabled ---------- */

    public function test_disabled_danger_is_neutral_grey_in_both_themes()
    {
        $css = $this->css();

        $this->assertStringContainsString(
            '.btn-danger:disabled,.btn-danger.disabled{background:#d9dee4;border-color:#d9dee4;color:#7c8794;opacity:1}',
            $css);
        $this->assertStringContainsString(
            'html.hk-dark .btn-danger:disabled,html.hk-dark .btn-danger.disabled{background:#33363b;border-color:#33363b;color:#7b7f87}',
            $css);
    }

    /* ---------- A4: users pills retired ---------- */

    public function test_users_buttons_use_the_app_radius_padding_and_token()
    {
        $css = $this->css();

        $override = '.l106-users .pat-list .btn{border-radius:var(--qs-radius);padding:.4375rem .875rem}';
        $this->assertStringContainsString($override, $css);
        $this->assertStringContainsString(
            '.l106-users .pat-list .btn-primary{background:var(--qs-primary-action);border-color:var(--qs-primary-action)}',
            $css);
        // Append-only discipline: the override lands AFTER the
        // original pill rule it restates.
        $original = '.l106-users .pat-list .btn{border-radius:999px;font-weight:600;padding:8px 18px}';
        $this->assertStringContainsString($original, $css);
        $this->assertGreaterThan(strpos($css, $original), strpos($css, $override));
    }

    /* ---------- A5: Submit is blue ---------- */

    public function test_submit_verbs_are_primary_blue_not_green()
    {
        $this->assertStringContainsString(
            'btn btn-primary">Submit Request',
            $this->bladeFile('pages/support_team/leave/my.blade.php'));
        $this->assertStringContainsString(
            'btn btn-primary btn-sm">Submit Marks',
            $this->bladeFile('pages/support_team/marks/manage.blade.php'));
        $this->assertStringContainsString(
            'btn btn-primary">Submit form',
            $this->bladeFile('pages/support_team/timetables/time_slots/add.blade.php'));
    }

    /* ---------- A6: duplicate Back controls removed ---------- */

    public function test_id_card_pages_keep_only_their_primary_action()
    {
        // The site header already carries the Back control; the
        // in-card duplicate went away on both ID card pages.
        $this->assertStringNotContainsString(
            'Back to Profile',
            $this->bladeFile('pages/support_team/students/id_card.blade.php'));
        $this->assertStringNotContainsString(
            'Back to Profile',
            $this->bladeFile('pages/support_team/users/id_card.blade.php'));
        // Class Overview's page_actions repeated the header Back
        // to the same dashboard target — section removed.
        $this->assertStringNotContainsString(
            'Back to Dashboard',
            $this->bladeFile('pages/support_team/class_overview/index.blade.php'));
        // The marks workspace Back is NOT a duplicate: the
        // back-button doctrine pins it (OwnerBackButtonDoctrineTest)
        // and it stands.
        $this->assertStringContainsString(
            'Back to Marks',
            $this->bladeFile('pages/support_team/marks/edit.blade.php'));
    }

    /* ---------- A7: parent fee CTAs keep white labels in dark ---------- */

    public function test_filled_anchor_buttons_keep_white_text_in_dark_mode()
    {
        $this->assertStringContainsString(
            'html.hk-dark .content a.p96-take-btn,html.hk-dark .content a.p96-pay-btn,html.hk-dark .content a.dashboard-primary-action,html.hk-dark .content a.acct-btn-primary{color:#fff}',
            $this->css());
    }

    /* ---------- A8: selector renders its disabled state ---------- */

    public function test_marks_selector_button_is_server_rendered_disabled_not_enabled()
    {
        $blade = $this->bladeFile('pages/support_team/marks/selector.blade.php');
        $this->assertStringContainsString(
            'id="l84-manage-btn" class="btn btn-primary l10-manage-btn" disabled',
            $blade);
        $this->assertStringContainsString(
            '<span id="l84-manage-btn-label">Not Enabled</span>',
            $blade);

        // The page script keeps the same resting label until a
        // complete unit checks out enabled.
        $this->assertStringContainsString(
            '$label.text(\'Not Enabled\');',
            $this->bladeFile('pages/support_team/marks/index.blade.php'));

        // And the served page shows it from first paint.
        $res = $this->actingAs($this->user('teacher@hackstra.app'))->get(route('marks.index'));
        $res->assertOk();
        $res->assertSee('id="l84-manage-btn" class="btn btn-primary l10-manage-btn" disabled', false);
        $res->assertSee('<span id="l84-manage-btn-label">Not Enabled</span>', false);
    }

    /* ---------- A9: marks strip wraps at phone width ---------- */

    public function test_marks_status_strip_stacks_its_action_full_width_below_480px()
    {
        $css = $this->css();

        $this->assertStringContainsString('@media (max-width:480px){', $css);
        $this->assertStringContainsString(
            '.l11-strip form{flex:1 1 100%;margin-left:0!important}',
            $css);
        $this->assertStringContainsString('.l11-strip form .btn{width:100%}', $css);
    }

    /* ---------- A10: health create takes the standard placement ---------- */

    public function test_health_records_create_button_sits_in_the_page_header_at_standard_size()
    {
        $blade = $this->bladeFile('pages/support_team/health_records/index.blade.php');

        $this->assertStringContainsString("@section('page_actions')", $blade);
        $this->assertStringContainsString(
            'route(\'health_records.create\') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Health Record',
            $blade);
        $this->assertStringNotContainsString('btn-sm">New Health Record', $blade);

        $res = $this->actingAs($this->user('nurse@hackstra.app'))->get(route('health_records.index'));
        $res->assertOk();
        $res->assertSee('New Health Record', false);
        $res->assertDontSee('btn btn-primary btn-sm">New Health Record', false);
    }

    /* ---------- A11: one control height on the bulk form row ---------- */

    public function test_bulk_id_card_selects_share_the_button_height()
    {
        // Measured before the fix: Select2 selections 36px, the
        // Preview Roster button 40px (the app .btn minimum). The
        // page's selections are restated to the button's 40px.
        $blade = $this->bladeFile('pages/support_team/students/id_card_bulk.blade.php');

        $this->assertStringContainsString('class="idc-bulk-form"', $blade);
        $this->assertStringContainsString(
            '.idc-bulk-form .select2-container .select2-selection--single{height:40px}',
            $blade);
    }

    /* ---------- A12: library actions on one row ---------- */

    public function test_library_row_actions_form_a_single_row_set()
    {
        $this->assertStringContainsString(
            'qs-row-actions',
            $this->bladeFile('pages/support_team/books/index.blade.php'));
        $this->assertStringContainsString(
            '.qs-row-actions{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}',
            $this->css());
    }
}
