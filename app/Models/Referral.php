<?php

namespace App\Models;

use App\Helpers\Qs;
use App\User;
use Eloquent;

/**
 * One entry in the SA's referral log (Round 5 Slice 4, P16):
 * who referred whom, where the conversation stands, and a free
 * -text note of any reward actually agreed. SA-side only — the
 * school never submits or edits log entries.
 *
 * The school-side programme surface is driven by two settings
 * (platform-owned, see the coordinator snippets): referral_code
 * and referral_reward_text. PROGRAMME OFF is defined precisely
 * as: referral_reward_text is blank (null / empty / whitespace)
 * — regardless of whether a code exists. While OFF, every
 * school-side surface hides; the SA log stays accessible to
 * the SA either way.
 */
class Referral extends Eloquent
{
    public const STATUSES = ['logged', 'contacted', 'onboarded', 'declined'];

    protected $fillable = ['referrer_name', 'referred_name', 'status', 'reward_note', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function statusLabel(string $status): string
    {
        return [
            'logged' => 'Logged',
            'contacted' => 'Contacted',
            'onboarded' => 'Onboarded',
            'declined' => 'Declined',
        ][$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    /** The referral code the SA set or generated, or null. */
    public static function code(): ?string
    {
        $code = trim((string) Qs::getSetting('referral_code'));

        return $code !== '' ? $code : null;
    }

    /** The reward text exactly as the SA typed it, or null. */
    public static function rewardText(): ?string
    {
        $text = trim((string) Qs::getSetting('referral_reward_text'));

        return $text !== '' ? $text : null;
    }

    /** Programme ON <=> a reward text exists (see class docblock). */
    public static function programmeOn(): bool
    {
        return static::rewardText() !== null;
    }

    /**
     * A fresh readable code: HACKSTRA-XXXXXX over an unambiguous
     * alphabet (no 0/O/1/I/L), from a CSPRNG.
     */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'HACKSTRA-' . $code;
    }
}
