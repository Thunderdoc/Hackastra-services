<?php

namespace App\Support\WhatsApp;

use App\Helpers\Qs;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * F1 (Round 4, design §3.1) — WhatsApp gateway settings access,
 * mirroring App\Support\RazorpayGateway. Credentials live in the
 * school Settings (whatsapp_* keys); the access token and app
 * secret are never rendered to any blade, never logged and never
 * returned to a UI surface — the two accessors below exist ONLY
 * for the transport (sending) and the webhook (signature check).
 *
 * Deliberate difference from Razorpay: with the feature enabled
 * but unconfigured, the engine runs in MOCK instead of hiding —
 * every event still produces a `mocked` log row, so the owner
 * can see exactly what would be sent before any Meta account
 * exists (the AiAssist::usingMock precedent).
 */
class WhatsAppGateway
{
    /** Event → its Settings toggle key (design §7). */
    public const EVENT_TOGGLES = [
        'attendance_absent' => 'whatsapp_absent_enabled',
        'fee_reminder' => 'whatsapp_fee_enabled',
        'result_published' => 'whatsapp_result_enabled',
        'circular_published' => 'whatsapp_circular_enabled',
        'assignment_created' => 'whatsapp_homework_enabled',
    ];

    public static function phoneNumberId(): ?string
    {
        $v = Qs::getSetting('whatsapp_phone_number_id');

        return $v && trim($v) !== '' ? trim($v) : null;
    }

    public static function businessId(): ?string
    {
        $v = Qs::getSetting('whatsapp_business_id');

        return $v && trim($v) !== '' ? trim($v) : null;
    }

    public static function templateLanguage(): string
    {
        $v = Qs::getSetting('whatsapp_template_language');

        return $v && trim($v) !== '' ? trim($v) : 'en';
    }

    /** Transport-only: the permanent access token. Never render/log. */
    public static function accessTokenForTransport(): ?string
    {
        $v = Qs::getSetting('whatsapp_access_token');

        return $v && trim($v) !== '' ? trim($v) : null;
    }

    /** Webhook-only: the app secret used to verify signatures. Never render/log. */
    public static function appSecretForWebhook(): ?string
    {
        $v = Qs::getSetting('whatsapp_app_secret');

        return $v && trim($v) !== '' ? trim($v) : null;
    }

    /** Webhook-only: the school-chosen verification string. Never render/log. */
    public static function verifyTokenForWebhook(): ?string
    {
        $v = Qs::getSetting('whatsapp_verify_token');

        return $v && trim($v) !== '' ? trim($v) : null;
    }

    /**
     * Mock when: forced by config (WHATSAPP_MOCK), OR no access
     * token saved, OR no phone-number-id saved, OR the token
     * starts with test_ (the rzp_test_ idea, adapted — an admin
     * can paste a placeholder token to force simulation).
     */
    public static function mock(): bool
    {
        if (config('whatsapp.mock')) {
            return true;
        }
        $token = self::accessTokenForTransport();
        if ($token === null || self::phoneNumberId() === null) {
            return true;
        }

        return Str::startsWith($token, 'test_');
    }

    public static function masterEnabled(): bool
    {
        return (string) Qs::getSetting('whatsapp_enabled') === '1';
    }

    /**
     * A per-event toggle gates its event only when it has been
     * explicitly saved as '0' — an unsaved toggle means ON, so
     * flipping just the master switch runs the whole feature.
     */
    public static function eventEnabled(string $event): bool
    {
        $key = self::EVENT_TOGGLES[$event] ?? null;
        if ($key === null) {
            return true;
        }
        $v = Qs::getSetting($key);

        return $v === null || (string) $v === '1';
    }

    public static function enabled(?string $event = null): bool
    {
        if (!self::masterEnabled()) {
            return false;
        }

        return $event === null || self::eventEnabled($event);
    }

    /** The settings-card status line (design §7d). */
    public static function statusLabel(): string
    {
        if (!self::masterEnabled()) {
            return 'Disabled';
        }

        return self::mock()
            ? 'MOCK mode — messages are logged, not sent (no Meta credentials saved)'
            : 'Live';
    }

    public static function logEvent(string $message, array $context = []): void
    {
        // Context must never carry secrets or full phone numbers;
        // callers pass ids, statuses and last-4 digits only.
        Log::channel('stack')->info('[whatsapp] ' . $message, $context);
    }
}
