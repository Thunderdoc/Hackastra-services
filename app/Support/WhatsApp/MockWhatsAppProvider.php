<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Str;

/**
 * F1 (Round 4, design §3.2) — mock transport: makes NO HTTP call,
 * mints a local wamid.MOCK… id, and always succeeds, so the whole
 * engine (triggers, dedupe, opt-out, phone rules, sent-log) is
 * provable with zero Meta credentials.
 */
class MockWhatsAppProvider implements WhatsAppProvider
{
    public function sendTemplate(string $to, string $template, string $language, array $params): array
    {
        return [
            'ok' => true,
            'provider_message_id' => 'wamid.MOCK' . Str::random(16),
            'error_code' => null,
            'error_message' => null,
        ];
    }
}
