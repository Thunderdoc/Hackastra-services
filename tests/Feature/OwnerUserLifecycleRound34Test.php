<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\UserType;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roles audit round 34 — user lifecycle WORKFLOW chain (P15).
 *
 * Round 15 proved the SA × principal boundary per action; round 26
 * proved API login gates per role. This round runs ONE continuous
 * lifecycle through the REAL routes and asserts scope at every
 * handoff, because a link can be guarded while the next link leaks:
 *
 *  SA creates an admin -> admin logs in (web + API) -> admin
 *  creates a teacher -> teacher logs in (web + API) -> admin
 *  SUSPENDS -> teacher's next web request bounces, real web login
 *  is refused, API login is 403, and (C-R34-1) a token minted
 *  BEFORE the suspension no longer yields data -> admin ACTIVATES
 *  -> web + API work again -> admin TERMINATES -> web + API
 *  refused again, records preserved.
 *
 * C-R34-1 (FIXED this round, MobileApiController only — NOT a
 * parent-owned file): pre-fix, suspension/termination blocked NEW
 * API logins (403) and ended web sessions on the next request
 * (EnsureActiveAccount + LoginController::authenticated), but a
 * Sanctum token minted before the status change kept yielding
 * data on every API data route — the api group carries no status
 * check and changeAccountStatus deletes no token rows. The fix is
 * a per-request status guard at the top of every API data method
 * (me, timetable, marks, attendance, notifications, messages),
 * returning the same 403 message as the login gate. Logout is
 * deliberately EXEMPT (a suspended user must still be able to
 * revoke her own token). Token rows are NOT deleted by the fix:
 * enforcement is per-request, revocation on suspend remains a
 * separate (parent-owned UserController) decision.
 *
 * Negatives: SA lifecycle writes on a principal refused; admin
 * lifecycle write on the head Super Admin refused; nobody changes
 * their own account; a suspended teacher cannot reactivate
 * herself (teamSA middleware bounces her to login).
 *
 * Snapshot-DB only; all probe rows and tokens roll back.
 */
class OwnerUserLifecycleRound34Test extends TestCase
{
    use DatabaseTransactions;

    protected const INACTIVE_MESSAGE = 'This account is not active. Please contact the school administrator.';

    protected function sa()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function locationIds(): array
    {
        $state = User::whereNotNull('state_id')->first();

        return [
            'state_id' => $state ? $state->state_id : 1,
            'lga_id' => 1,
            'nal_id' => 1,
        ];
    }

    protected function createViaRoute(User $actor, string $typeTitle, string $name, string $email, ?string $username = null)
    {
        $typeId = UserType::where('title', $typeTitle)->firstOrFail()->id;
        $payload = array_merge([
            'name' => $name,
            'email' => $email,
            'user_type' => Qs::hash($typeId),
            'gender' => 'male',
            'address' => '12 School Road',
            'emp_date' => '2026-01-05',
        ], $this->locationIds());
        if ($username !== null) {
            $payload['username'] = $username;
        }

        return $this->actingAs($actor)->post(route('users.store'), $payload);
    }

    protected function userByEmail(string $email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function tokenCount(User $user): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count();
    }

    protected function apiWithToken(string $method, string $uri, string $token, array $data = [])
    {
        // Round-26 artifact: forget the cached guard so each call
        // resolves from its own Bearer token like real HTTP.
        // Headers are passed PER CALL (json() 4th arg), never via
        // withHeaders() (which persists across requests in one test).
        // And after the call the default guard is reset to web:
        // an auth:sanctum request switches the in-process default
        // guard to sanctum and actingAs() does NOT restore it, so a
        // later web GET would authenticate via the sanctum
        // RequestGuard — and EnsureActiveAccount's guard-less
        // Auth::logout() then 500s (RequestGuard has no logout).
        // Pure in-process artifact: real HTTP requests are separate
        // processes whose default guard is web, so this is NOT an
        // app bug and the parent-owned middleware is untouched.
        Auth::forgetGuards();

        $response = $this->json($method, $uri, $data, ['Authorization' => 'Bearer ' . $token]);
        Auth::shouldUse('web');

        return $response;
    }

