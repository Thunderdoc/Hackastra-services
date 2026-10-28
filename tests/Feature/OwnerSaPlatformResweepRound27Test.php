<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AuditLog;
use App\Models\FeedbackItem;
use App\Models\SentMail;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 27 — Super Admin platform depth re-sweep.
 *
 * After the round-24/25/26 landscape (marks entry re-gate F-R24-1,
 * student/parent re-sweep, mobile API re-sweep) and the E31/E32
 * exam-scope guards, re-prove the platform boundary end to end:
 * the Super Admin is the platform owner — numbers, not records.
 *
 * BRIEF-PREMISE CORRECTION, read from the code before writing
 * any test: the brief claimed SA GET on /super_admin/settings
 * returns 200. It does not. PlatformOnly's allowlist deliberately
 * EXCLUDES settings/settings.update, and PlatformOnly runs in the
 * web group BEFORE the teamSA route gate — so an SA GET on the
 * settings page redirects to /dashboard, exactly like every other
 * non-allowlisted school-route GET. The settings page is teamSA
 * for the school Admin; the SA never reaches it. The test below
 * locks the real shape (SA 302 dashboard, Admin 200), not the
 * brief's 200.
 *
 * Terminal-close nuance, also read from the code: saUpdate on a
 * Fixed report refuses only a status CHANGE away from Fixed
 * (pop_error); saving the same 'fixed' status (e.g. a reply-only
 * save, which the blade supports via a hidden field) still goes
 * through. The test asserts the refused change, not a blanket
 * refusal of every saUpdate on a closed report.
 */
class OwnerSaPlatformResweepRound27Test extends TestCase
{
    use DatabaseTransactions;

    protected function sa()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function receivedCount()
    {
        return FeedbackItem::forwarded()->where('sa_status', 'received')->count();
    }

    protected function forwardedFixture(array $overrides = [])
    {
        return FeedbackItem::create(array_merge([
            'reporter_id' => $this->teacher()->id,
            'reporter_name' => 'Ananya Sharma',
            'reporter_role' => 'teacher',
            'type' => 'problem',
            'category' => 'app_bug',
            'title' => 'SA platform probe report',
            'description' => 'A forwarded report for the SA board.',
            'status' => 'in_progress',
            'forwarded_at' => now(),
            'forwarded_by' => $this->admin()->id,
            'sa_status' => 'received',
        ], $overrides));
    }

