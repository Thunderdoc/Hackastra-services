<?php

namespace App\Support;

use App\Helpers\Qs;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Razorpay\Api\Api;

/**
 * Workstream CU — Razorpay gateway service.
 *
 * Keys live in the school Settings (razorpay_key_id / razorpay_key_secret
 * / razorpay_webhook_secret), entered by the Admin; the secret is write-
 * only from the UI's point of view and is never rendered, logged or
 * returned by this class.
 *
 * Amounts cross the gateway boundary in PAISE and are always computed
 * server-side from the student's real invoice balance — nothing the
 * browser sends can influence the amount of an order.
 *
 * Mock mode (config/gateway.php): on when GATEWAY_MOCK=true or the key
 * id is a Razorpay test key. Order creation is then local, but callback
 * and webhook signatures are produced and verified with the identical
 * HMAC-SHA256 schemes Razorpay uses, so the production verification
 * path is exactly what the tests exercise.
 */
class RazorpayGateway
{
    public static function keyId(): ?string
    {
        $v = Qs::getSetting('razorpay_key_id');
        return $v ? trim($v) : null;
    }

    protected static function keySecret(): ?string
    {
        $v = Qs::getSetting('razorpay_key_secret');
        if ($v) {
            return trim($v);
        }
        // Mock-only fallback so a fresh demo (test key, no secret saved
        // yet) can still run the signed flow end-to-end locally.
        return self::mock() ? config('gateway.mock_secret') : null;
    }

    protected static function webhookSecret(): ?string
    {
        $v = Qs::getSetting('razorpay_webhook_secret');
        if ($v) {
            return trim($v);
        }
        return self::mock() ? config('gateway.mock_secret') : null;
    }

    public static function mock(): bool
    {
        if (config('gateway.mock')) {
            return true;
        }
        $key = self::keyId();
        return $key !== null && Str::startsWith($key, 'rzp_test_');
    }

    /**
     * The feature is available only when the school switched it on AND
     * a key id exists (plus a secret, unless mock mode supplies one).
     * Every "Pay Online" surface checks this, so a disabled or
     * unconfigured gateway leaves no dead buttons anywhere.
     */
    public static function enabled(): bool
    {
        if ((string) Qs::getSetting('gateway_enabled') !== '1') {
            return false;
        }
        if (!self::keyId()) {
            return false;
        }
        return self::mock() || self::keySecret() !== null;
    }

    /**
     * Create a Razorpay order for an exact amount in rupees.
     * Returns the gateway order id. Throws on gateway failure.
     */
    public static function createOrder(int $amountRupees, string $receiptRef, array $notes = []): string
    {
        $amountPaise = $amountRupees * 100;

        if (self::mock()) {
            return 'order_MOCK' . Str::random(14);
        }

        $api = new Api(self::keyId(), self::keySecret());
        $order = $api->order->create([
            'receipt' => $receiptRef,
            'amount' => $amountPaise,
            'currency' => 'INR',
            'notes' => $notes,
        ]);

        return $order['id'];
    }

    /**
     * The signature a genuine Razorpay checkout callback carries:
     * HMAC-SHA256 of "order_id|payment_id" with the key secret. Exposed
     * so the mock checkout can sign its simulated callbacks with the
     * real scheme (the secret itself never leaves the server).
     */
    public static function signPayment(string $orderId, string $paymentId): ?string
    {
        $secret = self::keySecret();
        if (!$secret) {
            return null;
        }
        return hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    }

    public static function verifyPaymentSignature($orderId, $paymentId, $signature): bool
    {
        if (!$orderId || !$paymentId || !$signature) {
            return false;
        }
        $expected = self::signPayment((string) $orderId, (string) $paymentId);
        return $expected !== null && hash_equals($expected, (string) $signature);
    }

    public static function verifyWebhookSignature(string $rawBody, $signature): bool
    {
        $secret = self::webhookSecret();
        if (!$secret || !$signature) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, (string) $signature);
    }

    /**
     * Sign a raw webhook body the way Razorpay would — used by the mock
     * tooling/tests only; production webhooks arrive pre-signed.
     */
    public static function signWebhook(string $rawBody): ?string
    {
        $secret = self::webhookSecret();
        return $secret ? hash_hmac('sha256', $rawBody, $secret) : null;
    }

    public static function logEvent(string $message, array $context = []): void
    {
        // Context must never carry secrets or full payloads; callers pass
        // ids and amounts only.
        Log::channel('stack')->info('[gateway] ' . $message, $context);
    }
}
