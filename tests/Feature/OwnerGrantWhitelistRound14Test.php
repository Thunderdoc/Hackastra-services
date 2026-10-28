<?php

namespace Tests\Feature;

use App\Models\ElectiveChoice;
use App\Models\RolePermission;
use App\Support\RolePermission as Grants;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent C round 14 — custom-roles whitelist probe (Wave 3 rule:
 * grants are ADDITIVE ONLY; the whitelist is exactly
 * electives.approve, electives.reject, documents.destroy).
 *
 * What OwnerRolePermissionTest already locks (not re-proven here):
 * non-admin UI 403, admin store, duplicate refused, grant-management
 * never grantable, teacher without grant 403, with grant approves,
 * revoke restores 403, key-specificity, admin base rule intact.
 *
 * This suite probes the gaps around that: non-whitelisted keys
 * beyond grant-management, non-admin write attempts, a bogus row
 * planted outside validation, a grant holder's reach into
 * admin-only surfaces, and the grantee-scope question (store
 * accepts any user id while the picker is staff-only).
 *
 * Users resolved by email, never hardcoded ids. All rows inside
 * DatabaseTransactions, rolled back.
 */
class OwnerGrantWhitelistRound14Test extends TestCase
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

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function grant($user, $key)
    {
        return RolePermission::create([
            'user_id' => $user->id,
            'permission' => $key,
            'granted_by' => $this->admin()->id,
        ]);
    }

    protected function makeChoice()
    {
        return ElectiveChoice::create([
            'student_record_id' => \App\Models\StudentRecord::first()->id,
            'subject_id' => \App\Models\Subject::first()->id,
            'status' => ElectiveChoice::STATUS_PENDING,
            'decided_by' => null,
        ]);
    }

    public function test_non_whitelisted_keys_refused_by_store()
    {
        $bogus = [
            'users.manage',
            'marks.manage',
            'payments.create',
            'settings.update',
            'role_permissions.store',
            'grants.manage',
            'Electives.Approve',
        ];

        foreach ($bogus as $key) {
            $this->actingAs($this->admin())
                ->post(route('role_permissions.store'), [
                    'user_id' => $this->teacher()->id,
                    'permission' => $key,
                ])->assertStatus(302)->assertSessionHasErrors('permission');

            $this->assertDatabaseMissing('role_permissions', [
                'user_id' => $this->teacher()->id,
                'permission' => $key,
            ]);
            $this->assertFalse(Grants::allows($this->teacher(), $key));
        }
    }

    public function test_trailing_space_key_trims_to_whitelisted_key()
    {
        // Test-authoring trap caught in this round (not an app bug):
        // Laravel's TrimStrings middleware trims the permission
        // before validation, so 'electives.approve ' becomes exactly
        // 'electives.approve' and stores a valid whitelisted grant.
        // That is normalization to a whitelisted key, never an
        // escape to a non-whitelisted one: the stored key is exact.
        $this->actingAs($this->admin())
            ->post(route('role_permissions.store'), [
                'user_id' => $this->teacher()->id,
                'permission' => 'electives.approve ',
            ])->assertStatus(302);
        $this->assertDatabaseHas('role_permissions', [
            'user_id' => $this->teacher()->id,
            'permission' => 'electives.approve',
        ]);
        $this->assertTrue(Grants::allows($this->teacher(), 'electives.approve'));
    }

    public function test_non_admin_write_attempts_refused()
    {
        // Teacher POSTs a whitelisted grant for herself: the
        // controller guard fires before validation.
        $this->actingAs($this->teacher())
            ->post(route('role_permissions.store'), [
                'user_id' => $this->teacher()->id,
                'permission' => 'electives.approve',
            ])->assertStatus(403);
        $this->assertDatabaseMissing('role_permissions', [
            'user_id' => $this->teacher()->id,
            'permission' => 'electives.approve',
        ]);

        // Teacher DELETEs an existing grant: refused, row survives.
        $grant = $this->grant($this->teacher(), 'electives.approve');
        $this->actingAs($this->teacher())
            ->delete(route('role_permissions.destroy', $grant->id))
            ->assertStatus(403);
        $this->assertNotNull($grant->fresh());
    }

    public function test_planted_bogus_row_grants_nothing()
    {
        // A row inserted outside store validation (legacy import,
        // direct DB edit) must still grant nothing: allows() checks
        // the whitelist before the row.
        $this->grant($this->teacher(), 'users.manage');
        $this->assertFalse(Grants::allows($this->teacher(), 'users.manage'));

        $choice = $this->makeChoice();
        $this->actingAs($this->teacher())
            ->post(route('electives.approve', $choice->id))
            ->assertStatus(403);
        $this->assertEquals(ElectiveChoice::STATUS_PENDING, $choice->fresh()->status);
    }

    public function test_grant_holder_still_refused_admin_surfaces()
    {
        $grant = $this->grant($this->teacher(), 'electives.approve');

        // Middleware-gated admin surfaces bounce to login.
        $this->actingAs($this->teacher())
            ->get(route('users.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())
            ->get(route('settings'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())
            ->get(route('transport.assignments'))
            ->assertRedirect(route('login'));

        // Grant administration itself stays admin-only even for a
        // grant holder, and the grant survives the refused delete.
        $this->actingAs($this->teacher())
            ->get(route('role_permissions.index'))
            ->assertStatus(403);
        $this->actingAs($this->teacher())
            ->delete(route('role_permissions.destroy', $grant->id))
            ->assertStatus(403);
        $this->assertNotNull($grant->fresh());

        // Positive control: the whitelisted action itself works.
        $choice = $this->makeChoice();
        $this->actingAs($this->teacher())
            ->post(route('electives.approve', $choice->id))
            ->assertStatus(302);
        $this->assertEquals(ElectiveChoice::STATUS_APPROVED, $choice->fresh()->status);
    }

    public function test_store_accepts_student_grantee_and_guard_honors_it()
    {
        // OBSERVATION (round 14, not a whitelist escape): the create
        // picker is staff-only, but store() validates any user id —
        // the controller docblock says so explicitly. An admin grant
        // to a student is therefore stored, and the approve guard
        // (admin OR grant, no user_type check) honors it — including
        // for the student's own choice. Only an admin can create
        // such a grant (non-admin store is 403, proven above), so
        // this is admin-misconfiguration surface, not a privilege
        // escalation by the student. Whether store should refuse
        // student/parent ids to match the picker is an owner call;
        // this test documents the current behavior either way.
        $this->actingAs($this->admin())
            ->post(route('role_permissions.store'), [
                'user_id' => $this->student()->id,
                'permission' => 'electives.approve',
            ])->assertStatus(302);
        $this->assertDatabaseHas('role_permissions', [
            'user_id' => $this->student()->id,
            'permission' => 'electives.approve',
        ]);
        $this->assertTrue(Grants::allows($this->student(), 'electives.approve'));

        $choice = $this->makeChoice();
        $this->actingAs($this->student())
            ->post(route('electives.approve', $choice->id))
            ->assertStatus(302);
        $this->assertEquals(ElectiveChoice::STATUS_APPROVED, $choice->fresh()->status);
    }
}
