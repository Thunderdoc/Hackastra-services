<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use App\Models\WhatsAppMessage;
use App\Support\WhatsApp\WhatsAppGateway;
use App\Support\WhatsApp\WhatsAppPhone;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * F1 (Round 4, design §10) — Meta WhatsApp webhook receiver,
 * beside GatewayWebhookController with the same discipline:
 * public by necessity, so trust comes exclusively from the
 * signature — X-Hub-Signature-256 = HMAC-SHA256(raw body,
 * whatsapp_app_secret), verified BEFORE parsing.
 *
 * GET  — Meta verification: echo hub.challenge only when
 *        hub.verify_token matches the saved setting.
 * POST — delivery statuses update rows by provider_message_id
 *        (unknown wamids ignored, idempotent); an inbound text
 *        STOP (case-insensitive) from a parent's number sets
 *        that parent's notify_whatsapp='0'.
 *
 * In mock mode (no app secret saved) POST accepts nothing —
 * returns 200 ignored — so an unconfigured install exposes no
 * unauthenticated write path.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $req)
    {
        // Meta sends hub.mode / hub.verify_token / hub.challenge —
        // dots in query keys may arrive literally or underscored
        // depending on the SAPI, so read both spellings.
        $query = [];
        foreach ((array) $req->query() as $k => $v) {
            $query[str_replace('.', '_', (string) $k)] = $v;
        }
        $expected = WhatsAppGateway::verifyTokenForWebhook();
        $given = (string) ($query['hub_verify_token'] ?? '');
        $challenge = $query['hub_challenge'] ?? '';

        if ($expected !== null && $given !== '' && hash_equals($expected, $given)) {
            return response((string) $challenge, 200);
        }

        return response('forbidden', 403);
    }

    public function handle(Request $req)
    {
        $secret = WhatsAppGateway::appSecretForWebhook();
        if ($secret === null) {
            // Mock mode: no secret, no writes.
            return response()->json(['status' => 'ignored']);
        }

        $raw = $req->getContent();
        $signature = (string) $req->header('X-Hub-Signature-256', '');
        $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            WhatsAppGateway::logEvent('webhook rejected: bad signature');

            return response()->json(['status' => 'invalid signature'], 400);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return response()->json(['status' => 'ignored']);
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->applyStatus($status);
                }
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $this->applyInbound($message);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /** Delivery status → row update by wamid. Forward-only for sent/delivered/read; failed always lands unless already read. */
    protected function applyStatus(array $status): void
    {
        try {
            $wamid = $status['id'] ?? null;
            $state = $status['status'] ?? null;
            if (!$wamid || !in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                return;
            }
            if (!Schema::hasTable('whatsapp_messages')) {
                return;
            }
            $row = WhatsAppMessage::where('provider_message_id', $wamid)->first();
            if (!$row) {
                WhatsAppGateway::logEvent('webhook for unknown wamid', ['wamid' => (string) $wamid]);
                return;
            }

            $rank = ['queued' => 0, 'mocked' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 2];
            if ($state === 'failed') {
                if ($row->status === 'read') {
                    return;
                }
                $error = $status['errors'][0] ?? [];
                $row->status = 'failed';
                $row->error_code = isset($error['code']) ? substr((string) $error['code'], 0, 30) : $row->error_code;
                $row->error_message = isset($error['title']) ? substr((string) $error['title'], 0, 500)
                    : (isset($error['message']) ? substr((string) $error['message'], 0, 500) : $row->error_message);
                $row->save();
                return;
            }

            if (($rank[$state] ?? 0) > ($rank[$row->status] ?? 0)) {
                $row->status = $state;
                $row->save();
            }
        } catch (\Throwable $e) {
            // A webhook must never 500 Meta into a retry storm over one row.
        }
    }

    /** Inbound text: STOP (case-insensitive) from a parent's number opts that parent out. Anything else is ignored (v1 is not a chatbot). */
    protected function applyInbound(array $message): void
    {
        try {
            $body = trim((string) ($message['text']['body'] ?? ''));
            if (strtoupper($body) !== 'STOP') {
                return;
            }
            $from = WhatsAppPhone::normalize((string) ($message['from'] ?? ''));
            if ($from === null || !Schema::hasTable('user_preferences')) {
                return;
            }
            $parents = User::where('user_type', 'parent')->get(['id', 'phone', 'phone2']);
            foreach ($parents as $parent) {
                $candidate = WhatsAppPhone::normalize($parent->phone ?: null)
                    ?: WhatsAppPhone::normalize($parent->phone2 ?: null);
                if ($candidate !== null && hash_equals($candidate, $from)) {
                    UserPreference::set((int) $parent->id, 'notify_whatsapp', '0');
                }
            }
        } catch (\Throwable $e) {
            // Ignored — see applyStatus.
        }
    }
}
