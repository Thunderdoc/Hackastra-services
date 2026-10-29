<?php

namespace Tests\Feature;

use App\Models\UserPreference;
use App\User;
use Tests\TestCase;

/**
 * Owner order 2026-10-29 (evening): clicking the topbar bell
 * plays a bell sound; a user can mute it for their own account.
 * The preference lives in the per-user UserPreference store
 * (key 'bell_sound', default ON), the toggle POSTs to
 * MyAccountController@update_bell_sound, and the bell button
 * carries the current state as data-hk-bell-on for the chime
 * script in partials/top_menu.
 */
class OwnerBellSoundTest extends TestCase
{
    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_bell_sound_defaults_on_and_toggle_persists_per_user()
    {
        $teacher = $this->teacher();

        // Default: no stored row, the bell renders with sound on.
        $this->assertTrue(UserPreference::enabled($teacher->id, 'bell_sound'));
        $page = $this->actingAs($teacher)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee('data-hk-bell-on="1"', false);
        $page->assertSee('hk-bell-sound-row', false);

        // Mute persists for this user only.
        $this->actingAs($teacher)
            ->postJson(route('my_account.bell_sound'), ['bell_sound' => 'off'])
            ->assertOk()->assertJson(['bell_sound' => 'off']);
        $this->assertFalse(UserPreference::enabled($teacher->id, 'bell_sound'));
        $this->actingAs($teacher)->get(route('dashboard'))
            ->assertSee('data-hk-bell-on="0"', false);

        // Unmute restores it; junk values are refused.
        $this->actingAs($teacher)
            ->postJson(route('my_account.bell_sound'), ['bell_sound' => 'on'])
            ->assertOk();
        $this->assertTrue(UserPreference::enabled($teacher->id, 'bell_sound'));
        $this->actingAs($teacher)
            ->postJson(route('my_account.bell_sound'), ['bell_sound' => 'loud'])
            ->assertStatus(422);
    }
}