    public function test_sa_creates_admin_who_logs_in_web_and_api()
    {
        $this->createViaRoute($this->sa(), 'admin', 'R34 Chain Admin', 'r34chainadmin@hackstra.app', 'r34chainadmin')
            ->assertRedirect();
        $fresh = $this->userByEmail('r34chainadmin@hackstra.app');
        $this->assertSame('admin', $fresh->user_type);
        $this->assertSame('active', $fresh->accountStatus());

        // Web: the new admin reaches school surfaces.
        $this->actingAs($fresh)->get(route('dashboard'))->assertOk();
        $this->actingAs($fresh)->get(route('users.index'))->assertOk();

        // Real web login (fresh session, no actingAs in the way).
        Auth::logout();
        $this->post('/login', ['identity' => 'r34chainadmin@hackstra.app', 'password' => 'admin'])
            ->assertRedirect('/home');
        $this->assertAuthenticatedAs($fresh);

        // API login: token minted, default password = user type.
        $this->postJson('/api/login', ['identity' => 'r34chainadmin@hackstra.app', 'password' => 'admin'])
            ->assertOk()
            ->assertJsonPath('user.user_type', 'admin');
        $this->assertNotEmpty($this->postJson('/api/login', ['identity' => 'r34chainadmin@hackstra.app', 'password' => 'admin'])->json('token'));
        $this->assertSame(2, $this->tokenCount($fresh));
    }

