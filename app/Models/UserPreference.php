<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (user, key) account preference — see the My Account page
 * (Batch 10). Values are stored as strings; boolean preferences use
 * '1'/'0'. Defaults live here so a user with no rows still gets the
 * product's standard behaviour.
 */
class UserPreference extends Model
{
    protected $fillable = ['user_id', 'key', 'value'];

    /** Preference keys the My Account page is allowed to write. */
    public static $keys = [
        'notify_email',
        'notify_announcements',
        'notify_attendance',
        'notify_exams',
        // My Account v3: gates the LMS bell alerts (assignment graded,
        // internal marks pushed) — enforced in App\Support\Notify.
        'notify_lms',
        // F1 (Round 4): the real WhatsApp opt-out — enforced in the
        // WhatsApp layer only (App\Support\ParentWhatsApp). Turning
        // it off marks rows skipped_opt_out; bells are unaffected.
        'notify_whatsapp',
        'date_format',
        'time_format',
        // Loop 50 (Workstream BX): the topbar dark-mode toggle writes
        // this key through MyAccountController@update_theme — same
        // per-user store, values 'dark'/'light'.
        'theme',
        // Owner order 2026-10-29: the topbar bell sound toggle
        // (MyAccountController@update_bell_sound), '1' = on.
        'bell_sound',
        // Owner order 2026-10-30: the proper mute — one master
        // switch in My Account. '1' = muted: the chime never plays
        // and the badge greys; notifications still collect in the
        // list (nothing is ever dropped).
        'notifications_muted',
        // Round 5 P4 (sidebar memory, Design B rebuild): the rail's
        // collapsed state ('1' = collapsed / body.sidebar-xs) and the
        // JSON list of expanded submenu group keys (data-group slugs
        // from partials/menu). Written by MyAccountController@
        // update_sidebar_state — same store, same AJAX pattern as
        // the theme toggle above.
        'sidebar_collapsed',
        'sidebar_open_groups',
    ];

    public static function defaults(): array
    {
        return [
            'notify_email' => '1',
            'notify_announcements' => '1',
            'notify_attendance' => '1',
            'notify_exams' => '1',
            'notify_lms' => '1',
            'notify_whatsapp' => '1',
            'date_format' => 'd M Y',
            'time_format' => '12',
            // Owner directive 2026-10-22 ("dark mode for all"): accounts
            // that never chose get dark; a stored 'light' choice wins.
            'theme' => 'dark',
            // The bell sound is ON until the user mutes it.
            'bell_sound' => '1',
            // Notifications are unmuted until the user mutes them.
            'notifications_muted' => '0',
            // Sidebar memory defaults: expanded rail, no remembered
            // open groups (route-driven auto-open still applies).
            'sidebar_collapsed' => '0',
            'sidebar_open_groups' => '[]',
        ];
    }

    /** All preferences for a user: stored values over the defaults. */
    public static function allFor(int $userId): array
    {
        $stored = self::where('user_id', $userId)->pluck('value', 'key')->all();

        return array_merge(self::defaults(), $stored);
    }

    public static function set(int $userId, string $key, $value): void
    {
        if (!in_array($key, self::$keys, true)) {
            return;
        }

        self::updateOrCreate(
            ['user_id' => $userId, 'key' => $key],
            ['value' => $value === null ? null : (string) $value]
        );
    }

    public static function enabled(int $userId, string $key): bool
    {
        $prefs = self::allFor($userId);

        return ($prefs[$key] ?? '0') === '1';
    }

    /** P4: the remembered collapsed state of the main sidebar. */
    public static function sidebarCollapsed(int $userId): bool
    {
        return self::enabled($userId, 'sidebar_collapsed');
    }

    /**
     * P4: the remembered open submenu groups, as a list of data-group
     * slugs. Anything malformed in storage degrades to the default
     * (no remembered groups) rather than breaking the rail.
     */
    public static function sidebarOpenGroups(int $userId): array
    {
        $prefs = self::allFor($userId);
        $decoded = json_decode($prefs['sidebar_open_groups'] ?? '[]', true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function ($key) {
            return is_string($key) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key);
        }));
    }

    public function user()
    {
        return $this->belongsTo(\App\User::class);
    }
}
