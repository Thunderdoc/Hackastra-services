<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: "analytical ka mujhe nahi pata —
 * tum apne end se dekho". The analytics review found and fixed:
 *
 *  - Teachers were shown the school's fee collection and
 *    outstanding totals. Fees are not a teacher surface anywhere
 *    else in this product, so a teacher's analytics now carries
 *    attendance + marks only (fee blocks are not even computed).
 *  - The Super Admin could open this school page school-wide;
 *    the platform-only doctrine sends him to /dashboard like
 *    every other school GET.
 *  - Months rendered as raw "2026-10"; they now read "Oct 2026",
 *    and a KPI strip (weighted overall attendance, collected,
 *    outstanding, classes in view) heads the page.
 */
class OwnerAnalyticsReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    public function test_teacher_analytics_has_no_fee_surface()
    {
        // Owner order 2026-10-30 (later the same day): the fee
        // gating was not enough — the analytics dashboard is of
        // no use to a teacher, so it was removed for teachers
        // entirely. 403, and no menu link either.
        $this->actingAs($this->teacher())
            ->get(route('analytics.index'))
            ->assertForbidden();

        $dashboard = $this->actingAs($this->teacher())->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertDontSee(route('analytics.index'), false);
    }

    public function test_super_admin_is_sent_to_the_platform_dashboard()
    {
        $this->actingAs($this->superAdmin())
            ->get(route('analytics.index'))
            ->assertRedirect('/dashboard');
    }

    public function test_admin_sees_kpis_and_readable_months()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('analytics.index', ['from_month' => '2026-10', 'to_month' => '2026-10']));
        $response->assertOk();
        $response->assertSee('Overall Attendance');
        $response->assertSee('Classes in View');
        $response->assertSee('Oct 2026', false);
        $response->assertSee('Fee collection by month');
    }
}