    public function test_admin_creates_teacher_then_suspend_activate_terminate_chain()
    {
        $admin = $this->admin();
        $this->createViaRoute($admin, 'teacher', 'R34 Chain Teacher', 'r34chainteacher@hackstra.app')
            ->assertRedirect();
        $teacher = $this->userByEmail('r34chainteacher@hackstra.app');
        $this->assertSame('teacher', $teacher->user_type);

        // Teacher logs in: web dashboard + API token.
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk();
        $login = $this->postJson('/api/login', ['identity' => 'r34chainteacher@hackstra.app', 'password' => 'teacher'])
            ->assertOk();
        $this->assertNotEmpty($login->json('token'));
        $this->assertSame(1, $this->tokenCount($teacher));

        // Token minted BEFORE any status change — the C-R34-1 leg.
        $oldToken = $teacher->createToken('mobile')->plainTextToken;
        $this->assertSame(2, $this->tokenCount($teacher));
        $this->apiWithToken('GET', '/api/me', $oldToken)->assertOk();

        // (c) SUSPEND: exact flash, status flips.
        $this->actingAs($admin)->from(route('users.index'))
            ->post(route('users.suspend', Qs::hash($teacher->id)))
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Account suspended — R34 Chain Teacher can no longer sign in.');
        $this->assertSame('suspended', $teacher->fresh()->accountStatus());

        // Next web request bounces (EnsureActiveAccount ends the session).
        $this->actingAs($teacher->fresh())->get(route('dashboard'))->assertRedirect(route('login'));

        // Real web login refused with the identity error.
        Auth::logout();
        $this->post('/login', ['identity' => 'r34chainteacher@hackstra.app', 'password' => 'teacher'])
            ->assertSessionHasErrors('identity');
        $this->assertGuest();

        // API login refused, no token; rows NOT revoked by suspension.
        $this->postJson('/api/login', ['identity' => 'r34chainteacher@hackstra.app', 'password' => 'teacher'])
            ->assertStatus(403)
            ->assertJsonPath('message', self::INACTIVE_MESSAGE);
        $this->assertSame(2, $this->tokenCount($teacher));

        // C-R34-1: the pre-suspension token no longer yields data.
        $this->apiWithToken('GET', '/api/me', $oldToken)
            ->assertStatus(403)
            ->assertJsonPath('message', self::INACTIVE_MESSAGE);

        // (d) ACTIVATE: web + API work again.
        $this->actingAs($admin)->from(route('users.index'))
            ->post(route('users.activate', Qs::hash($teacher->id)))
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Account reactivated — R34 Chain Teacher can sign in again.');
        $this->assertSame('active', $teacher->fresh()->accountStatus());
        $this->actingAs($teacher->fresh())->get(route('dashboard'))->assertOk();
        $this->postJson('/api/login', ['identity' => 'r34chainteacher@hackstra.app', 'password' => 'teacher'])
            ->assertOk();
        $this->apiWithToken('GET', '/api/me', $oldToken)->assertOk();

        // (e) TERMINATE: web + API refused again, records preserved.
        $this->actingAs($admin)->from(route('users.index'))
            ->post(route('users.terminate', Qs::hash($teacher->id)))
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Account terminated — R34 Chain Teacher can no longer sign in. Their records are preserved.');
        $this->assertSame('terminated', $teacher->fresh()->accountStatus());
        $this->actingAs($teacher->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        Auth::logout();
        $this->post('/login', ['identity' => 'r34chainteacher@hackstra.app', 'password' => 'teacher'])
            ->assertSessionHasErrors('identity');
        $this->assertGuest();
        $this->postJson('/api/login', ['identity' => 'r34chainteacher@hackstra.app', 'password' => 'teacher'])
            ->assertStatus(403)
            ->assertJsonPath('message', self::INACTIVE_MESSAGE);
        $this->apiWithToken('GET', '/api/me', $oldToken)
            ->assertStatus(403)
            ->assertJsonPath('message', self::INACTIVE_MESSAGE);

        // The terminated person still exists: state change, not deletion.
        $this->assertDatabaseHas('users', ['email' => 'r34chainteacher@hackstra.app', 'user_type' => 'teacher']);
    }

    public function test_suspended_token_refused_on_every_data_route_but_logout_still_revokes()
    {
        $admin = $this->admin();
        $this->createViaRoute($admin, 'teacher', 'R34 Route Teacher', 'r34routeteacher@hackstra.app')
            ->assertRedirect();
        $teacher = $this->userByEmail('r34routeteacher@hackstra.app');
        $token = $teacher->createToken('mobile')->plainTextToken;

        // Active control: at least one data route answers.
        $this->apiWithToken('GET', '/api/notifications', $token)->assertOk();

        $this->actingAs($admin)->from(route('users.index'))
            ->post(route('users.suspend', Qs::hash($teacher->id)))->assertRedirect();
        $this->assertSame('suspended', $teacher->fresh()->accountStatus());

        // Every data route refuses with the login-gate message (not
        // the per-route type message — the status guard fires first).
        foreach (['/api/me', '/api/timetable/mine', '/api/marks/mine', '/api/attendance/mine', '/api/notifications', '/api/messages'] as $uri) {
            $this->apiWithToken('GET', $uri, $token)
                ->assertStatus(403)
                ->assertJsonPath('message', self::INACTIVE_MESSAGE);
        }

        // Logout is exempt: a suspended user can still revoke her token.
        $this->assertSame(1, $this->tokenCount($teacher));
        $this->apiWithToken('POST', '/api/logout', $token)->assertOk();
        $this->assertSame(0, $this->tokenCount($teacher));
    }

    public function test_lifecycle_negatives_sa_principal_admin_superadmin_self_and_self_reactivation()
    {
        $admin = $this->admin();
        $sa = $this->sa();

        // Fresh principal, provisioned by the school Admin.
        $this->createViaRoute($admin, 'principal', 'R34 Probe Principal', 'r34probeprincipal@hackstra.app')
            ->assertRedirect();
        $principal = $this->userByEmail('r34probeprincipal@hackstra.app');

        // SA lifecycle writes on a principal: refused, stays active (R15).
        foreach (['suspend', 'activate', 'terminate'] as $action) {
            $this->actingAs($sa)->from(route('users.index'))
                ->post(route("users.$action", Qs::hash($principal->id)))
                ->assertRedirect()
                ->assertSessionHas('flash_danger');
            $this->assertSame('active', $principal->fresh()->accountStatus());
        }

        // Admin lifecycle write on the head Super Admin: refused.
        $this->actingAs($admin)->from(route('users.index'))
            ->post(route('users.suspend', Qs::hash($sa->id)))
            ->assertRedirect()
            ->assertSessionHas('flash_danger');
        $this->assertSame('active', $sa->fresh()->accountStatus());

        // Nobody changes their own account.
        $this->actingAs($admin)->from(route('users.index'))
            ->post(route('users.suspend', Qs::hash($admin->id)))
            ->assertRedirect()
            ->assertSessionHas('flash_danger', 'You cannot change the status of your own account.');
        $this->assertSame('active', $admin->fresh()->accountStatus());

        // A suspended teacher cannot reactivate herself: the teamSA
        // middleware bounces her to login before any controller guard.
        $this->createViaRoute($admin, 'teacher', 'R34 Self Teacher', 'r34selfteacher@hackstra.app')
            ->assertRedirect();
        $teacher = $this->userByEmail('r34selfteacher@hackstra.app');
        $this->actingAs($admin)->from(route('users.index'))
            ->post(route('users.suspend', Qs::hash($teacher->id)))->assertRedirect();
        $this->assertSame('suspended', $teacher->fresh()->accountStatus());
        $this->actingAs($teacher->fresh())
            ->post(route('users.activate', Qs::hash($teacher->id)))
            ->assertRedirect(route('login'));
        $this->assertSame('suspended', $teacher->fresh()->accountStatus());
    }
}
