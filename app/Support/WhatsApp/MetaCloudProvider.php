<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Facades\Http;

/**
 * F1 (Round 4, design §3.1) — the real Meta WhatsApp Cloud API
 * transport. Graph version comes from config/whatsapp.php, never
 * hard-coded here. All failures (HTTP error, Meta error object,
 * exception/timeout) are converted to the result array — this
 * provider never throws.
 */
class MetaCloudProvider implements WhatsAppProvider
{
    public function sendTemplate(string $to, string $template, string $language, array $params): array
    {
        try {
            $token = WhatsAppGateway::accessTokenForTransport();
            $phoneNumberId = WhatsAppGateway::phoneNumberId();
            if (!$token || !$phoneNumberId) {
                return [
                    'ok' => false, 'provider_message_id' => null,
                    'error_code' => 'not_configured',
                    'error_message' => 'WhatsApp credentials are not saved yet.',
                    'transport' => false,
                ];
            }

            $parameters = [];
            foreach ($params as $p) {
                $parameters[] = ['type' => 'text', 'text' => (string) $p];
            }

            $response = Http::timeout((int) config('whatsapp.timeout', 10))
                ->withToken($token)
                ->post('https://graph.facebook.com/' . config('whatsapp.graph_version') . '/' . $phoneNumberId . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $language],
                        'components' => $parameters ? [
                            ['type' => 'body', 'parameters' => $parameters],
                        ] : [],
                    ],
                ]);

            if ($response->successful()) {
                $id = $response->json('messages.0.id');

                return [
                    'ok' => true,
                    'provider_message_id' => $id ? (string) $id : null,
                    'error_code' => null,
                    'error_message' => null,
                ];
            }

            $error = $response->json('error');
            $code = is_array($error) && isset($error['code']) ? (string) $error['code'] : 'http_' . $response->status();
            $message = is_array($error) && isset($error['message']) ? (string) $error['message'] : 'Meta API returned HTTP ' . $response->status();

            return [
                'ok' => false, 'provider_message_id' => null,
                'error_code' => substr($code, 0, 30),
                'error_message' => substr($message, 0, 500),
                'transport' => $response->status() >= 500,
            ];
        } catch (\Throwable $e) {
            // Timeout / connection failure — a transport error: the
            // drainer stops its inline pass after 2 of these.
            return [
                'ok' => false, 'provider_message_id' => null,
                'error_code' => 'transport',
                'error_message' => substr($e->getMessage(), 0, 500),
                'transport' => true,
            ];
        }
    }
}
