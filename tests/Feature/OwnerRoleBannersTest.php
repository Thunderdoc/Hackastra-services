<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Role dashboard header banners (owner order 2026-11-06): every
 * role home carries ONE shared banner strip (.hk-banner-hero) with
 * a different, role-meaningful AI illustration per role. Pins: the
 * strip renders on each role's dashboard, the shared styling and
 * per-role classes exist in qs.css, and every image file is real.
 */
class OwnerRoleBannersTest extends TestCase
{
    public function test_each_role_dashboard_carries_its_banner()
    {
        $map = [
            'admin@hackstra.app' => 'hk-bn-admin',
            'principal@hackstra.app' => 'hk-bn-principal',
            'teacher@hackstra.app' => 'hk-bn-teacher',
            'student@hackstra.app' => 'hk-bn-student',
            'parent@hackstra.app' => 'hk-bn-parent',
            'accountant@hackstra.app' => 'hk-bn-accountant',
            'librarian@hackstra.app' => 'hk-bn-librarian',
            'nurse@hackstra.app' => 'hk-bn-nurse',
            'superadmin@hackstra.app' => 'hk-bn-superadmin',
        ];

        foreach ($map as $email => $class) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('hk-banner-hero')
                ->assertSee($class);
        }
    }

    public function test_banner_images_and_styles_exist()
    {
        // Beauty Wave 2: the Hostel Warden's banner joins the family
        // as the 10th image + class (its dashboard render is pinned
        // in OwnerBeautyWave2Test — the demo seed has no warden
        // login, so the role's map above cannot carry it).
        foreach (['admin', 'principal', 'teacher', 'student', 'parent', 'accountant', 'librarian', 'nurse', 'superadmin', 'hostel'] as $role) {
            $path = public_path('assets/img/banners/role-' . $role . '.jpg');
            $this->assertFileExists($path, 'missing banner image for ' . $role);
            $this->assertGreaterThan(50000, filesize($path), 'banner image for ' . $role . ' looks empty');
        }

        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('.hk-banner-hero', $css);
        $this->assertStringContainsString('.hk-bn-teacher', $css);
        $this->assertStringContainsString('.hk-bn-nurse', $css);
        $this->assertStringContainsString('.hk-bn-hostel', $css);
    }

    public function test_banner_melts_into_the_dark_theme()
    {
        // Owner fix 2026-11-07: in dark mode the light banner art
        // read as a pasted white slab ("half white aur half
        // pathetic"). The appended block dims the art on its own
        // ::before layer, veils it dark, flips the greeting light
        // and gives the strip the dark-card border — dark only.
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $pos = strpos($css, 'Role banner: dark-mode melt');
        $this->assertNotFalse($pos, 'dark-mode melt block missing from qs.css');
        $block = substr($css, $pos);

        // The edge reads as a designed dark card, not a slab.
        $this->assertStringContainsString(
            'html.hk-dark .hk-banner-hero{border-color:#36383c}', $block);

        // The art replays on a filtered layer (same per-role image
        // via inherit) and keeps the drift micro-motion.
        $this->assertStringContainsString('html.hk-dark .hk-banner-hero::before', $block);
        $this->assertStringContainsString('background-image:inherit', $block);
        $this->assertStringContainsString('filter:brightness(.82) saturate(.92)', $block);
        $this->assertStringContainsString(
            'animation:hkBannerDrift 26s ease-in-out infinite alternate', $block);

        // The overlay becomes a dark veil, strongest at the left.
        $this->assertStringContainsString(
            'linear-gradient(90deg,rgba(7,10,15,.62),rgba(7,10,15,.18))', $block);

        // The greeting flips light; the sub-line goes light muted.
        $this->assertStringContainsString(
            'html.hk-dark .hk-bn-greet{color:#f5f7fa', $block);
        $this->assertStringContainsString(
            'html.hk-dark .hk-bn-sub{color:#c3ccd6', $block);

        // The layer's motion dies under reduced motion, like the
        // hero's own drift guard.
        $rm = strpos($block, '@media (prefers-reduced-motion:reduce)');
        $this->assertNotFalse($rm, 'dark banner reduced-motion guard missing');
        $guard = substr($block, $rm, 200);
        $this->assertStringContainsString('html.hk-dark .hk-banner-hero::before', $guard);
        $this->assertStringContainsString('animation:none!important', $guard);

        // The light theme is untouched: the original white veil
        // and navy greeting rules still stand as written.
        $this->assertStringContainsString(
            'background:linear-gradient(90deg,rgba(255,255,255,.85),rgba(255,255,255,0))', $css);
        $this->assertStringContainsString(
            '.hk-bn-greet{font-size:27px;font-weight:800;color:#10263b', $css);
    }
}
