<?php

namespace App\Http\Controllers;

use App\Models\GatewayTransaction;
use App\Support\Audit;
use App\Support\OnlinePaymentRecorder;
use App\Support\RazorpayGateway;
use Illuminate\Http\Request;

/**
 * Workstream CU — Razorpay webhook receiver.
 *
 * Public by necessity (Razorpay's servers call it), so trust comes
 * exclusively from the webhook signature: HMAC-SHA256 of the RAW body
 * with the webhook secret from Settings. Anything unsigned or badly
 * signed is rejected before parsing. Handling is idempotent via
 * OnlinePaymentRecorder — a payment already recorded by the browser
 * callback is confirmed, never duplicated.
 */
class GatewayWebhookController extends Controller
{
    public function razorpay(Request $req)
    {
        $raw = $req->getContent();
        $signature = $req->header('X-Razorpay-Signature');

        if (!RazorpayGateway::verifyWebhookSignature($raw, $signature)) {
            RazorpayGateway::logEvent('webhook rejected: bad signature');
            return response()->json(['status' => 'invalid signature'], 400);
        }

        $payload = json_decode($raw, true);
        $event = $payload['event'] ?? null;
        $entity = $payload['payload']['payment']['entity'] ?? null;

        if (!$entity || empty($entity['order_id'])) {
            return response()->json(['status' => 'ignored']);
        }

        $txn = GatewayTransaction::where('order_id', $entity['order_id'])->first();
        if (!$txn) {
            RazorpayGateway::logEvent('webhook for unknown order', ['order_id' => $entity['order_id']]);
            return response()->json(['status' => 'unknown order']);
        }

        if ($event === 'payment.captured' && !empty($entity['id'])) {
            OnlinePaymentRecorder::record($txn, (string) $entity['id'], $raw);
        } elseif ($event === 'payment.failed') {
            if ($txn->status !== 'captured') {
                $txn->status = 'failed';
                $txn->payload_hash = hash('sha256', $raw);
                $txn->save();
            }
            Audit::log('payment.gateway_failed', $txn, 'Gateway reported payment failed for order ' . $txn->order_id);
        }

        return response()->json(['status' => 'ok']);
    }
}
