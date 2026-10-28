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
 * Roles audit round 35 — P21 SWEEP EXTENSION (C-R34-1 siblings).
 *
 * Round 34 proved the core hole and fix: a Sanctum token minted
 * BEFORE suspension kept yielding 200 on every API data route,
 * because the api group carried no per-request status check. The
 * fix (MobileApiController::inactiveAccountResponse, NOT a
 * parent-owned file) guards all six data methods with the login
 * gate's exact 403 message; logout stays exempt; token rows are
 * NOT deleted — enforcement is per-request, not revocation.
 *
 * This round sweeps the credential types round 34 did NOT
 * individually lock:
 *
 *  (a) OLD TOKEN AFTER REACTIVATION: the same token minted before
 *      suspension must return 200 again after reactivation WITHOUT
 *      a fresh login — because the rows survive. Round 34's chain
 *      asserted this once inside a longer test; here it is the
 *      dedicated leg, plus terminate (403 again, rows still kept).
 *  (b) PARENT token: all parent data routes (?child= own child
 *      1, plus messages + me) 403 while suspended, 200 again with
 *      the SAME token after reactivation.
 *  (c) STUDENT token: own data routes 403 while suspended, 200
 *      again with the SAME token after reactivation.
 *  (d) WEB SECOND SESSION: a session that never existed before
 *      the suspension ALSO bounces on its first request — the
 *      bounce is per-request (EnsureActiveAccount), not the
 *      invalidation of one particular session id. In-process
 *      tests share one session store, so a literal second browser
 *      cannot be distinguished from the first; the fresh-actingAs
 *      leg plus the real-login-refusal leg together prove the
 *      mechanism, and the docblock says so plainly rather than
 *      claiming a two-browser proof the harness cannot give.
 *  (e) ADMIN token (extra leg): an admin's me/notifications
 *      answer 200 active, 403 inactive-message while suspended
 *      by the Super Admin, 200 again with the SAME token after
 *      SA reactivation — the guard is role-blind, as designed.
 *
 * Test-craft trap (round 34, NOT an app bug): an auth:sanctum
 * request switches the in-process default guard to sanctum and
 * actingAs() does NOT restore it, so a later web GET would
 * authenticate via the sanctum RequestGuard and
 * EnsureActiveAccount's guard-less Auth::logout() would 500
 * (RequestGuard has no logout). Hence: per-call json() headers
 * (never persistent withHeaders) + Auth::shouldUse('web') after
 * every API call. Real HTTP requests are separate processes
 * whose default guard is web.
 *
 * Snapshot-DB only; all probe rows and tokens roll back.
 */
