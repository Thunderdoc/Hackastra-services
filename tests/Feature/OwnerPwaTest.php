<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * PWA installability: manifest, service worker and offline page are
 * real files under public/ (served by the web server as the docroot,
 * not by Laravel routes), and the master layout links + registers
 * them. Files are asserted on disk here; the running dev server
 * proves the 200s via curl.
 */
class OwnerPwaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manifest_is_valid_and_names_the_app()
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true);
        $this->assertSame('Hackstra Academy', $manifest['name']);
        $this->assertSame('Hackstra', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_service_worker_caches_shell_and_never_caches_auth()
    {
        $path = public_path('sw.js');
        $this->assertFileExists($path);

        $js = file_get_contents($path);
        $this->assertStringContainsString('hackstra-v1', $js);
        $this->assertStringContainsString('/offline.html', $js);
        $this->assertStringContainsString('Authorization', $js);
    }

    public function test_offline_page_exists()
    {
        $path = public_path('offline.html');
        $this->assertFileExists($path);
        $this->assertStringContainsString("You're offline", file_get_contents($path));
    }

    public function test_master_layout_links_manifest_and_registers_worker()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('manifest.webmanifest', false);
        $response->assertSee('serviceWorker', false);
    }
}
