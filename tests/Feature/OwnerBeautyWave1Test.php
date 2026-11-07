<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Beauty Wave 1 (2026-11-06, 50/50 phase):
 * (1) Guest shell unity — login / forgot / reset / verify /
 *     admission-enquiry share ONE guest design. History: the
 *     Login-A split card (brand panel + navy scrim) was rejected
 *     ("bhaut bekar" — the art drowned under the scrim); Sample C
 *     (campus art at full colour across the whole page, one card
 *     floating on it) was then rejected too (2026-11-07, "very
 *     bekar" — the picture shouted from every corner). The
 *     current design is the Calm Split (2026-11-07): the page is
 *     a quiet app backdrop with NO picture on it, and ONE centred
 *     card carries a softened in-card art panel (.login-b-art)
 *     beside the form panel, with the school brand (logo +
 *     Settings system name) at the panel top. The Sample C
 *     full-bleed drifting layer (.login-a-art) is retired.
 * (2) One motion layer — .hk-reveal entrance (fade + rise) with
 *     the .hk-reveal-1..3 stagger utilities, plus a gentle
 *     ken-burns drift on the role banner; both die under
 *     prefers-reduced-motion and in print.
 * (3) The slim module banner (partials/module_banner) replaces the
 *     bespoke headbars on the timetables index and marks selector,
 *     and gives the period-attendance index its first one — same
 *     titles, subtitles, stats and chips as before.
 * Presentation only throughout: no form, field, route or POST
 * behaviour changes anywhere in this wave.
 */
class OwnerBeautyWave1Test extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ---------- (2) motion layer: stylesheet pins ---------- */

    public function test_stylesheet_carries_the_reveal_primitive_and_stagger()
    {
        $css = $this->css();
        $this->assertStringContainsString('@keyframes hkRevealIn', $css);
        $this->assertStringContainsString(
            '.hk-reveal{animation:hkRevealIn .55s cubic-bezier(.22,.8,.32,1) both}', $css);
        $this->assertStringContainsString('.hk-reveal-1{animation-delay:.07s}', $css);
        $this->assertStringContainsString('.hk-reveal-2{animation-delay:.14s}', $css);
        $this->assertStringContainsString('.hk-reveal-3{animation-delay:.21s}', $css);
    }

    public function test_stylesheet_carries_the_banner_micro_motion()
    {
        $css = $this->css();
        $this->assertStringContainsString('@keyframes hkBannerDrift', $css);
        $this->assertStringContainsString(
            '.hk-banner-hero{animation:hkBannerDrift 26s ease-in-out infinite alternate}', $css);
    }

    public function test_motion_layer_honours_reduced_motion()
    {
        $css = $this->css();
        $pos = strpos($css, 'Beauty Wave 1');
        $this->assertNotFalse($pos, 'wave-1 block missing from qs.css');
        $wave = substr($css, $pos);
        $rm = strpos($wave, '@media (prefers-reduced-motion:reduce)');
        $this->assertNotFalse($rm, 'wave-1 reduced-motion guard missing');
        $block = substr($wave, $rm, 300);
        $this->assertStringContainsString('.hk-reveal', $block);
        $this->assertStringContainsString('.hk-banner-hero', $block);
        $this->assertStringContainsString('animation:none!important', $block);
    }

    public function test_stylesheet_carries_the_slim_module_banner()
    {
        $css = $this->css();
        $this->assertStringContainsString('.hk-mod-banner{', $css);
        $this->assertStringContainsString('.hk-mod-ic-blue{background:#e9f1ff;color:#3478f6}', $css);
        $this->assertStringContainsString('html.hk-dark .hk-mod-banner', $css);
    }

    /* ---------- (1) guest shell: rendered pages ---------- */

    public function test_login_renders_as_a_calm_split_page()
    {
        // Calm Split markup: page + centred card + in-card art
        // panel + card-top brand + legal line — and NO brand panel
        // and NO full-bleed art layer (both rejected designs).
        $this->get(route('login'))->assertOk()
            ->assertSee('login-a-card hk-reveal')
            ->assertSee('login-b-art')
            ->assertSee('login-a-school-line')
            ->assertSee('Welcome back')
            ->assertSee('login-a-legal')
            ->assertDontSee('login-a-art')
            ->assertDontSee('login-a-brand-inner')
            ->assertDontSee('login-a-brand-title')
            ->assertDontSee('login-a-campus')
            ->assertDontSee('The calm,');
    }

    public function test_forgot_password_renders_in_the_guest_shell()
    {
        $this->get(route('password.request'))->assertOk()
            ->assertSee('login-a-page')
            ->assertSee('login-b-art')
            ->assertSee('login-a-school-line')
            ->assertSee('login-a-legal')
            ->assertDontSee('login-a-brand-inner')
            // The form itself is the pre-wave one, untouched.
            ->assertSee('Password recovery')
            ->assertSee('name="email"', false);
    }

    public function test_reset_password_renders_in_the_guest_shell()
    {
        $this->get('/password/reset/wave1-token')->assertOk()
            ->assertSee('login-a-page')
            ->assertSee('login-b-art')
            ->assertSee('login-a-school-line')
            ->assertDontSee('login-a-brand-inner')
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('name="token"', false);
    }

    public function test_admission_enquiry_renders_in_the_guest_shell()
    {
        $this->get(route('admission_enquiry.form'))->assertOk()
            ->assertSee('login-a-page')
            ->assertSee('login-b-art')
            ->assertSee('login-a-school-line')
            ->assertSee('login-a-legal')
            ->assertDontSee('login-a-brand-inner')
            ->assertSee('Admission Enquiry')
            ->assertSee('Send Enquiry');
    }

    public function test_stylesheet_carries_the_calm_split_guest_design()
    {
        // RE-EXPRESSED 2026-11-07: this test pinned Sample C
        // literally (art as the full-page background at full
        // colour, card max-width:470px floating right, legal line
        // as a blurred dark pill over the art). The owner rejected
        // Sample C, so those assertions now pin the Calm Split
        // redesign instead. Append-only note: the Sample C rules
        // remain in qs.css ABOVE this block; the redesign block is
        // appended last and wins — the position assertions below
        // pin that ordering.
        $css = $this->css();
        $pos = strpos($css, 'Guest Calm Split');
        $this->assertNotFalse($pos, 'Calm Split block missing from qs.css');
        $this->assertGreaterThan(strpos($css, 'Guest Sample C'), $pos,
            'the redesign block must be appended after the Sample C block it restates');
        $block = substr($css, $pos);

        // The campus art survives ONLY as the in-card panel,
        // softened under a flat wash — never a page background.
        $this->assertStringContainsString('.login-b-art{', $block);
        $this->assertStringContainsString("url('../img/login-campus.jpg')", $block);
        $this->assertStringContainsString('background-size:cover', $block);

        // The Sample C full-bleed layer is retired, and the page
        // backdrop is themed in both directions (calm tones).
        $this->assertStringContainsString('.login-a-art{display:none}', $block);
        $this->assertStringContainsString('html:not(.hk-dark) .login-a-page', $block);
        $this->assertStringContainsString('html.hk-dark .login-a-page', $block);
        $this->assertStringNotContainsString('login-a-campus-scrim', $block);

        // No brand panel; one centred card at the split width.
        $this->assertStringContainsString('.login-a-brand{display:none}', $css);
        $this->assertStringContainsString('.login-a-card{', $block);
        $this->assertStringContainsString('max-width:960px', $block);

        // The legal line is plain text now — no pill background
        // (it only existed to stay readable over the full art).
        $this->assertStringContainsString('.login-a-legal{background:transparent', $block);

        // The school brandmark stays visible at phone widths:
        // an old identity-band rule hid it below 992px, taking
        // the school LOGO with it; the redesign restates it.
        $this->assertStringContainsString(
            '.login-a-panel .login-a-brandmark{display:flex}', $block);

        // Guest form header icons are normalised to a 56px circle.
        $this->assertStringContainsString(
            '.login-a-panel-guest .text-center>i[class*="icon-"]', $css);
        $this->assertStringContainsString('height:56px', $css);

        // The dark theme keeps its existing dark card colour.
        $this->assertStringContainsString(
            'html.hk-dark .login-a-card{background:#242527}', $css);
    }

    public function test_guest_pages_carry_the_hackstra_brand_pill()
    {
        // Owner feedback on Sample C (2026-11-06): the Hackstra
        // platform branding had vanished — the platform lockup
        // rides top-left on every guest page (over the calm
        // backdrop since the Calm Split redesign), from the
        // bundled platform asset (never the Settings logo).
        foreach ([route('login'), route('password.request'), route('admission_enquiry.form')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('hk-brand-pill')
                ->assertSee('images/hackstra-logo.svg')
                ->assertSee('login-b-art')
                ->assertDontSee('login-a-art');
        }
        $pill = file_get_contents(resource_path('views/partials/login/brand_pill.blade.php'));
        $this->assertStringContainsString('HACKSTRA', $pill);
        $this->assertStringContainsString('School Management System', $pill);
        $this->assertStringNotContainsString("getSetting('logo')", $pill);
        $this->assertStringNotContainsString('getLogo(', $pill);
    }

    public function test_stylesheet_carries_the_brand_pill_and_the_retired_drift()
    {
        // RE-EXPRESSED 2026-11-07: the art-drift half of this test
        // pinned the Sample C full-bleed layer's slow ken-burns
        // (.login-a-art + hkArtDrift). The Calm Split redesign
        // retires that layer, so the drift assertions are replaced
        // by the retirement pin below. The brand pill half is
        // unchanged — the pill survives the redesign untouched.
        $css = $this->css();
        $pos = strpos($css, 'Guest brand pill + art drift');
        $this->assertNotFalse($pos, 'brand pill block missing from qs.css');
        $block = substr($css, $pos);

        // The pill: translucent dark, blurred, rounded, top-left.
        $this->assertStringContainsString('.hk-brand-pill{', $block);
        $this->assertStringContainsString('background:rgba(8,24,40,.55)', $block);
        $this->assertStringContainsString('backdrop-filter:blur(6px)', $block);
        $this->assertStringContainsString('left:16px', $block);
        $this->assertStringContainsString('top:16px', $block);

        // Hover-only sheen on the primary submit.
        $this->assertStringContainsString('.login-a-btn:hover::after', $block);

        // The redesign block is appended AFTER the drift block and
        // retires the drifting layer outright (display:none), so
        // the old animation rules can never paint again; the pill
        // is restated above the centred card (z-index) there too.
        $calm = strpos($css, 'Guest Calm Split');
        $this->assertNotFalse($calm, 'Calm Split block missing from qs.css');
        $this->assertGreaterThan($pos, $calm);
        $calmBlock = substr($css, $calm);
        $this->assertStringContainsString('.login-a-art{display:none}', $calmBlock);
        $this->assertStringContainsString('.hk-brand-pill{z-index:3}', $calmBlock);
    }

    public function test_verify_page_uses_the_guest_shell()
    {
        // The notice route sits behind auth, so the shell is pinned
        // at the blade: open + close includes around the same card.
        $blade = file_get_contents(resource_path('views/auth/verify.blade.php'));
        $this->assertStringContainsString('partials.login.guest_shell_open', $blade);
        $this->assertStringContainsString('partials.login.guest_shell_close', $blade);
        $this->assertStringNotContainsString('page-content login-cover', $blade);
    }

    public function test_guest_pages_no_longer_reference_the_dead_campus_path()
    {
        // The campus art is the real file (a page background in
        // qs.css now); the never-existent ai/ path must not render
        // anywhere.
        foreach ([route('login'), route('password.request')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('img/ai/login-campus', $html);
        }
        $this->assertFileExists(public_path('assets/img/login-campus.jpg'));
    }

    /* ---------- (2) motion layer: dashboards carry the reveal ---------- */

    public function test_role_dashboards_carry_the_reveal()
    {
        foreach (['admin@hackstra.app', 'teacher@hackstra.app', 'student@hackstra.app',
                     'parent@hackstra.app', 'accountant@hackstra.app', 'librarian@hackstra.app',
                     'nurse@hackstra.app', 'principal@hackstra.app', 'superadmin@hackstra.app'] as $email) {
            $this->actingAs($this->user($email))->get(route('dashboard'))
                ->assertOk()
                ->assertSee('hk-reveal');
        }
    }

    /* ---------- (3) slim module banners ---------- */

    public function test_timetables_index_carries_the_slim_banner_with_its_stats()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('hk-mod-ic-blue', $html);
        $this->assertStringContainsString('View and manage class timetables', $html);
        $this->assertStringContainsString('Periods / Week', $html);
        // The phone-strip hide rule keys off this class — it must
        // stay on the banner's right side.
        $this->assertStringContainsString('tt-index-stats', $html);
    }

    public function test_marks_selector_carries_the_slim_banner()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('marks.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('Manage Exam Marks', $html);
        $this->assertStringContainsString(
            'Pick the exam, class, section and subject', $html);
        $this->assertStringContainsString(
            'Session ' . \App\Helpers\Qs::getCurrentSession(), $html);
    }

    public function test_period_attendance_index_carries_the_slim_banner()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('attendance.periods.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('hk-mod-ic-green', $html);
        $this->assertStringContainsString(
            'Take attendance for your assigned classes and periods', $html);
    }

    public function test_workload_page_opens_with_the_slim_banner_and_its_actions()
    {
        // Visual-coherence round (2026-11-06): the workload page's
        // private header slab becomes the shared banner; its two
        // links ride the banner's actions slot, unchanged.
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('tt.workload'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('hk-mod-ic-blue', $html);
        $this->assertStringContainsString('Workload Analysis', $html);
        $this->assertStringContainsString('caps: 8 periods a day, 40 a week (Settings)', $html);
        $this->assertStringContainsString('My Timetable', $html);
        $this->assertStringContainsString('My Extra Classes', $html);
        $this->assertStringNotContainsString('l16-head', $html);
    }
}