class OwnerTokenSweepRound35Test extends TestCase
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

    protected function createViaRoute(User $actor, string $typeTitle, string $name, string $email)
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

    protected function changeStatus(User $actor, User $target, string $action)
    {
        return $this->actingAs($actor)->from(route('users.index'))
            ->post(route("users.$action", Qs::hash($target->id)))
            ->assertRedirect();
    }

    protected function apiWithToken(string $method, string $uri, string $token, array $data = [])
    {
        Auth::forgetGuards();

        $response = $this->json($method, $uri, $data, ['Authorization' => 'Bearer ' . $token]);
        Auth::shouldUse('web');

        return $response;
    }

    protected function assertInactiveOnAll(array $uris, string $token)
    {
        foreach ($uris as $uri) {
            $this->apiWithToken('GET', $uri, $token)
                ->assertStatus(403)
                ->assertJsonPath('message', self::INACTIVE_MESSAGE);
        }
    }

    public function test_old_token_returns_after_reactivation_without_relogin()
    {
        $admin = $this->admin();
        $this->createViaRoute($admin, 'teacher', 'R35 Reactivate Teacher', 'r35reactteacher@hackstra.app')
            ->assertRedirect();
        $teacher = $this->userByEmail('r35reactteacher@hackstra.app');
        $oldToken = $teacher->createToken('mobile')->plainTextToken;
        $this->assertSame(1, $this->tokenCount($teacher));

        // Active: the old token yields data.
        $this->apiWithToken('GET', '/api/me', $oldToken)->assertOk();
        $this->apiWithToken('GET', '/api/notifications', $oldToken)->assertOk();

        // Suspended: same token refuses on every data route, rows kept.
        $this->changeStatus($admin, $teacher, 'suspend');
        $this->assertSame('suspended', $teacher->fresh()->accountStatus());
        $this->assertSame(1, $this->tokenCount($teacher));
        $this->assertInactiveOnAll(['/api/me', '/api/notifications'], $oldToken);

        // Reactivated: the SAME token yields data again — no new
        // login, no re-mint. This is the per-request (not
        // revocation) design of C-R34-1, locked explicitly.
        $this->changeStatus($admin, $teacher, 'activate');
        $this->assertSame('active', $teacher->fresh()->accountStatus());
        $this->assertSame(1, $this->tokenCount($teacher));
        $this->apiWithToken('GET', '/api/me', $oldToken)->assertOk();
        $this->apiWithToken('GET', '/api/notifications', $oldToken)->assertOk();

        // Terminated: same token refuses again, rows STILL kept.
        $this->changeStatus($admin, $teacher, 'terminate');
        $this->assertSame('terminated', $teacher->fresh()->accountStatus());
        $this->assertSame(1, $this->tokenCount($teacher));
        $this->assertInactiveOnAll(['/api/me', '/api/notifications'], $oldToken);
    }

    public function test_parent_token_suspended_then_reactivated_with_same_token()
    {
        // Seeded parent (id 5) links ONLY to student records 1 + 2
        // (users 57 + 58); ?child=1 is an own child, so every route
        // below is a legitimate 200 while active.
        $parent = $this->userByEmail('parent@hackstra.app');
        $this->assertSame('parent', $parent->user_type);
        $token = $parent->createToken('mobile')->plainTextToken;
        $uris = [
            '/api/me',
            '/api/timetable/mine?child=1',
            '/api/marks/mine?child=1',
            '/api/attendance/mine?child=1',
            '/api/notifications',
            '/api/messages',
        ];

        foreach ($uris as $uri) {
            $this->apiWithToken('GET', $uri, $token)->assertOk();
        }

        $this->changeStatus($this->admin(), $parent, 'suspend');
        $this->assertSame('suspended', $parent->fresh()->accountStatus());
        $this->assertSame(1, $this->tokenCount($parent));
        $this->assertInactiveOnAll($uris, $token);

        $this->changeStatus($this->admin(), $parent, 'activate');
        $this->assertSame('active', $parent->fresh()->accountStatus());
        foreach ($uris as $uri) {
            $this->apiWithToken('GET', $uri, $token)->assertOk();
        }
    }

    public function test_student_token_suspended_then_reactivated_with_same_token()
    {
        // Seeded student (user 57, Prep Section A, record 1).
        $student = $this->userByEmail('student@hackstra.app');
        $this->assertSame('student', $student->user_type);
        $token = $student->createToken('mobile')->plainTextToken;
        $uris = [
            '/api/me',
            '/api/timetable/mine',
            '/api/marks/mine',
            '/api/attendance/mine',
            '/api/notifications',
        ];

        foreach ($uris as $uri) {
            $this->apiWithToken('GET', $uri, $token)->assertOk();
        }

        $this->changeStatus($this->admin(), $student, 'suspend');
        $this->assertSame('suspended', $student->fresh()->accountStatus());
        $this->assertSame(1, $this->tokenCount($student));
        $this->assertInactiveOnAll($uris, $token);

        $this->changeStatus($this->admin(), $student, 'activate');
        $this->assertSame('active', $student->fresh()->accountStatus());
        foreach ($uris as $uri) {
            $this->apiWithToken('GET', $uri, $token)->assertOk();
        }
    }

    public function test_fresh_web_session_bounces_after_suspension()
    {
        $admin = $this->admin();
        $this->createViaRoute($admin, 'teacher', 'R35 Second Session Teacher', 'r35secondsession@hackstra.app')
            ->assertRedirect();
        $teacher = $this->userByEmail('r35secondsession@hackstra.app');

        // Session 1: real web login, dashboard answers.
        Auth::logout();
        $this->post('/login', ['identity' => 'r35secondsession@hackstra.app', 'password' => 'teacher'])
            ->assertRedirect('/home');
        $this->assertAuthenticatedAs($teacher);
        $this->get(route('dashboard'))->assertOk();

        // Admin suspends. Session 1's next request bounces — and so
        // does a FRESH actingAs (a session that never existed
        // before the suspension): the bounce is per-request via
        // EnsureActiveAccount, not the invalidation of one id.
        $this->changeStatus($admin, $teacher, 'suspend');
        $this->assertSame('suspended', $teacher->fresh()->accountStatus());
        $this->actingAs($teacher->fresh())->get(route('dashboard'))->assertRedirect(route('login'));

        // No NEW session can be minted while suspended, either.
        Auth::logout();
        $this->post('/login', ['identity' => 'r35secondsession@hackstra.app', 'password' => 'teacher'])
            ->assertSessionHasErrors('identity');
        $this->assertGuest();

        // Reactivated: a fresh session answers again.
        $this->changeStatus($admin, $teacher, 'activate');
        $this->assertSame('active', $teacher->fresh()->accountStatus());
        $this->actingAs($teacher->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_admin_token_suspended_by_sa_then_reactivated_with_same_token()
    {
        $sa = $this->sa();
        $this->createViaRoute($sa, 'admin', 'R35 Sweep Admin', 'r35sweepadmin@hackstra.app')
            ->assertRedirect();
        $freshAdmin = $this->userByEmail('r35sweepadmin@hackstra.app');
        $this->assertSame('admin', $freshAdmin->user_type);
        $token = $freshAdmin->createToken('mobile')->plainTextToken;

        // Admin data routes are role-403 by design (round 26), so
        // the sweep uses the caller-scoped routes: me + notifications.
        $this->apiWithToken('GET', '/api/me', $token)->assertOk();
        $this->apiWithToken('GET', '/api/notifications', $token)->assertOk();

        // SA suspends the admin; the guard is role-blind.
        $this->changeStatus($sa, $freshAdmin, 'suspend');
        $this->assertSame('suspended', $freshAdmin->fresh()->accountStatus());
        $this->assertInactiveOnAll(['/api/me', '/api/notifications'], $token);

        $this->changeStatus($sa, $freshAdmin, 'activate');
        $this->assertSame('active', $freshAdmin->fresh()->accountStatus());
        $this->apiWithToken('GET', '/api/me', $token)->assertOk();
        $this->apiWithToken('GET', '/api/notifications', $token)->assertOk();
    }
}
