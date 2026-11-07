<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Round 5 Slice 1 (owner order 2026-11-07, "implement all 20 UX
 * patterns"): the interaction core + error floor.
 *
 *  P20 — one shared AJAX error handler (hkAjaxError): 419 session
 *        expired with draft preservation, 429 honouring
 *        Retry-After, network failure visible; a global ajaxError
 *        floor so no jQuery request fails silently.
 *  P1  — hkOptimistic: the payments ajax-pay row (and the topbar
 *        bell surfaces) mutate ONLY through the helper, with a
 *        snapshot that rolls back on any failure.
 *  P7  — hkDebounce is the one debounce; the payments typeahead
 *        and the sidebar saver consume it.
 *  P8  — the double-submit guard bridges programmatic .submit()
 *        and releases after 20 s; the write-heavy routes carry
 *        named per-user throttles.
 *  P2  — the skeleton kit (qs.css + patterns/skeleton partial +
 *        hkSkel) wired into the builder preview and the attendance
 *        AJAX regions; cascades show a Loading state.
 *  P13 — the shortcut registry owns Ctrl+K (the topbar inline
 *        listener is gone), "?" opens the overlay, per-role nav.
 *  P17 — the branded 429/503 pages honour Retry-After.
 */
class OwnerRound5Slice1Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function file(string $rel): string
    {
        return file_get_contents(base_path($rel));
    }

    /* ---------------- P20: the error floor ---------------- */

    public function test_error_floor_handler_exists_with_all_branches()
    {
        $js = $this->file('resources/views/partials/js/custom_js.blade.php');

        $this->assertStringContainsString('window.hkAjaxError = function', $js);
        $this->assertStringContainsString('status === 419', $js);
        $this->assertStringContainsString('status === 429', $js);
        $this->assertStringContainsString('status === 0', $js);
        $this->assertStringContainsString('Retry-After', $js);
        // The global floor: unhandled jQuery failures still surface.
        $this->assertStringContainsString('$(document).ajaxError', $js);
        $this->assertStringContainsString('hkFloorHandled', $js);
        // 419 draft preservation: stash + restore + clear-on-success.
        $this->assertStringContainsString('window.hkStashFormDrafts', $js);
        $this->assertStringContainsString('hkRestoreFormDrafts', $js);
        $this->assertStringContainsString('window.hkClearFormDraft', $js);
        // submitForm routes its silent statuses to the floor and
        // returns its request so optimistic callers can await it.
        $this->assertStringContainsString('window.hkAjaxError(e);', $js);
        $this->assertStringContainsString('return req;', $js);
        // Cascades share the floor instead of failing silently.
        $this->assertStringContainsString('function hkCascadeError', $js);
        $this->assertStringContainsString('hkSilent: true', $js);
    }

    /* ---------------- P1: optimistic helper + payments ---------------- */

    public function test_optimistic_helper_and_payments_rollback_contract()
    {
        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('window.hkOptimistic = function', $ux);
        $this->assertStringContainsString('opts.revert', $ux);
        $this->assertStringContainsString('hkRetryQueue', $ux);

        $js = $this->file('resources/views/partials/js/custom_js.blade.php');
        // The pay handler snapshots, applies through the helper, and
        // reverts on failure; the row is removed only on success.
        $this->assertStringContainsString('window.hkOptimistic({', $js);
        $this->assertStringContainsString('paidText: td_amt_paid.text()', $js);
        $this->assertStringContainsString('That payment was not recorded', $js);
        $this->assertStringContainsString('onSuccess: function()', $js);
    }

    public function test_topbar_bell_surfaces_are_optimistic_and_ctrl_k_has_one_owner()
    {
        $menu = $this->file('resources/views/partials/top_menu.blade.php');
        // Both mark-read branches + the bell-sound toggle ride the
        // helper (apply / request / revert).
        $this->assertGreaterThanOrEqual(3, substr_count($menu, 'window.hkOptimistic({'));
        $this->assertStringContainsString('revert: function ()', $menu);
        // The old silent catch blocks are gone from the bell scripts.
        $this->assertStringNotContainsString("}).catch(function () {});", $menu);
        // Ctrl+K lives in the registry now — the inline listener is
        // removed so the behaviour has exactly one owner.
        $this->assertStringNotContainsString("event.key.toLowerCase() === 'k'", $menu);

        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('window.hkShortcuts', $ux);
        $this->assertStringContainsString("document.getElementById('lav-global-search')", $ux);
    }

    /* ---------------- P7: shared debounce ---------------- */

    public function test_shared_debounce_and_its_migrations()
    {
        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('window.hkDebounce = function', $ux);
        $this->assertStringContainsString('debounced.cancel', $ux);
        // Sidebar scroll saver rides it (no hand-rolled timer left).
        $this->assertStringContainsString("window.hkDebounce(save, 150)", $ux);

        $payments = $this->file('resources/views/pages/support_team/payments/index.blade.php');
        $this->assertStringContainsString('window.hkDebounce', $payments);
        $this->assertStringContainsString('searchNow.cancel()', $payments);
        $this->assertStringNotContainsString('var timer = null;', $payments);
    }

    /* ---------------- P8: guard bridge + throttles ---------------- */

    public function test_guard_bridges_programmatic_submit_and_times_out()
    {
        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('hkSubmitBridge', $ux);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit', $ux);
        $this->assertStringContainsString('hxSubmitAt', $ux);
        $this->assertStringContainsString('20000', $ux);
        $this->assertStringContainsString('[data-hx-guard]', $ux);
    }

    public function test_named_limiters_are_defined()
    {
        $provider = $this->file('app/Providers/RouteServiceProvider.php');
        foreach (['web-write', 'generate', 'ai-assist', 'imports', 'public-form', 'api-login'] as $name) {
            $this->assertStringContainsString("RateLimiter::for('{$name}'", $provider);
        }
        // Authenticated limiters key per user, never per school IP.
        $this->assertStringContainsString("optional(\$request->user())->id ?: \$request->ip()", $provider);
    }

    public function test_write_heavy_routes_carry_their_throttles()
    {
        $expected = [
            'admission_enquiry.store' => 'public-form',
            'students.bulk_delete' => 'web-write',
            'bulk.upload' => 'imports',
            'bulk.jobs.confirm' => 'imports',
            'bulk.jobs.apply' => 'web-write',
            'bulk.jobs.discard' => 'web-write',
            'bulk.jobs.rollback' => 'web-write',
            'leave.decide' => 'web-write',
            'tt.generate' => 'generate',
            'payments.reset_record' => 'web-write',
            'payments.pay_now' => 'web-write',
            'payroll.generate' => 'generate',
            'marks.submit' => 'web-write',
            'marks.reopen' => 'web-write',
            'exams.publish_results' => 'web-write',
            'exams.unpublish_results' => 'web-write',
            'exam_dates.generate' => 'generate',
            'ai.circular_draft' => 'ai-assist',
            'ai.report_comment' => 'ai-assist',
        ];
        foreach ($expected as $name => $limiter) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route {$name} missing");
            $this->assertContains("throttle:{$limiter}", $route->gatherMiddleware(), "route {$name} lacks throttle:{$limiter}");
        }

        $login = collect(Route::getRoutes())->first(function ($r) {
            return $r->uri() === 'api/login' && in_array('POST', $r->methods());
        });
        $this->assertNotNull($login);
        $this->assertContains('throttle:api-login', $login->gatherMiddleware());
    }

    public function test_api_login_throttle_trips_with_retry_after()
    {
        // A dedicated IP keeps this bucket out of every other test's way.
        $this->withServerVariables(['REMOTE_ADDR' => '10.99.98.1']);
        $response = null;
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'nobody@hackstra.app',
                'password' => 'wrong-password',
            ]);
        }
        $response->assertStatus(429);
        $this->assertNotEmpty($response->headers->get('Retry-After'));
    }

    public function test_public_form_throttle_renders_branded_429_with_wait_line()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.99.98.2']);
        $response = null;
        for ($i = 0; $i < 11; $i++) {
            $response = $this->post(route('admission_enquiry.store'), []);
        }
        $response->assertStatus(429);
        $this->assertNotEmpty($response->headers->get('Retry-After'));
        // P17: the branded page turns the header into a concrete wait.
        $response->assertSee('try again in about', false);
    }

    /* ---------------- P2: skeleton kit ---------------- */

    public function test_skeleton_kit_css_partial_and_wiring()
    {
        $css = $this->file('public/assets/css/qs.css');
        $this->assertStringContainsString('.hk-skel{', $css);
        $this->assertStringContainsString('.hk-skel-frame', $css);
        $this->assertStringContainsString('html.hk-dark .hk-skel{', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);

        $table = view('partials.patterns.skeleton', ['type' => 'table', 'rows' => 3])->render();
        $this->assertSame(3, substr_count($table, 'hk-skel-row'));
        $this->assertStringContainsString('aria-hidden="true"', $table);
        $frame = view('partials.patterns.skeleton', ['type' => 'frame', 'rows' => 4])->render();
        $this->assertStringContainsString('hk-skel-frame', $frame);

        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('window.hkSkel = function', $ux);
        $this->assertStringContainsString('window.hkSelectLoading = function', $ux);

        // Builder preview: skeleton overlay + slim bar + guarded buttons.
        $builder = $this->file('resources/views/pages/support_team/reports/builder.blade.php');
        $this->assertStringContainsString('rbSkelOverlay', $builder);
        $this->assertStringContainsString('rbSkelBar', $builder);
        $this->assertStringContainsString('partials.patterns.skeleton', $builder);
        $this->assertGreaterThanOrEqual(3, substr_count($builder, 'data-hx-guard'));

        // Attendance AJAX regions render a skeleton while refreshing.
        $report = $this->file('resources/views/pages/support_team/attendance/report.blade.php');
        $this->assertStringContainsString('window.hkSkel($results[0], true', $report);
        $defaulters = $this->file('resources/views/pages/support_team/attendance/defaulters.blade.php');
        $this->assertStringContainsString('window.hkSkel($adResults[0], true', $defaulters);
    }

    public function test_builder_page_renders_skeleton_overlay()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        $response->assertSee('rbSkelOverlay');
        $response->assertSee('hk-skel-frame');
    }

    /* ---------------- P13: shortcuts ---------------- */

    public function test_shortcuts_overlay_and_registry_render_per_role()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('hk-shortcuts-modal');
        $response->assertSee('Keyboard shortcuts');
        $response->assertSee('hkShortcutNav');
        $response->assertSee('Go to Period Attendance');
        // Admins do not get the teacher's timetable key.
        $response->assertDontSee('Go to My Timetable');

        $teacher = $this->actingAs($this->teacher())->get(route('dashboard'));
        $teacher->assertOk();
        $teacher->assertSee('Go to My Timetable');

        // Discoverability: My Account carries the opener.
        $account = $this->actingAs($this->admin())->get(route('my_account'));
        $account->assertOk();
        $account->assertSee('hk-shortcuts-open');
    }

    public function test_save_form_opt_ins_exist()
    {
        $marks = $this->file('resources/views/pages/support_team/marks/edit.blade.php');
        $this->assertStringContainsString('data-hk-save-form', $marks);
        $take = $this->file('resources/views/pages/support_team/attendance_periods/take.blade.php');
        $this->assertStringContainsString('data-hk-save-form', $take);
    }

    /* ---------------- P17: Retry-After on the error pages ---------------- */

    public function test_error_pages_honour_retry_after()
    {
        foreach (['429', '503'] as $code) {
            $page = $this->file("resources/views/errors/{$code}.blade.php");
            $this->assertStringContainsString('hkRetryAfter', $page);
            $this->assertStringContainsString('getHeaders', $page);
            $this->assertStringContainsString('try again in', $page);
        }
    }
}
