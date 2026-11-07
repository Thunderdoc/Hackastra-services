<?php

namespace App\Support\WhatsApp;

/**
 * F1 (Round 4, design §5) — parent phone normaliser.
 *
 * Seeded/real parent phones are free-form ("+91 98110 00006",
 * spaces included) and are NOT dialable by the Cloud API as
 * stored. Rules, in order: strip everything except digits and a
 * leading '+' → drop '+' → drop a leading '0' → if exactly 10
 * digits, prefix the configured default country code → accept
 * only ^[1-9]\d{10,14}$ (E.164 without '+') → else null.
 * Placeholder/garbage (e.g. 0123456789) normalises to null,
 * which the engine records as skipped_no_phone.
 */
class WhatsAppPhone
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim($raw);
        if ($s === '') {
            return null;
        }

        // Keep digits and '+' only, then drop every '+' (a leading
        // one is the international marker; the API wants bare digits).
        $s = preg_replace('/[^\d+]/', '', $s);
        $s = str_replace('+', '', (string) $s);
        if ($s === '') {
            return null;
        }

        // Drop a leading trunk 0 (09811000006 → 9811000006).
        if (strpos($s, '0') === 0) {
            $s = substr($s, 1);
        }

        // Bare local number → add the default country code.
        if (strlen($s) === 10) {
            $s = (string) config('whatsapp.default_country_code', '91') . $s;
        }

        return preg_match('/^[1-9]\d{10,14}$/', $s) ? $s : null;
    }
}
