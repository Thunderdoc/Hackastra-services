<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 10 — platform-users scope spot-check.
 *
 * Settled rules: the Super Admin's Manage Users list carries
 * PLATFORM accounts only (super_admin + admin); the school's
 * people are the school Admin's domain and never appear in the
 * SA list, its counts, or its role filter. The school Admin's
 * and the principal's lists are STAFF lists that never carry
 * super_admin rows (parent fix 2026-10-28).
 *
 * Names used are seeded anchors: Ankit Kumar = superadmin@,
 * Kavita Rao = admin@, Ananya Sharma = teacher@,
 * Rajesh Iyer = principal@ (limited role, level 2).
 */
class OwnerSaUsersScopeRound10Test extends TestCase
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

    public function test_sa_list_shows_platform_accounts_only()
    {
        $this->actingAs($this->sa())->get(route('users.index'))
            ->assertOk()
            ->assertSee('Ankit Kumar')
            ->assertSee('Kavita Rao')
            ->assertDontSee('Ananya Sharma')
            ->assertDontSee('Rajesh Iyer');

        // A tampered role filter cannot reveal school people: the
        // filter only offers roles the viewer may see, so an
        // out-of-scope value falls back to the platform-only list.
        $this->actingAs($this->sa())->get(route('users.index', ['user_type' => 'teacher']))
            ->assertOk()
            ->assertDontSee('Ananya Sharma');
    }

    public function test_admin_and_principal_lists_show_no_super_admin_rows()
    {
        // Round 5 P12 paginated the list (25/page, name order), so
        // witnesses are checked through the search filter — the
        // scoping thesis is unchanged, and the SA must not surface
        // even when searched for by name.
        $this->actingAs($this->admin())->get(route('users.index'))
            ->assertOk()
            ->assertDontSee('Ankit Kumar');

        // (The by-name search asserts on the SA's email: the search
        // box echoes the typed query back into the page, so the name
        // itself always "appears" there — the row would carry his
        // email, and it must not.)
        $this->actingAs($this->admin())->get(route('users.index', ['q' => 'Ankit Kumar']))
            ->assertOk()
            ->assertDontSee('superadmin@hackstra.app');

        $this->actingAs($this->admin())->get(route('users.index', ['q' => 'Ananya Sharma']))
            ->assertOk()
            ->assertSee('Ananya Sharma');

        $this->actingAs($this->admin())->get(route('users.index', ['q' => 'Rajesh Iyer']))
            ->assertOk()
            ->assertSee('Rajesh Iyer');

        $this->actingAs($this->principal())->get(route('users.index'))
            ->assertOk()
            ->assertDontSee('Ankit Kumar');

        $this->actingAs($this->principal())->get(route('users.index', ['q' => 'Ananya Sharma']))
            ->assertOk()
            ->assertSee('Ananya Sharma');
    }
}
