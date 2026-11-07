<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Setting;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Logo fallback (live proof 2026-11-07): the Settings logo URL points
 * into /storage/..., whose file is NOT part of the shipped webroot —
 * on the deployed host it 404'd, so the login card and the sidebar
 * showed a broken-image icon while the setting looked healthy.
 * Qs::getLogo() must only serve the configured logo when its file is
 * actually on disk; otherwise it serves the bundled demo logo that
 * ships in the repo (public/demo-assets/school-logo.svg). A school
 * whose uploaded logo file exists keeps its own logo, unchanged.
 */
class OwnerLogoFallbackTest extends TestCase
{
    use DatabaseTransactions;

    private function setLogo($value)
    {
        Setting::updateOrCreate(['type' => 'logo'], ['description' => $value]);
    }

    public function test_bundled_demo_logo_ships_in_the_repo()
    {
        $this->assertFileExists(public_path('demo-assets/school-logo.svg'));
    }

    public function test_missing_logo_file_falls_back_to_the_bundled_demo_logo()
    {
        $bogus = asset('storage/uploads/logo-that-does-not-exist.svg');
        $this->setLogo($bogus);

        $this->assertSame(asset('demo-assets/school-logo.svg'), Qs::getLogo());

        // Login card renders the bundled fallback, never the broken path.
        $login = $this->get(route('login'));
        $login->assertOk();
        $login->assertSee('demo-assets/school-logo.svg', false);
        $login->assertDontSee('logo-that-does-not-exist', false);

        // A signed-in home never renders the broken path either (the
        // sidebar's own bundled icon fallback is a shipped asset too).
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $home = $this->actingAs($teacher)->get(route('dashboard'));
        $home->assertOk();
        $home->assertDontSee('logo-that-does-not-exist', false);
    }

    public function test_existing_logo_file_is_used_unchanged()
    {
        // A repo-shipped file stands in for a school's uploaded logo:
        // set + on disk => the configured URL wins, byte for byte.
        $valid = asset('global_assets/images/logo_light.png');
        $this->setLogo($valid);

        $this->assertSame($valid, Qs::getLogo());

        $login = $this->get(route('login'));
        $login->assertOk();
        $login->assertSee('global_assets/images/logo_light.png', false);
    }
}
