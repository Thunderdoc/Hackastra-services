<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\UserType;
use App\User;
use Tests\TestCase;

/**
 * One Admin per school (owner order 2026-10-29 night): "per
 * school admin can be only one, make sure all as per it."
 *
 * The rule binds every creation path: the single-user form
 * (store refuses a second Admin for ANY actor, the create form
 * stops offering the role while one exists) and the demo seed
 * itself (exactly one user_type=admin account). Editing the
 * existing Admin stays fully possible — that is how a school
 * changes who its Admin is.
 */
class OwnerOneAdminPerSchoolTest extends TestCase
{
    // The full middleware stack runs on purpose: users.* routes are
    // on the PlatformOnly allowlist, so the SA reaches them too, and
    // the create form needs the shared $errors the session middleware
    // provides (a WithoutMiddleware run 500s the form for a harness
    // reason that can never happen live).

    protected function userByEmail(string $email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function createViaRoute(User $actor, string $typeTitle, string $email)
    {
        $typeId = UserType::where('title', $typeTitle)->firstOrFail()->id;
        $state = User::whereNotNull('state_id')->first();

        return $this->actingAs($actor)->post(route('users.store'), [
            'name' => 'Rule Probe Person',
            'email' => $email,
            'user_type' => Qs::hash($typeId),
            'gender' => 'male',
            'address' => '12 School Road',
            'emp_date' => '2026-01-05',
            'state_id' => $state ? $state->state_id : 1,
            'lga_id' => 1,
            'nal_id' => 1,
        ]);
    }

    public function test_seed_has_exactly_one_admin()
    {
        $this->assertSame(1, User::where('user_type', 'admin')->count());
        $this->assertSame('admin@hackstra.app', User::where('user_type', 'admin')->first()->email);
    }

    public function test_second_admin_creation_is_refused_for_admin_and_super_admin()
    {
        $admin = $this->userByEmail('admin@hackstra.app');
        $response = $this->createViaRoute($admin, 'admin', 'second.admin@probe.test');
        $response->assertOk();
        $this->assertFalse($response->json('ok'));
        $this->assertNull(User::where('email', 'second.admin@probe.test')->first());

        $sa = $this->userByEmail('superadmin@hackstra.app');
        $response = $this->createViaRoute($sa, 'admin', 'second.admin2@probe.test');
        $response->assertOk();
        $this->assertFalse($response->json('ok'));
        $this->assertNull(User::where('email', 'second.admin2@probe.test')->first());

        $this->assertSame(1, User::where('user_type', 'admin')->count());
    }

    public function test_other_roles_still_create_and_the_existing_admin_still_edits()
    {
        $admin = $this->userByEmail('admin@hackstra.app');
        // A successful store answers with the redirect to the user
        // list (the JSON {ok:false} shape belongs to refusals only).
        $this->createViaRoute($admin, 'teacher', 'new.teacher@probe.test')->assertRedirect();
        $this->assertNotNull(User::where('email', 'new.teacher@probe.test')->first());

        // The one Admin's own record remains editable (name change
        // is how the school hands the role to a different person).
        $response = $this->actingAs($admin)->put(route('users.update', Qs::hash($admin->id)), [
            'name' => 'Kavita Rao Updated',
            'email' => $admin->email,
            'gender' => 'female',
            'address' => '12 School Road',
            'state_id' => 1,
            'lga_id' => 1,
            'nal_id' => 1,
        ]);
        $this->assertSame('Kavita Rao Updated', $admin->fresh()->name);
    }

    public function test_create_form_stops_offering_admin_while_one_exists()
    {
        foreach (['admin@hackstra.app', 'superadmin@hackstra.app'] as $email) {
            $response = $this->actingAs($this->userByEmail($email))->get(route('users.create'));
            $response->assertOk();
            $titles = collect($response->viewData('user_types'))->pluck('title')->all();
            $this->assertNotContains('admin', $titles, "create form for {$email} must not offer a second Admin");
        }
    }
}
