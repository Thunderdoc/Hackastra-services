<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Hackstra Wave 2, feature 8 of 8 — Automated Backup (safe subset
 * only).
 *
 * The routes for this feature are integrated centrally by the
 * coordinator (routes/web.php is not edited here), so this test
 * registers the exact proposed routes itself when they are not
 * already present, and skips registration once they are. Either
 * way the controller, view and gates under test are the real ones.
 *
 * What is asserted:
 *   - the Backup & Monitoring page is 200 for the Admin and shows
 *     only safe checks (database, disks, /health, last verified)
 *   - teacher and student are denied by the teamSA gate (the gate
 *     redirects to login)
 *   - the refresh action stores the last-verified timestamp via
 *     Setting (type backup_last_verified)
 *   - the page output contains no secret strings: neither the
 *     literal APP_KEY name nor the app's own key value
 *   - NO raw-download route exists: plausible download URLs 404
 */
class OwnerBackupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Route::has('backup.index')) {
            Route::group([
                // 'web' here mirrors web.php, where the provider wraps
                // everything in the web group (session, error bag for
                // the master layout, CSRF). The coordinator's web.php
                // lines do not need to repeat it.
                'middleware' => ['web', 'auth', 'teamSA'],
                // Full namespace here: in web.php this comes from the
                // outer provider group (App\Http\Controllers) plus the
                // SupportTeam group; the test registers top-level, so
                // it must spell the whole namespace out.
                'namespace' => 'App\Http\Controllers\SupportTeam',
                'prefix' => 'backup',
            ], function () {
                Route::get('/', 'BackupController@index')->name('backup.index');
                Route::post('/refresh', 'BackupController@refresh')->name('backup.refresh');
            });
            // Routes registered after boot are not in the name
            // look-up table until this refresh (RouteServiceProvider
            // only refreshes once, after loading web.php).
            Route::getRoutes()->refreshNameLookups();
        }
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    public function test_backup_page_is_200_for_admin_and_shows_only_safe_checks()
    {
        $this->assertNotNull($this->admin());

        $response = $this->actingAs($this->admin())->get(route('backup.index'));
        $response->assertOk();
        $response->assertSee('Backup &amp; Monitoring', false);
        $response->assertSee('Database connection', false);
        $response->assertSee('/health', false);
        $response->assertSee('Last verified', false);
        // Configured local disks are named; remote disks are listed
        // but not probed.
        $response->assertSee('local', false);
        $response->assertSee('public', false);
    }

    public function test_backup_page_contains_no_secrets()
    {
        $response = $this->actingAs($this->admin())->get(route('backup.index'));
        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString('APP_KEY', $body);
        $this->assertStringNotContainsString((string) config('app.key'), $body);
        $this->assertStringNotContainsString('storage/app', $body);
    }

    public function test_teacher_and_student_are_denied()
    {
        $this->assertNotNull($this->teacher());
        $this->assertNotNull($this->student());

        $this->actingAs($this->teacher())
            ->get(route('backup.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->student())
            ->get(route('backup.index'))
            ->assertRedirect(route('login'));
    }

    public function test_refresh_stores_last_verified_timestamp()
    {
        $this->actingAs($this->admin())
            ->post(route('backup.refresh'))
            ->assertRedirect(route('backup.index'));

        $stored = Setting::where('type', 'backup_last_verified')->value('description');
        $this->assertNotNull($stored);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stored);

        $this->actingAs($this->admin())
            ->get(route('backup.index'))
            ->assertOk()
            ->assertSee('Last verified: ' . $stored, false);
    }

    public function test_no_raw_download_route_exists()
    {
        $this->actingAs($this->admin())->get('/backup/download')->assertNotFound();
        $this->actingAs($this->admin())->get('/backup/export')->assertNotFound();
        $this->actingAs($this->admin())->get('/backup/database.sql')->assertNotFound();
    }
}
