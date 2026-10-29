<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\SettingsUnlockRequest;
use App\Models\UserNotification;
use App\Support\SettingsLock;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Settings lock + two-stage unlock (owner order 2026-10-29).
 * The Admin locks the school settings; editing reopens only
 * when his WRITTEN request is approved by the Principal and
 * then by the Super Admin — at which point both the Admin and
 * the Principal are notified. A rejection at either stage
 * keeps the lock.
 */
class OwnerSettingsLockTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal(): User
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function superAdmin(): User
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function bells(User $user, string $type): int
    {
        return UserNotification::where('user_id', $user->id)->where('type', $type)->count();
    }

    public function test_lock_blocks_saves_until_principal_and_super_admin_both_approve()
    {
        $admin = $this->admin();
        $original = Qs::getSetting('system_name');

        $this->actingAs($admin)->post(route('settings.lock'))
            ->assertRedirect(route('settings'));
        $this->assertTrue(SettingsLock::isLocked());

        // Saves are refused while locked, value untouched. (The
        // payload is complete: settings validation runs before
        // the controller, exactly as the real form posts it.)
        $payload = [
            'system_name' => 'Changed While Locked',
            'current_session' => '2026-2027',
            'address' => 'Madurai, Tamil Nadu, India',
        ];
        $this->actingAs($admin)->put(route('settings.update'), $payload)
            ->assertSessionHas('flash_danger');
        $this->assertStringContainsString('Settings are locked', (string) session('flash_danger'));
        $this->assertSame($original, Qs::getSetting('system_name'));

        // The written request goes to the Principal first.
        $this->actingAs($admin)->post(route('settings.unlock_request'), [
            'changes' => 'Update the school phone number — the office line changed.',
        ])->assertRedirect(route('settings'));
        $req = SettingsUnlockRequest::firstOrFail();
        $this->assertSame('pending_principal', $req->status);
        $this->assertGreaterThan(0, $this->bells($this->principal(), 'settings_unlock_requested'));

        // A second request while one is open is refused.
        $this->actingAs($admin)->post(route('settings.unlock_request'), [
            'changes' => 'Another change entirely, also written out.',
        ])->assertSessionHas('flash_danger');
        $this->assertSame(1, SettingsUnlockRequest::count());

        // Principal approves: the lock still holds — the Super
        // Admin has the final word.
        $this->actingAs($this->principal())->post(route('settings_unlock.decide', $req->id), ['decision' => 'approve'])
            ->assertSessionHas('flash_success');
        $this->assertSame('pending_super_admin', $req->fresh()->status);
        $this->assertTrue(SettingsLock::isLocked(), 'principal approval alone must not unlock');

        // The Admin cannot decide his own request at the SA stage.
        $this->actingAs($admin)->post(route('settings_unlock.decide', $req->id), ['decision' => 'approve'])
            ->assertForbidden();
        $this->assertTrue(SettingsLock::isLocked());

        // Super Admin approves: unlocked, and BOTH sides notified.
        $this->actingAs($this->superAdmin())->post(route('settings_unlock.decide', $req->id), ['decision' => 'approve'])
            ->assertSessionHas('flash_success');
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertFalse(SettingsLock::isLocked());
        $this->assertGreaterThan(0, $this->bells($admin, 'settings_unlock_approved'),
            'the Admin must be told the edit right is live');
        $this->assertGreaterThan(0, $this->bells($this->principal(), 'settings_unlock_approved'),
            'the Principal must be told too');

        // Editing works again.
        $payload['system_name'] = 'Changed After Unlock';
        $this->actingAs($admin)->put(route('settings.update'), $payload);
        $this->assertSame('Changed After Unlock', Qs::getSetting('system_name'));
    }

    public function test_principal_rejection_keeps_the_lock_and_frees_a_new_request()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('settings.lock'));
        $this->actingAs($admin)->post(route('settings.unlock_request'), [
            'changes' => 'Change the current session dates for the new term.',
        ]);
        $req = SettingsUnlockRequest::firstOrFail();

        $this->actingAs($this->principal())->post(route('settings_unlock.decide', $req->id), ['decision' => 'reject'])
            ->assertSessionHas('flash_success');
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertTrue(SettingsLock::isLocked());
        $this->assertGreaterThan(0, $this->bells($admin, 'settings_unlock_rejected'));
        $this->assertNull(SettingsLock::openRequest(), 'a rejected request must not block a new one');
    }

    public function test_super_admin_rejection_keeps_the_lock()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('settings.lock'));
        $this->actingAs($admin)->post(route('settings.unlock_request'), [
            'changes' => 'Rename the school on the portal header.',
        ]);
        $req = SettingsUnlockRequest::firstOrFail();
        $this->actingAs($this->principal())->post(route('settings_unlock.decide', $req->id), ['decision' => 'approve']);
        $this->actingAs($this->superAdmin())->post(route('settings_unlock.decide', $req->id), ['decision' => 'reject'])
            ->assertSessionHas('flash_success');
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertTrue(SettingsLock::isLocked());
        $this->assertGreaterThan(0, $this->bells($admin, 'settings_unlock_rejected'));
        $this->assertGreaterThan(0, $this->bells($this->principal(), 'settings_unlock_rejected'),
            'the Principal who approved it hears the final no too');
    }

    public function test_teachers_have_no_part_in_the_lock()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        // The lock route sits behind the teamSA route gate, which
        // bounces non-team roles to login (the app-wide pattern).
        $this->actingAs($teacher)->post(route('settings.lock'))->assertStatus(302);
        $this->assertFalse(SettingsLock::isLocked());
        $this->actingAs($teacher)->get(route('settings_unlock.index'))->assertForbidden();
    }
}
