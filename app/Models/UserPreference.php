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
        'date_format',
        'time_format',
        // Loop 50 (Workstream BX): the topbar dark-mode toggle writes
        // this key through MyAccountController@update_theme — same
        // per-user store, values 'dark'/'light'.
        'theme',
    ];

    public static function defaults(): array
    {
        return [
            'notify_email' => '1',
            'notify_announcements' => '1',
            'notify_attendance' => '1',
            'notify_exams' => '1',
            'date_format' => 'd M Y',
            'time_format' => '12',
            // Owner directive 2026-10-22 ("dark mode for all"): accounts
            // that never chose get dark; a stored 'light' choice wins.
            'theme' => 'dark',
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

    public function user()
    {
        return $this->belongsTo(\App\User::class);
    }
}
