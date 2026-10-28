<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Roles audit round 15 — Super Admin × principal boundary.
 *
 * The limited principal role postdates the round-10 SA-users scope
 * test, which only covered the SEEDED principal@ row. This suite
 * proves the boundary is by user_type, not by seeded row: a FRESH
 * principal account (as an Admin would provision a second
 * principal) must be invisible to the SA list, visible to the
 * school Admin's and the principal's own staff lists, unreachable
 * by SA lifecycle writes, and uncreatable by the SA — while the
 * school Admin CAN lifecycle-manage it (school-managed, not
 * platform-managed).
 *
 * Settled rules: SA list = super_admin + admin only; admin and
 * principal lists = staff minus student/parent/super_admin (so
 * principal rows ARE school rows); SA writes only super_admin +
 * admin targets (mayWriteUserTarget); Admin writes every
 * non-super_admin target; SA provisions platform accounts only.
 */
class OwnerSaPrincipalBoundaryRound15Test extends TestCase
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

    /** A second principal, as provisioned by the school Admin. */
    protected function freshPrincipal()
    {
        return User::create([
            'name' => 'Zed Principal Probe',
            'username' => 'zedprincipalprobe',
            'email' => 'zedprincipalprobe@hackstra.app',
            'password' => Hash::make('principal'),
            'code' => 'ZEDPRINC01',
            'user_type' => 'principal',
        ]);
    }

    public function test_sa_list_excludes_fresh_principal_even_when_filtered()
    {
        $this->freshPrincipal();

        $this->actingAs($this->sa())->get(route('users.index'))
            ->assertOk()
            ->assertSee('Ankit Kumar')
            ->assertSee('Kavita Rao')
            ->assertDontSee('Zed Principal Probe');

        // Tampered filter: 'principal' is not among the SA's filter
        // options (super_admin + admin only), so it falls back to
        // the platform-only list — still no principal row.
        $this->actingAs($this->sa())->get(route('users.index', ['user_type' => 'principal']))
            ->assertOk()
            ->assertDontSee('Zed Principal Probe')
            ->assertSee('Kavita Rao');
    }

    public function test_admin_and_principal_lists_include_fresh_principal()
    {
        $this->freshPrincipal();

        $this->actingAs($this->admin())->get(route('users.index'))
            ->assertOk()
            ->assertSee('Zed Principal Probe')
            ->assertDontSee('Ankit Kumar');

        // The Admin's role filter DOES offer principal, so it
        // narrows to principals only: both principal rows, no
        // teacher rows.
        $this->actingAs($this->admin())->get(route('users.index', ['user_type' => 'principal']))
            ->assertOk()
            ->assertSee('Zed Principal Probe')
            ->assertSee('Rajesh Iyer')
            ->assertDontSee('Ananya Sharma');

        // Peer principals appear in the principal's own staff list.
        $this->actingAs($this->principal())->get(route('users.index'))
            ->assertOk()
            ->assertSee('Zed Principal Probe')
            ->assertDontSee('Ankit Kumar');
    }

    public function test_sa_lifecycle_writes_on_principal_are_refused()
    {
        $probe = $this->freshPrincipal();

        foreach (['suspend', 'activate', 'terminate'] as $action) {
            $this->actingAs($this->sa())
                ->from(route('users.index'))
                ->post(route("users.$action", Qs::hash($probe->id)))
                ->assertRedirect()
                ->assertSessionHas('flash_danger');
            $this->assertSame('active', $probe->fresh()->accountStatus());
        }
    }

    public function test_admin_lifecycle_write_on_principal_succeeds()
    {
        // Positive control: the principal IS school-managed — the
        // school Admin suspends it, proving the refusal above is
        // about the SA boundary, not about principals being
        // untouchable.
        $probe = $this->freshPrincipal();

        $this->actingAs($this->admin())
            ->from(route('users.index'))
            ->post(route('users.suspend', Qs::hash($probe->id)))
            ->assertRedirect()
            ->assertSessionHas('flash_success');
        $this->assertSame(User::STATUS_SUSPENDED, $probe->fresh()->accountStatus());
    }

    public function test_sa_cannot_create_a_principal_account()
    {
        // The create form posts user_type as a HASHED user-type id
        // (UserRequest decodes it before validation) — posting the
        // plain title would be refused for the wrong reason, so the
        // fixture posts exactly what the real form posts.
        $principalTypeId = \App\Models\UserType::where('title', 'principal')->firstOrFail()->id;
        $state = User::where('state_id', '!=', null)->first();
        $this->actingAs($this->sa())->post(route('users.store'), [
            'name' => 'Zed Principal Created',
            'email' => 'zedcreated@hackstra.app',
            'user_type' => Qs::hash($principalTypeId),
            'gender' => 'male',
            'address' => '12 School Road',
            'state_id' => $state ? $state->state_id : 1,
            'lga_id' => 1,
            'nal_id' => 1,
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'zedcreated@hackstra.app']);
    }
}
