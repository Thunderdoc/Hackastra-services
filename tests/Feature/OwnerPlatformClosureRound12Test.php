<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent C round 12 — platform-surface closure probe.
 *
 * SA-only surfaces must stay SA-only, and the audit trail must
 * stay split the way the owner ruled (round 4): the Super Admin
 * sees the whole platform trail; Admin and Principal see the
 * SCHOOL trail only (rows acted by a super_admin stay hidden).
 * There is no audit-log "show" route at all — only the index —
 * so a fabricated show URL must 404 even for SA.
 *
 * Refusal shape in this codebase: both the teamOrPrincipal:SA
 * group (audit logs) and the super_admin group (platform mail)
 * redirect to login on failure — never a rendered denial page.
 */
class OwnerPlatformClosureRound12Test extends TestCase
{
    use DatabaseTransactions;

    protected function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_sa_sees_full_trail_admin_and_principal_see_school_trail_only()
    {
        $sa = $this->user('superadmin@hackstra.app');
        $admin = $this->user('admin@hackstra.app');

        AuditLog::create([
            'user_id' => $sa->id, 'action' => 'platform.probe',
            'description' => 'SAONLYMARKERX1',
        ]);
        AuditLog::create([
            'user_id' => $admin->id, 'action' => 'school.probe',
            'description' => 'ADMINMARKERX1',
        ]);

        // SA sees both rows.
        $this->actingAs($sa)->get(route('audit_logs.index'))
            ->assertOk()
            ->assertSee('SAONLYMARKERX1')
            ->assertSee('ADMINMARKERX1');

        // Admin sees the school row only — the SA-acted row stays hidden.
        $this->actingAs($admin)->get(route('audit_logs.index'))
            ->assertOk()
            ->assertSee('ADMINMARKERX1')
            ->assertDontSee('SAONLYMARKERX1');

        // Principal reads the SAME school trail as Admin (round-4 fix).
        $this->actingAs($this->user('principal@hackstra.app'))
            ->get(route('audit_logs.index'))
            ->assertOk()
            ->assertSee('ADMINMARKERX1')
            ->assertDontSee('SAONLYMARKERX1');
    }

    public function test_non_school_trail_roles_are_refused_audit_logs()
    {
        foreach ([
            'teacher@hackstra.app',
            'accountant@hackstra.app',
            'librarian@hackstra.app',
            'student@hackstra.app',
            'parent@hackstra.app',
        ] as $email) {
            $this->actingAs($this->user($email))->get(route('audit_logs.index'))
                ->assertRedirect(route('login'));
        }
    }

    public function test_audit_log_show_route_does_not_exist_even_for_sa()
    {
        $this->actingAs($this->user('superadmin@hackstra.app'))
            ->get('/super_admin/audit-logs/1')
            ->assertNotFound();
    }

    public function test_platform_mail_is_sa_only()
    {
        // SA reaches both the composer and the sent log.
        $this->actingAs($this->user('superadmin@hackstra.app'))
            ->get(route('platform_mail.create'))->assertOk();
        $this->actingAs($this->user('superadmin@hackstra.app'))
            ->get(route('platform_mail.index'))->assertOk();

        // Every school role is bounced to login on both surfaces.
        foreach ([
            'admin@hackstra.app',
            'principal@hackstra.app',
            'teacher@hackstra.app',
            'accountant@hackstra.app',
            'librarian@hackstra.app',
            'student@hackstra.app',
            'parent@hackstra.app',
        ] as $email) {
            $this->actingAs($this->user($email))->get(route('platform_mail.create'))
                ->assertRedirect(route('login'));
            $this->actingAs($this->user($email))->get(route('platform_mail.index'))
                ->assertRedirect(route('login'));
        }
    }
}
