<?php

namespace App\Support\WhatsApp;

/**
 * F1 (Round 4, design §3.1) — the one transport operation.
 * Everything else (dedupe, opt-out, caps, retries) is engine
 * policy in App\Support\ParentWhatsApp.
 *
 * Implementations NEVER throw: every failure is converted to the
 * result array, following App\Support\Audit's law.
 */
interface WhatsAppProvider
{
    /**
     * @param  string[]  $params  ordered template variable values (plain text)
     * @return array{ok:bool, provider_message_id:?string, error_code:?string, error_message:?string, transport?:bool}
     */
    public function sendTemplate(string $to, string $template, string $language, array $params): array;
}
