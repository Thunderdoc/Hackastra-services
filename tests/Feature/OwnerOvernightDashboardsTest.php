<?php

namespace Tests\Feature;

use App\Support\TeacherScope;
use App\User;
use Tests\TestCase;

/**
 * Stream S4-DASHBOARDS, overnight batch 2026-10-29.
 *
 * Item 8 — Analytics Dashboard: reproduce-first. The page already
 * exists (GET /analytics, teamSAT gate, AnalyticsController live
 * aggregates, teacher class-scoping with 403 on a foreign class).
 * These tests prove what actually works on the snapshot DB; they
 * do NOT invent a redesign.
 *
 * Item 9 — Principal login: reproduce-first with a REAL login POST
 * (seeded principal@hackstra.app / demo password, user_type
 * 'principal', status active) through to the principal Command
 * Center (GET /dashboard).
 *
 * Item 10 — Admin dashboard redesign (admin ONLY): the KPI stat
 * cards, Quick Actions and New Enquiries must be gone; the School
 * Calendar stays in the right-side column; greeting, Add Student
 * and all other panels stay. The brief's "move the main form below
 * the calendar" is covered by the form-count test: this page has
 * NO main form — its only forms are the two year-select GET forms
 * on the Enrollment Trend and Fee Collection panels — so no form
 * move is invented here.
 *
 * Run ONLY on an isolated snapshot (never the shared DB):
 *   cp database/database.sqlite /tmp/ovn-dash.sqlite
 *   DB_DATABASE=/tmp/ovn-dash.sqlite php -d memory_limit=512M vendor/bin/phpunit --filter=OwnerOvernightDashboardsTest
 */
class OwnerOvernightDashboardsTest extends TestCase
{
    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    // ---------- Item 8: Analytics Dashboard ----------

    public function test_item8_admin_analytics_renders_all_sections()
    {
        $response = $this->actingAs($this->admin())->get(route('analytics.index'));

        $response->assertOk();
        $response->assertSee('Analytics Dashboard');
        $response->assertSee('Attendance by class and month');
        $response->assertSee('Fee collection by month');
        $response->assertSee('Outstanding fees by class');
        $response->assertSee('Marks averages by class and exam');
    }

    public function test_item8_teacher_analytics_scoped_and_foreign_class_forbidden()
    {
        $teacher = $this->teacher();
        $own = TeacherScope::classIds($teacher);
        $this->assertNotEmpty($own, 'Seed teacher must teach at least one class');

        $allClassIds = \App\Models\MyClass::orderBy('id')->pluck('id')->all();
        $foreignIds = array_values(array_diff($allClassIds, $own));
        $this->assertNotEmpty($foreignIds, 'Seed must have a class the teacher does not teach');

        $this->actingAs($teacher)
            ->get(route('analytics.index', ['class_id' => $own[0]]))
            ->assertOk();

        $this->actingAs($teacher)
            ->get(route('analytics.index', ['class_id' => $foreignIds[0]]))
            ->assertForbidden();
    }

    public function test_item8_analytics_invalid_filters_do_not_error()
    {
        // Unknown class id -> 404 (controller behaviour, not a 500).
        $this->actingAs($this->admin())
            ->get(route('analytics.index', ['class_id' => 999999]))
            ->assertNotFound();

        // Non-numeric class and reversed month range are ignored /
        // normalised, never a 500.
        $this->actingAs($this->admin())
            ->get(route('analytics.index', [
                'class_id' => 'not-a-class',
                'from_month' => '2026-10',
                'to_month' => '2026-01',
            ]))
            ->assertOk();
    }

    public function test_item8_principal_analytics_is_deliberately_gated_not_broken()
    {
        // The principal is deliberately NOT in any team array
        // (limited principal role, 2026-10-28): the analytics route
        // uses teamSAT and the controller guard names only admin /
        // super_admin / teacher, and the principal menu carries NO
        // Analytics Dashboard link (its Reports & Analytics entry
        // points at the reports builder). So a signed-in principal
        // hitting /analytics is redirected to login by the gate —
        // that is the designed gate, not a rendering failure.
        // Opening analytics to the principal would need a route
        // gate change (routes/web.php), which is outside this
        // stream's file scope, so it is reported, not silently made.
        $this->actingAs($this->principal())
            ->get(route('analytics.index'))
            ->assertRedirect('/login');
    }

    // ---------- Item 9: Principal login ----------

    public function test_item9_principal_login_post_reaches_command_center()
    {
        $principal = $this->principal();
        $this->assertSame('principal', $principal->user_type);
        $this->assertSame('active', $principal->status);

        $response = $this->post('/login', [
            'identity' => 'principal@hackstra.app',
            'password' => 'hackstra',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($principal);

        $home = $this->get(route('dashboard'));
        $home->assertOk();
        $home->assertSee('School Command Center');
        $home->assertSee('Approval Center');
    }

    public function test_item9_principal_login_accepts_username_identity()
    {
        $response = $this->post('/login', [
            'identity' => 'principal',
            'password' => 'hackstra',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($this->principal());
    }

    public function test_item9_principal_wrong_password_does_not_authenticate()
    {
        $this->post('/login', [
            'identity' => 'principal@hackstra.app',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    // ---------- Item 10: Admin dashboard redesign ----------

    public function test_item10_admin_dashboard_removes_kpi_quick_actions_enquiries()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Total Students');
        $response->assertDontSee('Teaching Staff');
        $response->assertDontSee('Administrators');
        $response->assertDontSee('Quick Actions');
        $response->assertDontSee('Add Teacher');
        $response->assertDontSee('New Enquiries');
    }

    public function test_item10_admin_dashboard_keeps_calendar_greeting_add_student_and_panels()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('School Calendar');
        $response->assertSee('Add Student');
        $response->assertSee('Student Enrollment Trend');
        $response->assertSee('Fee Collection Overview');
        $response->assertSee('Today');
        $response->assertSee('Recent Students');
        $response->assertSee('Recent Payments');
        $response->assertSee('Upcoming Events');
        $response->assertSee('Smart Insights');
    }

    public function test_item10_admin_dashboard_has_no_main_form_to_move()
    {
        // The brief's form move is blocked with reason: this page
        // has no main form. Its only forms are the two year-select
        // GET forms on the Enrollment Trend and Fee Collection
        // panels (blade grep: exactly 2 '<form' lines in
        // dashboard.blade.php, both class dashboard-period-form).
        // The rendered page also carries layout forms (top search,
        // logout), so this test counts the dashboard's own
        // period-form class, not every form on the page. If a
        // third period form ever appears, this test fails and the
        // move must be re-examined, not guessed.
        $response = $this->actingAs($this->admin())->get(route('dashboard'));
        $response->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'dashboard-period-form'));
        $this->assertSame(2, substr_count($response->getContent(), 'name="year"'));
    }

    public function test_item10_principal_home_is_not_the_admin_dashboard()
    {
        // The redesign touches the admin board only: the principal
        // still lands on its own Command Center, never the
        // support_team dashboard blade.
        $response = $this->actingAs($this->principal())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('School Command Center');
        $response->assertDontSee('Student Enrollment Trend');
    }
}
