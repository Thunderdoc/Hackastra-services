<?php

namespace Tests\Feature;

use App\Models\ElectiveChoice;
use App\Models\RolePermission;
use App\Models\StudentDocument;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\RolePermission as Grants;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Custom Roles / Permissions (2026-10-28) — additive grant layer.
 *
 * Users are resolved by EMAIL, never hardcoded ids. Everything runs
 * inside DatabaseTransactions, so no grant, choice or document row
 * survives the test. Proves: teacher without grant -> 403 on
 * electives approve; teacher WITH grant -> approve succeeds; grant
 * destroyed -> 403 again; non-admin cannot open the grants UI;
 * duplicate grants refused; grant-management itself is never
 * grantable; and the admin base rule still works with no grant.
 */
class OwnerRolePermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function makeChoice()
    {
        $record = StudentRecord::first();
        $subject = Subject::first();

        return ElectiveChoice::create([
            'student_record_id' => $record->id,
            'subject_id' => $subject->id,
            'status' => ElectiveChoice::STATUS_PENDING,
            'decided_by' => null,
        ]);
    }

    protected function makeDocument()
    {
        $record = StudentRecord::first();

        return StudentDocument::create([
            'student_record_id' => $record->id,
            'uploaded_by' => $this->admin()->id,
            'title' => 'Grant test document',
            'original_name' => 'grant-test.pdf',
            'stored_path' => 'documents/grant-test-probe.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
    }

    protected function grant($user, $key)
    {
        return RolePermission::create([
            'user_id' => $user->id,
            'permission' => $key,
            'granted_by' => $this->admin()->id,
        ]);
    }

    public function test_non_admin_cannot_open_grants_ui()
    {
        $this->actingAs($this->teacher())
            ->get(route('role_permissions.index'))
            ->assertStatus(403);

        $this->actingAs($this->teacher())
            ->get(route('role_permissions.create'))
            ->assertStatus(403);
    }

    public function test_admin_opens_grants_ui_and_stores_grant()
    {
        $this->actingAs($this->admin())
            ->get(route('role_permissions.index'))
            ->assertStatus(200);

        $this->actingAs($this->admin())
            ->post(route('role_permissions.store'), [
                'user_id' => $this->teacher()->id,
                'permission' => 'electives.approve',
            ])->assertStatus(302);

        $this->assertDatabaseHas('role_permissions', [
            'user_id' => $this->teacher()->id,
            'permission' => 'electives.approve',
        ]);
        $this->assertTrue(Grants::allows($this->teacher(), 'electives.approve'));
    }

    public function test_duplicate_grant_refused()
    {
        $this->grant($this->teacher(), 'electives.approve');

        $this->actingAs($this->admin())
            ->post(route('role_permissions.store'), [
                'user_id' => $this->teacher()->id,
                'permission' => 'electives.approve',
            ])->assertStatus(302)->assertSessionHas('flash_danger');

        $this->assertEquals(1, RolePermission::where('user_id', $this->teacher()->id)
            ->where('permission', 'electives.approve')->count());
    }

    public function test_grant_management_itself_is_never_grantable()
    {
        $this->actingAs($this->admin())
            ->post(route('role_permissions.store'), [
                'user_id' => $this->teacher()->id,
                'permission' => 'role_permissions.manage',
            ])->assertStatus(302)->assertSessionHasErrors('permission');

        $this->assertDatabaseMissing('role_permissions', [
            'user_id' => $this->teacher()->id,
            'permission' => 'role_permissions.manage',
        ]);
        $this->assertFalse(Grants::allows($this->teacher(), 'role_permissions.manage'));
    }

    public function test_teacher_without_grant_gets_403_on_elective_approve()
    {
        $choice = $this->makeChoice();

        $this->actingAs($this->teacher())
            ->post(route('electives.approve', $choice->id))
            ->assertStatus(403);

        $this->assertEquals(
            ElectiveChoice::STATUS_PENDING,
            $choice->fresh()->status
        );
    }

    public function test_teacher_with_grant_approves_then_403_after_revoke()
    {
        $choice = $this->makeChoice();
        $grant = $this->grant($this->teacher(), 'electives.approve');

        $this->actingAs($this->teacher())
            ->post(route('electives.approve', $choice->id))
            ->assertStatus(302);
        $this->assertEquals(
            ElectiveChoice::STATUS_APPROVED,
            $choice->fresh()->status
        );

        // Revoke via the grants UI route, exactly as an admin would.
        $this->actingAs($this->admin())
            ->delete(route('role_permissions.destroy', $grant->id))
            ->assertStatus(302);
        $this->assertFalse(Grants::allows($this->teacher(), 'electives.approve'));

        $choice2 = $this->makeChoice();
        $this->actingAs($this->teacher())
            ->post(route('electives.approve', $choice2->id))
            ->assertStatus(403);
    }

    public function test_grant_is_key_specific_and_admin_base_rule_intact()
    {
        // electives.approve does NOT open documents.destroy.
        $this->grant($this->teacher(), 'electives.approve');
        $document = $this->makeDocument();
        $this->actingAs($this->teacher())
            ->delete(route('documents.destroy', $document->id))
            ->assertStatus(403);
        $this->assertNotNull($document->fresh());

        // documents.destroy grant DOES open destroy.
        $this->grant($this->teacher(), 'documents.destroy');
        $this->actingAs($this->teacher())
            ->delete(route('documents.destroy', $document->id))
            ->assertStatus(302);
        $this->assertNull($document->fresh());

        // Admin needs no grant at all (base rule untouched).
        $choice = $this->makeChoice();
        $this->actingAs($this->admin())
            ->post(route('electives.approve', $choice->id))
            ->assertStatus(302);
        $this->assertEquals(
            ElectiveChoice::STATUS_APPROVED,
            $choice->fresh()->status
        );
    }
}
