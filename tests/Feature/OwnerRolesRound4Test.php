<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AuditLog;
use App\Models\FeedbackItem;
use App\Models\Payroll;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\SentMail;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Roles agent C round 4: Super Admin surfaces, payroll edge cases,
 * and the orphan PTM bell item.
 *
 * Pattern: every guard is proven by a request, not by reading code.
 * A second Super Admin + fresh staff/parent fixtures are created
 * inside the test transaction and roll back with it.
 */
class OwnerRolesRound4Test extends TestCase
{
    use DatabaseTransactions;

    protected function byEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function sa() { return $this->byEmail('superadmin@hackstra.app'); }
    protected function admin() { return $this->byEmail('admin@hackstra.app'); }
    protected function teacher() { return $this->byEmail('teacher@hackstra.app'); }
    protected function principal() { return $this->byEmail('principal@hackstra.app'); }
    protected function parent() { return $this->byEmail('parent@hackstra.app'); }
    protected function accountant() { return $this->byEmail('accountant@hackstra.app'); }

    protected function makeUser($type, $code, $email, $name)
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('secret'),
            'user_type' => $type,
            'code' => $code,
            'status' => 'active',
        ]);
    }

    /* ---------------- Super Admin surfaces ---------------- */

    public function test_sa_dashboard_is_platform_overview_not_school_records()
    {
        $res = $this->actingAs($this->sa())->get(route('dashboard'));
        $res->assertOk();
        $res->assertSee('Platform Accounts by Role');
        $res->assertSee('Platform Accounts');
        // The by-role panel carries platform accounts only.
        $res->assertSee('Platform Super Admin');
        $res->assertSee('School Admin');
        // NOTE: the dashboard's recent-audit / recent-login panels
        // legitimately name school actors (the SA holds the full
        // audit trail by design) — no name assertion here.
    }

    public function test_sa_settings_page_is_platformonly_blocked_but_admin_reaches_it()
    {
        // Route middleware is teamSA, but PlatformOnly deliberately
        // keeps settings off the SA allowlist: SA GET -> dashboard.
        $this->actingAs($this->sa())->get(route('settings'))
            ->assertRedirect(route('dashboard'));
        // Non-GET for a disallowed SA route is a hard 403.
        $this->actingAs($this->sa())->put(route('settings.update'), [])
            ->assertForbidden();
        $this->actingAs($this->admin())->get(route('settings'))
            ->assertOk();
    }

    public function test_sa_users_list_is_platform_accounts_only()
    {
        $res = $this->actingAs($this->sa())->get(route('users.index'));
        $res->assertOk();
        $res->assertSee('Kavita Rao'); // school admin
        $res->assertDontSee('Ananya Sharma'); // teacher: school domain
        $res->assertDontSee('Aarav Sharma'); // student: never in users list
    }

    public function test_admin_users_list_is_staff()
    {
        $this->actingAs($this->admin())->get(route('users.index'))
            ->assertOk()
            ->assertSee('Ananya Sharma');
    }

    /**
     * FIXED by parent 2026-10-28 (round-4 finding): the admin and
     * principal branches now also exclude super_admin rows, so the
     * head-SA row renders on neither list — matching the principal
     * branch comment ("never platform accounts") and the admin
     * staff-list rule. The tampered ?user_type=super_admin filter
     * already yields null (super_admin is level 1, outside ut2).
     */
    public function test_users_list_sa_row_excluded_for_admin_and_principal()
    {
        $this->actingAs($this->admin())->get(route('users.index'))
            ->assertOk()->assertDontSee('Ankit Kumar');
        $this->actingAs($this->principal())->get(route('users.index'))
            ->assertOk()->assertDontSee('Ankit Kumar');
    }

    public function test_lifecycle_self_action_refused()
    {
        $sa = $this->sa();
        $res = $this->actingAs($sa)->from(route('users.index'))
            ->post(route('users.suspend', ['id' => Qs::hash($sa->id)]));
        $res->assertSessionHas('flash_danger', 'You cannot change the status of your own account.');
        $this->assertEquals('active', $sa->fresh()->status ?: 'active');
    }

    public function test_lifecycle_head_sa_untouchable_but_peer_sa_can_be_suspended()
    {
        $sa2 = $this->makeUser('super_admin', 'SA2TEST', 'sa2@test.local', 'Second SA');
        // A peer SA cannot touch the head SA (id 1).
        $this->actingAs($sa2)->from(route('users.index'))
            ->post(route('users.suspend', ['id' => Qs::hash($this->sa()->id)]))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertEquals('active', $this->sa()->fresh()->status ?: 'active');
        // But the head SA may suspend the peer SA.
        $this->actingAs($this->sa())->from(route('users.index'))
            ->post(route('users.suspend', ['id' => Qs::hash($sa2->id)]))
            ->assertSessionHas('flash_success');
        $this->assertEquals('suspended', $sa2->fresh()->status);
        // And reactivate them.
        $this->actingAs($this->sa())->from(route('users.index'))
            ->post(route('users.activate', ['id' => Qs::hash($sa2->id)]))
            ->assertSessionHas('flash_success');
        $this->assertEquals('active', $sa2->fresh()->status);
    }

    public function test_lifecycle_admin_cannot_suspend_super_admin()
    {
        $sa2 = $this->makeUser('super_admin', 'SA2TEST', 'sa2@test.local', 'Second SA');
        $this->actingAs($this->admin())->from(route('users.index'))
            ->post(route('users.suspend', ['id' => Qs::hash($sa2->id)]))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertEquals('active', $sa2->fresh()->status);
    }

    public function test_audit_log_scoping_sa_sees_all_admin_and_principal_see_school_trail_only()
    {
        $saMarker = 'R4-SA-ACTED-MARKER';
        $adminMarker = 'R4-ADMIN-ACTED-MARKER';
        AuditLog::create(['user_id' => $this->sa()->id, 'action' => 'r4.probe', 'description' => $saMarker]);
        AuditLog::create(['user_id' => $this->admin()->id, 'action' => 'r4.probe', 'description' => $adminMarker]);

        $this->actingAs($this->sa())->get(route('audit_logs.index'))
            ->assertOk()->assertSee($saMarker)->assertSee($adminMarker);
        $this->actingAs($this->admin())->get(route('audit_logs.index'))
            ->assertOk()->assertDontSee($saMarker)->assertSee($adminMarker);
        // Principal is school leadership: same school-trail rule as Admin.
        $this->actingAs($this->principal())->get(route('audit_logs.index'))
            ->assertOk()->assertDontSee($saMarker)->assertSee($adminMarker);
    }

    public function test_platform_mail_sa_only_and_logged_not_faked()
    {
        $this->actingAs($this->sa())->get(route('platform_mail.create'))
            ->assertOk()->assertDontSee('Ananya Sharma');
        $this->actingAs($this->admin())->get(route('platform_mail.create'))
            ->assertRedirect(route('login'));

        $before = SentMail::count();
        $res = $this->actingAs($this->sa())->post(route('platform_mail.send'), [
            'audience' => 'admins',
            'subject' => 'Round4 probe',
            'body' => 'Probe body, never delivered as real mail without SMTP.',
        ]);
        $res->assertRedirect(route('platform_mail.index'));
        $this->assertEquals($before + 1, SentMail::count());
        $sent = SentMail::latest('id')->first();
        $this->assertEquals('logged', $sent->status);
        $this->assertEquals('admins', $sent->audience);

        // Admin POST is refused by the super_admin middleware, no row.
        $this->actingAs($this->admin())->post(route('platform_mail.send'), [
            'audience' => 'admins', 'subject' => 'x', 'body' => 'x',
        ])->assertRedirect(route('login'));
        $this->assertEquals($before + 1, SentMail::count());
    }

    public function test_forwarded_feedback_only_for_sa()
    {
        $fwd = FeedbackItem::create([
            'reporter_id' => $this->teacher()->id, 'reporter_name' => 'Ananya Sharma',
            'reporter_role' => 'teacher', 'type' => 'feedback', 'category' => 'facility',
            'priority' => 'normal', 'title' => 'R4 forwarded item', 'description' => 'd',
            'status' => 'acknowledged', 'forwarded_at' => now(), 'forwarded_by' => $this->admin()->id,
        ]);
        $unfwd = FeedbackItem::create([
            'reporter_id' => $this->teacher()->id, 'reporter_name' => 'Ananya Sharma',
            'reporter_role' => 'teacher', 'type' => 'feedback', 'category' => 'facility',
            'priority' => 'normal', 'title' => 'R4 never forwarded item', 'description' => 'd',
            'status' => 'new',
        ]);

        $this->actingAs($this->sa())->get(route('feedback.sa_index'))
            ->assertOk()->assertSee('R4 forwarded item')->assertDontSee('R4 never forwarded item');
        // Unforwarded detail 404s for SA (mustBeForwarded).
        $this->actingAs($this->sa())->get(route('feedback.sa_show', $unfwd->id))
            ->assertNotFound();
        $this->actingAs($this->sa())->get(route('feedback.sa_show', $fwd->id))
            ->assertOk();
        // Admin cannot use SA feedback routes at all.
        $this->actingAs($this->admin())->get(route('feedback.sa_index'))
            ->assertForbidden();
    }

    /* ---------------- Payroll edge cases ---------------- */

    public function test_payroll_admin_index_accountant_blocked_principal_blocked_sa_platformonly()
    {
        $this->actingAs($this->admin())->get(route('payroll.index'))
            ->assertOk();
        $this->actingAs($this->accountant())->get(route('payroll.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->principal())->get(route('payroll.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->sa())->get(route('payroll.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_payroll_month_boundaries_clamp()
    {
        $this->actingAs($this->admin())->get(route('payroll.index', ['month' => 13, 'year' => 2026]))
            ->assertOk()->assertViewHas('month', now()->month);
        $this->actingAs($this->admin())->get(route('payroll.index', ['month' => 0, 'year' => 1999]))
            ->assertOk()->assertViewHas('month', now()->month)->assertViewHas('year', now()->year);
        $this->actingAs($this->admin())->get(route('payroll.index', ['month' => 5, 'year' => 2026]))
            ->assertOk()->assertViewHas('month', 5)->assertViewHas('year', 2026);
    }

    public function test_payroll_duplicate_month_refused_and_non_staff_refused()
    {
        // Seeded: teacher@ has an Oct 2026 record.
        $before = Payroll::forMonth(2026, 10)->count();
        $this->actingAs($this->admin())->from(route('payroll.index'))
            ->post(route('payroll.store'), [
                'user_id' => $this->teacher()->id, 'year' => 2026, 'month' => 10,
                'basic_salary' => 1000, 'allowances' => 0, 'deductions' => 0,
            ])->assertSessionHas('flash_danger');
        $this->assertEquals($before, Payroll::forMonth(2026, 10)->count());

        // Students and principals never receive salary records.
        $student = $this->byEmail('student@hackstra.app');
        $this->actingAs($this->admin())->from(route('payroll.index'))
            ->post(route('payroll.store'), [
                'user_id' => $student->id, 'year' => 2026, 'month' => 11,
                'basic_salary' => 1000, 'allowances' => 0, 'deductions' => 0,
            ])->assertSessionHas('flash_danger');
        $this->assertEquals(0, Payroll::where('user_id', $student->id)->where('year', 2026)->where('month', 11)->count());
        $this->actingAs($this->admin())->from(route('payroll.index'))
            ->post(route('payroll.store'), [
                'user_id' => $this->principal()->id, 'year' => 2026, 'month' => 11,
                'basic_salary' => 1000, 'allowances' => 0, 'deductions' => 0,
            ])->assertSessionHas('flash_danger');
        $this->assertEquals(0, Payroll::where('user_id', $this->principal()->id)->count());
    }

    public function test_payroll_generate_is_idempotent_for_the_month()
    {
        $before = Payroll::forMonth(2026, 10)->count();
        $this->actingAs($this->admin())->from(route('payroll.index'))
            ->post(route('payroll.generate'), ['year' => 2026, 'month' => 10]);
        $afterFirst = Payroll::forMonth(2026, 10)->count();
        $this->assertGreaterThanOrEqual($before, $afterFirst);
        $res = $this->actingAs($this->admin())->from(route('payroll.index'))
            ->post(route('payroll.generate'), ['year' => 2026, 'month' => 10]);
        $res->assertSessionHas('flash_success');
        $this->assertStringContainsString('already has', session('flash_success'));
        $this->assertEquals($afterFirst, Payroll::forMonth(2026, 10)->count());
    }

    public function test_payslips_self_service_only_and_empty_state()
    {
        // Fresh teacher with no rows: empty state, no crash.
        $fresh = $this->makeUser('teacher', 'TTEST1', 't1@test.local', 'Fresh Teacher');
        $this->actingAs($fresh)->get(route('my_payslips.index'))
            ->assertOk()->assertSee('No payslips available yet');
        $this->actingAs($fresh)->from(route('my_payslips.index'))
            ->get(route('my_payslips.pdf_all'))
            ->assertSessionHas('flash_info');

        // Own-records-only: accountant sees only their own Sep row.
        $accRow = Payroll::create([
            'user_id' => $this->accountant()->id, 'year' => 2026, 'month' => 9,
            'basic_salary' => 30000, 'allowances' => 0, 'deductions' => 0, 'status' => 'pending',
        ]);
        $this->assertEquals('30000.00', $accRow->fresh()->net_pay);
        $res = $this->actingAs($this->accountant())
            ->get(route('my_payslips.index', ['year' => 2026, 'month' => 9]));
        $res->assertOk();
        $this->assertEquals(1, $res->viewData('records')->count());
        // Teacher in the same month filter sees zero rows.
        $res2 = $this->actingAs($this->teacher())
            ->get(route('my_payslips.index', ['year' => 2026, 'month' => 9]));
        $res2->assertOk();
        $this->assertEquals(0, $res2->viewData('records')->count());

        // Foreign payslip views are 403 both ways.
        $teacherRow = Payroll::where('user_id', $this->teacher()->id)->firstOrFail();
        $this->actingAs($this->accountant())->get(route('my_payslips.payslip', $teacherRow->id))
            ->assertForbidden();
        $this->actingAs($this->teacher())->get(route('my_payslips.payslip', $accRow->id))
            ->assertForbidden();
        $this->actingAs($this->accountant())->get(route('my_payslips.pdf', $teacherRow->id))
            ->assertForbidden();

        // Principals get no payslips at all.
        $this->actingAs($this->principal())->get(route('my_payslips.index'))
            ->assertForbidden();
    }

    /* ---------------- Orphan PTM bell item ---------------- */

    public function test_ptm_cancel_cleans_the_teachers_bell_item()
    {
        $slot = PtmSlot::create([
            'date' => '2026-11-05', 'start_time' => '14:00:00', 'end_time' => '15:00:00',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'created_by' => $this->admin()->id,
        ]);
        // Parent books for their own Prep child (record 1 -> user 57).
        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $this->byEmail('student@hackstra.app')->id,
        ])->assertRedirect(route('ptm.index'));
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();
        $bell = UserNotification::where('user_id', $this->teacher()->id)
            ->where('type', 'ptm_booked')
            ->where('title', 'PTM slot booked: 05 Nov 2026 14:00');
        $this->assertEquals(1, $bell->count());

        // The booking parent cancels: booking AND bell item go away.
        $this->actingAs($this->parent())->from(route('ptm.index'))
            ->delete(route('ptm.cancel', $booking->id))
            ->assertRedirect(route('ptm.index'));
        $this->assertNull(PtmBooking::find($booking->id));
        $this->assertEquals(0, $bell->count());
        // The slot is free again.
        $this->assertNull($slot->fresh()->booking);
    }

    public function test_ptm_foreign_cancel_refused()
    {
        $slot = PtmSlot::create([
            'date' => '2026-11-03', 'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'created_by' => $this->admin()->id,
        ]);
        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $this->byEmail('student@hackstra.app')->id,
        ]);
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();
        $otherParent = $this->makeUser('parent', 'PTEST1', 'p1@test.local', 'Other Parent');
        $this->actingAs($otherParent)->from(route('ptm.index'))
            ->delete(route('ptm.cancel', $booking->id))
            ->assertForbidden();
        $this->assertNotNull(PtmBooking::find($booking->id));
    }
}
