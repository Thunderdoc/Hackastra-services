<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Principal Command Center (owner order 2026-10-28, Hybrid +
 * School Command Center): 'principal' is its own limited
 * user_type — school-wide read + designated approvals — and
 * lands on the academic-leadership board with its own sidebar
 * order, while a non-principal admin keeps the operator board.
 * The principal never reaches the admin board (dashboard.school
 * redirects back to the principal home).
 */
class OwnerPrincipalDashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function principal()
    {
        $user = User::where('email', 'principal@hackstra.app')->firstOrFail();
        $user->user_type = 'principal';
        $user->save();

        return $user;
    }

    protected function admin()
    {
        $user = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $user->user_type = 'admin';
        $user->save();

        return $user;
    }

    public function test_principal_lands_on_command_center()
    {
        $response = $this->actingAs($this->principal())->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('School Command Center');
        $response->assertSee('Approval Center');
        $response->assertSee('Attention Needed');
        $response->assertSee('Finance');
    }

    public function test_non_principal_admin_keeps_admin_board()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('School Calendar');
        $response->assertDontSee('School Command Center');
    }

    public function test_principal_sidebar_uses_principal_order_without_settings()
    {
        $response = $this->actingAs($this->principal())->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Academics & Curriculum');
        $response->assertSee('Approvals & Requests');
        $response->assertSee('Safety & Incidents');
        $response->assertSee('Principal');
        // Limited role: settings stay admin-only, so the sidebar
        // must not link them (a link would only bounce to login).
        $response->assertDontSee('School Settings');
    }

    public function test_principal_cannot_reach_school_board()
    {
        $response = $this->actingAs($this->principal())->get(route('dashboard.school'));
        $response->assertRedirect(route('dashboard'));
    }
}
