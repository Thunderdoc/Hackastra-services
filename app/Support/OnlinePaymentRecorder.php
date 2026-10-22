<?php

namespace App\Support;

use App\Models\GatewayTransaction;
use App\Models\PaymentRecord;
use App\Repositories\PaymentRepo;
use Illuminate\Support\Facades\DB;

/**
 * Workstream CU — turns a VERIFIED gateway payment into a school
 * payment, through the exact bookkeeping PaymentController@pay_now
 * performs at the counter: PaymentRecord amounts updated, a Receipt
 * row created, an audit entry written. Receipts, balances, CSV
 * exports and the collection/defaulters reports therefore treat an
 * online payment identically to a manual one.
 *
 * Idempotency: gateway_transactions.payment_id is UNIQUE and this
 * method runs inside a transaction with row locks. Whether the
 * browser callback or the webhook arrives first, the second call
 * finds the transaction already captured and confirms state without
 * recording anything twice.
 */
class OnlinePaymentRecorder
{
    public static function record(GatewayTransaction $txn, string $paymentId, ?string $rawPayload = null): GatewayTransaction
    {
        return DB::transaction(function () use ($txn, $paymentId, $rawPayload) {
            $txn = GatewayTransaction::where('id', $txn->id)->lockForUpdate()->first();

            if ($rawPayload) {
                $txn->payload_hash = hash('sha256', $rawPayload);
            }

            // Already captured (by callback or webhook): confirm, no-op.
            if ($txn->status === 'captured' && $txn->payment_id === $paymentId) {
                $txn->save();
                return $txn;
            }

            // A different transaction already owns this payment id.
            $dupe = GatewayTransaction::where('payment_id', $paymentId)
                ->where('id', '!=', $txn->id)->first();
            if ($dupe) {
                $txn->status = 'failed';
                $txn->save();
                Audit::log('payment.gateway_duplicate', $txn,
                    'Duplicate gateway payment id rejected: ' . $paymentId);
                return $txn;
            }

            $pr = PaymentRecord::where('id', $txn->pr_id)->lockForUpdate()->first();
            if (!$pr) {
                $txn->status = 'failed';
                $txn->save();
                return $txn;
            }

            $fee = (int) ($pr->payment ? $pr->payment->amount : 0);
            $outstanding = $fee - (int) ($pr->amt_paid ?: 0);
            $apply = min((int) $txn->amount, max($outstanding, 0));

            $txn->payment_id = $paymentId;
            $txn->status = 'captured';

            if ($apply < 1) {
                // Invoice was settled another way (e.g. at the counter)
                // before this payment landed. Capture the fact, take no
                // money twice, and leave an audit trail for refund.
                $txn->save();
                Audit::log('payment.gateway_overpaid', $txn,
                    'Gateway payment ' . $paymentId . ' arrived for an already-settled invoice; no receipt issued');
                return $txn;
            }

            $pay = app(PaymentRepo::class);

            $newPaid = (int) ($pr->amt_paid ?: 0) + $apply;
            $balance = $fee - $newPaid;

            $pay->updateRecord($pr->id, [
                'amt_paid' => $newPaid,
                'balance' => $balance,
                'paid' => $balance < 1 ? 1 : 0,
            ]);

            $receipt = $pay->createReceipt([
                'amt_paid' => $apply,
                'balance' => $balance,
                'pr_id' => $pr->id,
                'year' => $pr->year,
                'method' => 'online_razorpay',
                'gateway_ref' => $paymentId,
            ]);

            $txn->receipt_id = $receipt ? $receipt->id : null;
            $txn->save();

            Audit::log('payment.recorded', $pr,
                'Online payment via Razorpay: ' . $apply . '; payment ' . $paymentId . '; balance ' . $balance);

            return $txn;
        });
    }
}
