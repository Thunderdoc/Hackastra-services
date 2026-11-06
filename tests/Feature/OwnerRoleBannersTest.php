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
        foreach (['admin', 'principal', 'teacher', 'student', 'parent', 'accountant', 'librarian', 'nurse', 'superadmin'] as $role) {
            $path = public_path('assets/img/banners/role-' . $role . '.jpg');
            $this->assertFileExists($path, 'missing banner image for ' . $role);
            $this->assertGreaterThan(50000, filesize($path), 'banner image for ' . $role . ' looks empty');
        }

        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('.hk-banner-hero', $css);
        $this->assertStringContainsString('.hk-bn-teacher', $css);
        $this->assertStringContainsString('.hk-bn-nurse', $css);
    }
}
