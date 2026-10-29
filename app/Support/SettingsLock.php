<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\SettingsUnlockRequest;
use Illuminate\Support\Facades\Schema;

/**
 * The school settings lock (owner order 2026-10-29).
 *
 * The Admin configures the school, then locks the settings:
 * from that moment every settings save is refused until a
 * written unlock request passes BOTH approvers — the
 * Principal first, then the Super Admin. Approval clears the
 * lock and the Admin edits freely again (and can re-lock).
 * The state is one settings row, so it is per-school by
 * construction in this single-school install.
 */
class SettingsLock
{
    public const LOCKED_MESSAGE = 'Settings are locked. To edit them, send an unlock request from the Settings page — the Principal and then the Super Admin must approve it before editing opens again.';

    public static function isLocked(): bool
    {
        if (!Schema::hasTable('settings')) {
            return false;
        }

        return Setting::where('type', 'settings_locked')->value('description') === '1';
    }

    public static function lock(): void
    {
        Setting::updateOrCreate(['type' => 'settings_locked'], ['description' => '1']);
    }

    public static function unlock(): void
    {
        Setting::updateOrCreate(['type' => 'settings_locked'], ['description' => '0']);
    }

    /** The currently open (undecided) unlock request, if any. */
    public static function openRequest(): ?SettingsUnlockRequest
    {
        if (!Schema::hasTable('settings_unlock_requests')) {
            return null;
        }

        return SettingsUnlockRequest::whereIn('status', ['pending_principal', 'pending_super_admin'])
            ->latest()->first();
    }
}
