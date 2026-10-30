<?php

namespace Tests\Feature;

use App\Models\UserNotification;
use App\Models\UserPreference;
use App\Support\Notify;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: notifications done properly — a full
 * notifications page with an explicit Mark all read, and a
 * PROPER mute: one master switch in My Account that silences
 * the chime and greys the badge while notifications keep
 * collecting (nothing is ever dropped).
 */
class OwnerNotificationsProperTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_notifications_page_lists_only_my_notifications()
    {
        $teacher = $this->teacher();

        // Delta-based: earlier suite files may have left this
        // teacher notifications of their own (shared test DB).
        $before = Notify::unreadCount($teacher->id);
        Notify::toUser($teacher->id, 'test_event', 'Your cover was approved', 'Body one', route('substitutions.mine'));
        Notify::toUser($teacher->id, 'test_event', 'Second notice', null, null);
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        Notify::toUser($admin->id, 'test_event', 'Admin only notice', null, null);

        $response = $this->actingAs($teacher)->get(route('notifications.index'));
        $response->assertOk();
        $response->assertSee('Your cover was approved');
        $response->assertSee('Second notice');
        $response->assertSee(($before + 2) . ' new');
        $response->assertDontSee('Admin only notice');

        $unread = $this->actingAs($teacher)->get(route('notifications.index', ['filter' => 'unread']));
        $unread->assertOk();
        $unread->assertSee('Your cover was approved');
    }

    public function test_mark_all_read_from_the_page_clears_the_unread_count()
    {
        $teacher = $this->teacher();
        $before = Notify::unreadCount($teacher->id);
        Notify::toUser($teacher->id, 'test_event', 'One', null, null);
        Notify::toUser($teacher->id, 'test_event', 'Two', null, null);
        $this->assertSame($before + 2, Notify::unreadCount($teacher->id));

        // The page's form asks for the redirect contract; the
        // bell's plain post keeps the JSON one (pinned elsewhere).
        $this->actingAs($teacher)
            ->post(route('notifications.read'), ['redirect' => '1'])
            ->assertRedirect();

        $this->assertSame(0, Notify::unreadCount($teacher->id));
        $this->assertSame(0, UserNotification::where('user_id', $teacher->id)->whereNull('read_at')->count());
    }

    public function test_mute_and_bell_sound_save_from_my_account_preferences()
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)->post(route('my_account.preferences'), [
            'tab' => 'notifications',
            'notifications_muted_present' => '1',
            'notifications_muted' => '1',
            'bell_sound_present' => '1',
            // bell_sound checkbox absent = off
        ])->assertRedirect();

        $this->assertTrue(UserPreference::enabled($teacher->id, 'notifications_muted'));
        $this->assertFalse(UserPreference::enabled($teacher->id, 'bell_sound'));

        // Muting never deletes or blocks the notifications
        // themselves — they still arrive and still count.
        $before = Notify::unreadCount($teacher->id);
        Notify::toUser($teacher->id, 'test_event', 'Still arrives while muted', null, null);
        $this->assertSame($before + 1, Notify::unreadCount($teacher->id));

        // The topbar chime gate is off while muted.
        $dash = $this->actingAs($teacher)->get(route('dashboard'));
        $dash->assertOk();
        $dash->assertSee('data-hk-bell-on="0"', false);
        $dash->assertSee('Notifications muted');
    }

    public function test_bell_dropdown_links_the_full_list_and_settings()
    {
        $teacher = $this->teacher();
        $dash = $this->actingAs($teacher)->get(route('dashboard'));
        $dash->assertOk();
        $dash->assertSee('See all notifications');
        $dash->assertSee(route('notifications.index'), false);
    }
}