    protected function saSidebarBadge($html)
    {
        if (preg_match('/School Feedback<\/span>\s*<span class="badge badge-danger ml-auto">(\d+)<\/span>/', $html, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function saDashboardHtml()
    {
        $response = $this->actingAs($this->sa())->get(route('dashboard'));
        $response->assertOk();

        return $response->getContent();
    }

    /** The "Platform Accounts by Role" panel, cut out of the page. */
    protected function rolePanel($html)
    {
        $start = strpos($html, 'Platform Accounts by Role');
        $end = strpos($html, 'Recent Audit Activity');
        $this->assertNotFalse($start, 'Role panel heading missing from SA dashboard');
        $this->assertNotFalse($end, 'Audit panel heading missing from SA dashboard');

        return substr($html, $start, $end - $start);
    }

    /** The "School Snapshot" counts panel, cut out of the page. */
    protected function snapshotPanel($html)
    {
        $start = strpos($html, 'School Snapshot');
        $end = strpos($html, 'Platform Actions');
        $this->assertNotFalse($start, 'Snapshot heading missing from SA dashboard');
        $this->assertNotFalse($end, 'Platform Actions heading missing from SA dashboard');

        return substr($html, $start, $end - $start);
    }

    public function test_sa_platform_overview_is_numbers_not_records()
    {
        $html = $this->saDashboardHtml();

        $this->assertStringContainsString('Platform Overview', $html);
        $this->assertStringContainsString('School Snapshot', $html);
        $this->assertStringContainsString('Active students', $html);

        // The role panel lists platform roles ONLY — no school
        // roles at all.
        $panel = $this->rolePanel($html);
        $this->assertStringContainsString('Platform Super Admin', $panel);
        $this->assertStringContainsString('School Admin', $panel);
        $this->assertStringNotContainsString('>Teacher<', $panel);
        $this->assertStringNotContainsString('>Student<', $panel);
        $this->assertStringNotContainsString('>Principal<', $panel);

        // The snapshot panel is counts ONLY — student/teacher
        // totals and one collection total, never a record row
        // or a link into school records (blade comment, owner
        // ruling 2026-10-22). NOTE (test-craft correction, not
        // a finding): a student NAME does legitimately appear
        // elsewhere on this page — the Recent Audit Activity
        // and Recent Sign-ins panels name sign-in ACTORS
        // (e.g. 'Aarav Sharma', auth.login, User #57). That is
        // the platform security surface the SA owns by the
        // round-12 rule (SA sees the full audit trail); it
        // carries no marks, fees, attendance, or class data,
        // so it is not a school-record leak and is NOT
        // asserted against here.
        $snap = $this->snapshotPanel($html);
        $this->assertStringContainsString('Active students', $snap);
        $this->assertStringContainsString('Teachers', $snap);
        $this->assertStringContainsString('Total fee collection', $snap);
        $this->assertStringNotContainsString('Aarav Sharma', $snap);
        $this->assertStringNotContainsString('Dariana Heaney', $snap);
    }

    public function test_sa_users_list_is_platform_only_even_when_filtered()
    {
        $this->actingAs($this->sa())->get(route('users.index'))
            ->assertOk()
            ->assertSee('Ankit Kumar')
            ->assertSee('Kavita Rao')
            ->assertDontSee('Ananya Sharma')
            ->assertDontSee('Rajesh Iyer')
            ->assertDontSee('Aarav Sharma');

        // Tampered filters fall back to the platform-only list.
        $this->actingAs($this->sa())->get(route('users.index', ['user_type' => 'teacher']))
            ->assertOk()
            ->assertDontSee('Ananya Sharma')
            ->assertSee('Kavita Rao');

        $this->actingAs($this->sa())->get(route('users.index', ['user_type' => 'principal']))
            ->assertOk()
            ->assertDontSee('Rajesh Iyer')
            ->assertSee('Kavita Rao');
    }

    public function test_audit_trail_split_sa_full_admin_and_principal_school_only()
    {
        AuditLog::create([
            'user_id' => $this->sa()->id,
            'action' => 'sa.probe',
            'description' => 'SAONLYMARKERX27',
        ]);
        AuditLog::create([
            'user_id' => $this->admin()->id,
            'action' => 'admin.probe',
            'description' => 'ADMINMARKERX27',
        ]);

        // Super Admin: the whole trail, platform rows included.
        $this->actingAs($this->sa())->get(route('audit_logs.index'))
            ->assertOk()
            ->assertSee('SAONLYMARKERX27')
            ->assertSee('ADMINMARKERX27');

        // School Admin: school trail only — no SA-acted rows.
        $this->actingAs($this->admin())->get(route('audit_logs.index'))
            ->assertOk()
            ->assertSee('ADMINMARKERX27')
            ->assertDontSee('SAONLYMARKERX27');

        // Principal: the same school trail as the Admin (round-4 fix).
        $this->actingAs($this->principal())->get(route('audit_logs.index'))
            ->assertOk()
            ->assertSee('ADMINMARKERX27')
            ->assertDontSee('SAONLYMARKERX27');
    }

    public function test_sa_feedback_badge_lifecycle_view_pickup_reopen()
    {
        $baseline = $this->receivedCount();
        $item = $this->forwardedFixture();

        // Badge inflates by exactly the new forwarded item.
        $this->assertSame($baseline + 1, $this->saSidebarBadge($this->saDashboardHtml()));

        // Viewing does NOT clear it.
        $this->actingAs($this->sa())->get(route('feedback.sa_show', $item->id))->assertOk();
        $this->assertSame('received', $item->fresh()->sa_status);
        $this->assertSame($baseline + 1, $this->saSidebarBadge($this->saDashboardHtml()));

        // SA picks it up (in_progress): badge drops to baseline.
        $this->actingAs($this->sa())
            ->patch(route('feedback.sa_update', $item->id), [
                'sa_status' => 'in_progress',
                'sa_reply' => 'Looking into it.',
            ])->assertRedirect();
        $this->assertSame('in_progress', $item->fresh()->sa_status);
        $this->assertSame($baseline, $this->receivedCount());
        $this->assertSame($baseline > 0 ? $baseline : null, $this->saSidebarBadge($this->saDashboardHtml()));

        // SA closes it, then the school Admin reopens: badge
        // re-inflates by exactly the item (re-escalation).
        $this->actingAs($this->sa())
            ->patch(route('feedback.sa_update', $item->id), ['sa_status' => 'fixed'])
            ->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->actingAs($this->admin())
            ->post(route('feedback.reopen', $item->id))
            ->assertRedirect();
        $this->assertSame('received', $item->fresh()->sa_status);
        $this->assertSame('in_progress', $item->fresh()->status);
        $this->assertSame($baseline + 1, $this->receivedCount());
        $this->assertSame($baseline + 1, $this->saSidebarBadge($this->saDashboardHtml()));
    }

    public function test_sa_terminal_close_auto_resolves_and_refuses_reopen()
    {
        $item = $this->forwardedFixture(['status' => 'in_progress']);

        // SA fixes it: school side auto-resolves with it.
        $this->actingAs($this->sa())
            ->patch(route('feedback.sa_update', $item->id), ['sa_status' => 'fixed'])
            ->assertRedirect();
        $item = $item->fresh();
        $this->assertSame('fixed', $item->sa_status);
        $this->assertSame('resolved', $item->status);

        // The detail page disables the status select for a closed item.
        $html = $this->actingAs($this->sa())
            ->get(route('feedback.sa_show', $item->id))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<select name="sa_status"[^>]*disabled/', $html);

        // A status change AWAY from Fixed is refused, statuses intact.
        $this->actingAs($this->sa())
            ->from(route('feedback.sa_show', $item->id))
            ->patch(route('feedback.sa_update', $item->id), ['sa_status' => 'in_progress'])
            ->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertSame('resolved', $item->fresh()->status);

        // The SA cannot reopen it either (PlatformOnly: reopen is
        // not an allowlisted SA route); the principal cannot
        // either (mustBeAdmin guard). Only the school Admin can.
        $this->actingAs($this->sa())->post(route('feedback.reopen', $item->id))->assertForbidden();
        $this->actingAs($this->principal())->post(route('feedback.reopen', $item->id))->assertForbidden();
        $this->assertSame('fixed', $item->fresh()->sa_status);

        $this->actingAs($this->admin())
            ->post(route('feedback.reopen', $item->id))
            ->assertRedirect();
        $this->assertSame('received', $item->fresh()->sa_status);
        $this->assertSame('in_progress', $item->fresh()->status);
    }

    public function test_sa_platform_mail_is_sa_only()
    {
        // Composer lists platform accounts only.
        $this->actingAs($this->sa())->get(route('platform_mail.create'))
            ->assertOk()
            ->assertSee('Ankit Kumar')
            ->assertSee('Kavita Rao')
            ->assertDontSee('Ananya Sharma');

        // SA sends to one platform account: row recorded, redirect
        // to the sent list with a success flash.
        $this->actingAs($this->sa())
            ->post(route('platform_mail.send'), [
                'audience' => 'user',
                'user_id' => Qs::hash($this->admin()->id),
                'subject' => 'SA platform probe mail',
                'body' => 'Platform mail probe body.',
            ])
            ->assertRedirect(route('platform_mail.index'))
            ->assertSessionHas('flash_success');
        $this->assertDatabaseHas('sent_mails', [
            'subject' => 'SA platform probe mail',
            'recipient_count' => 1,
        ]);

        // Sent list shows it to the SA.
        $this->actingAs($this->sa())->get(route('platform_mail.index'))
            ->assertOk()
            ->assertSee('SA platform probe mail');

        // The school Admin cannot compose or send at all.
        $this->actingAs($this->admin())->get(route('platform_mail.create'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->admin())
            ->post(route('platform_mail.send'), [
                'audience' => 'user',
                'user_id' => Qs::hash($this->admin()->id),
                'subject' => 'Admin must not send platform mail',
                'body' => 'Blocked body.',
            ])
            ->assertRedirect(route('login'));
        $this->assertDatabaseMissing('sent_mails', [
            'subject' => 'Admin must not send platform mail',
        ]);
    }

    public function test_sa_school_gets_bounce_to_dashboard()
    {
        // PlatformOnly fires before the team gates: every school
        // GET redirects the SA to the Platform Overview.
        $this->actingAs($this->sa())->get(route('marks.manage', [1, 1, 1, 1]))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->sa())->get(route('exam_dates.manage'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->sa())->get(route('tt.generate.form'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_sa_school_posts_are_forbidden()
    {
        // PlatformOnly aborts non-GET school requests with 403
        // before any controller or validation runs — including on
        // the surfaces re-gated in rounds 24/31/32, which must not
        // accidentally open an SA write path.
        $this->actingAs($this->sa())
            ->put(route('marks.update', [1, 1, 1, 1]), [])
            ->assertForbidden();
        $this->actingAs($this->sa())
            ->post(route('exam_dates.generate'), [])
            ->assertForbidden();
        $this->actingAs($this->sa())
            ->post(route('exam_dates.save'), [])
            ->assertForbidden();
        $this->actingAs($this->sa())
            ->post(route('tt.generate'), [])
            ->assertForbidden();
    }

    public function test_sa_settings_page_is_platformonly_blocked()
    {
        // See the class docblock: settings is NOT on the
        // PlatformOnly allowlist, so the SA never reaches it.
        $this->actingAs($this->sa())->get(route('settings'))
            ->assertRedirect(route('dashboard'));

        // Control: the school Admin does reach it.
        $this->actingAs($this->admin())->get(route('settings'))->assertOk();
    }

    public function test_sa_has_no_topbar_bell()
    {
        $html = $this->saDashboardHtml();

        $this->assertStringNotContainsString('aria-label="Recent activity"', $html);
        $this->assertStringNotContainsString('aria-label="Circulars"', $html);
        $this->assertStringNotContainsString('lav-bell-badge', $html);

        // The page itself rendered fully — the sidebar feedback
        // surface (the SA's real badge home) is present.
        $this->assertStringContainsString('School Feedback', $html);
    }
}
