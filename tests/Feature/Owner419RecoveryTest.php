<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Item 15 (owner, 2026-11-07 late): the 419 recovery was a trap —
 * the error page's "Go back" used history.back(), which restores
 * the STALE form from the back/forward cache with the same dead
 * token, so resubmitting just 419s again. The 419 itself is
 * correct security behaviour and stays; the RECOVERY is fixed:
 *
 *  (1) the page's primary action links to the referring page URL
 *      — a plain link is a fresh navigation, so the form reloads
 *      live with a fresh token. The referer is trusted only when
 *      http(s) and same-host; otherwise the dashboard link alone
 *      is offered. history.back() appears nowhere on the page.
 *  (2) hk-draft autosave coverage extends to the classic-post
 *      forms that lacked it — the leave apply form, the leave
 *      decision-note forms (both queue tables), and the marking
 *      scheme editor — and hk-draft itself learns hk_date fields,
 *      so a 419 no longer eats typed work on those forms either.
 *      (AJAX forms keep their P20 stash and are untouched.)
 */
class Owner419RecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function render419(?string $referer): string
    {
        $server = $referer !== null ? ['HTTP_REFERER' => $referer] : [];
        $req = Request::create('http://localhost/leave', 'POST', [], [], [], $server);
        $this->app->instance('request', $req);

        return view('errors.419')->render();
    }

    public function test_419_page_reloads_the_referring_form_fresh()
    {
        $html = $this->render419('http://localhost/leave/my');

        // The primary action is a fresh load of the form page.
        // (Asserted on the button markup: the body copy also uses
        // the words "Reload the form".)
        $this->assertStringContainsString('>Reload the form</a>', $html);
        $this->assertStringContainsString('href="http://localhost/leave/my"', $html);
        // The dashboard escape stays.
        $this->assertStringContainsString('Back to dashboard', $html);
        // The trap is gone: no history navigation anywhere.
        $this->assertStringNotContainsString('history.back', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_419_page_ignores_a_foreign_referer()
    {
        $html = $this->render419('https://evil.example/phish');

        $this->assertStringNotContainsString('>Reload the form</a>', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('Back to dashboard', $html);
        $this->assertStringNotContainsString('history.back', $html);
    }

    public function test_419_page_without_a_referer_offers_the_dashboard_only()
    {
        $html = $this->render419(null);

        $this->assertStringNotContainsString('>Reload the form</a>', $html);
        $this->assertStringContainsString('Back to dashboard', $html);
        $this->assertStringNotContainsString('history.back', $html);
    }

    public function test_419_blade_source_never_uses_history_back()
    {
        $blade = file_get_contents(base_path('resources/views/errors/419.blade.php'));

        $this->assertStringNotContainsString('history.back', $blade);
        $this->assertStringNotContainsString('javascript:', $blade);
        // The fresh-load return is referer-driven.
        $this->assertStringContainsString("headers->get('referer')", $blade);
        $this->assertStringContainsString('Reload the form', $blade);
    }

    public function test_hk_draft_understands_hk_date_fields()
    {
        $ux = file_get_contents(public_path('assets/js/hackstra-ux.js'));

        // Transformed pairs are read by their hidden sibling's name.
        $this->assertStringContainsString('input[data-hk-date="1"]', $ux);
        $this->assertStringContainsString('function datePairs(form)', $ux);
        // A still-native date input restores through its attribute,
        // which is where hk_date takes its initial value from.
        $this->assertStringContainsString("field.setAttribute('value', data[field.name])", $ux);
    }

    public function test_leave_apply_form_opts_into_drafts()
    {
        $teacher = $this->teacher();

        $resp = $this->actingAs($teacher)->get(route('leave.my'));
        $resp->assertOk();
        $resp->assertSee('data-hk-draft="u' . $teacher->id . '-leave-apply"', false);
    }

    public function test_leave_decision_forms_opt_into_drafts_on_both_queues()
    {
        $teacher = $this->teacher();
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $studentLeave = LeaveRequest::create([
            'user_id' => $student->id,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-10',
            'reason' => 'fever',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $resp = $this->actingAs($teacher)->get(route('leave.student_requests'));
        $resp->assertOk();
        $resp->assertSee('data-hk-draft="u' . $teacher->id . '-leave-decide-' . $studentLeave->id . '"', false);

        $admin = $this->admin();
        $staffLeave = LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'reason' => 'Family function',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $resp = $this->actingAs($admin)->get(route('leave.index'));
        $resp->assertOk();
        $resp->assertSee('data-hk-draft="u' . $admin->id . '-leave-decide-' . $staffLeave->id . '"', false);
    }

    public function test_scheme_editor_forms_opt_into_drafts()
    {
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->get(route('exams.scheme.edit', [1, 13]));
        $resp->assertOk();
        $resp->assertSee('data-hk-draft="u' . $admin->id . '-scheme-1-13-', false);
    }
}
