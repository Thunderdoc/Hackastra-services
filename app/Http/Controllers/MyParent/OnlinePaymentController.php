<?php

namespace App\Http\Controllers\MyParent;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\GatewayTransaction;
use App\Repositories\PaymentRepo;
use App\Support\Audit;
use App\Support\OnlinePaymentRecorder;
use App\Support\RazorpayGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Workstream CU — parent online fee payment (Razorpay).
 *
 * Flow: Pay Online (on the child's fee page) → order created here for
 * the SERVER-COMPUTED outstanding balance → checkout view (Razorpay
 * hosted JS in live mode, a local simulation in mock mode) → callback
 * verified by HMAC signature → payment recorded through the same
 * bookkeeping as a counter payment → existing receipt page.
 *
 * The browser is never trusted: not for the amount (computed here
 * from the invoice), not for success (only a valid signature counts).
 */
class OnlinePaymentController extends Controller
{
    protected $pay;

    public function __construct(PaymentRepo $pay)
    {
        $this->pay = $pay;
    }

    public function checkout($pr_id)
    {
        if (!RazorpayGateway::enabled()) {
            return redirect()->route('my_fees')->with('flash_danger', 'Online payment is not available right now.');
        }

        $pr = $this->pay->findRecord($pr_id);
        if (!$pr) {
            return redirect()->route('my_fees')->with('flash_danger', __('msg.rnf'));
        }
        // Same record-level check the parent receipt page uses: the
        // record's student must be this parent's child.
        $this->authorize('view', $pr);

        $fee = (int) ($pr->payment ? $pr->payment->amount : 0);
        $outstanding = $fee - (int) ($pr->amt_paid ?: 0);
        if ($outstanding < 1) {
            return redirect()->route('my_fees.show', $pr->student_id)
                ->with('flash_success', 'This fee is already fully paid.');
        }

        try {
            $orderId = RazorpayGateway::createOrder($outstanding, 'rcpt_' . $pr->ref_no, [
                'student_id' => (string) $pr->student_id,
                'pr_id' => (string) $pr->id,
            ]);
        } catch (\Throwable $e) {
            RazorpayGateway::logEvent('order creation failed', ['pr_id' => $pr->id]);
            return redirect()->route('my_fees.show', $pr->student_id)
                ->with('flash_danger', 'The payment gateway could not be reached. Please try again later.');
        }

        $txn = GatewayTransaction::create([
            'gateway' => 'razorpay',
            'order_id' => $orderId,
            'student_id' => $pr->student_id,
            'pr_id' => $pr->id,
            'invoice_ref' => $pr->ref_no,
            'amount' => $outstanding,
            'status' => 'initiated',
        ]);

        $d['txn'] = $txn;
        $d['pr'] = $pr;
        $d['amount'] = $outstanding;
        $d['amount_paise'] = $outstanding * 100;
        $d['key_id'] = RazorpayGateway::keyId();
        $d['mock'] = RazorpayGateway::mock();
        $d['school'] = Qs::getSystemName();
        $d['parent'] = Auth::user();
        // In mock mode the simulated checkout must still arrive signed:
        // pre-sign a mock payment id here, server-side, with the real
        // scheme. The live checkout gets its signature from Razorpay.
        $d['mock_payment_id'] = $d['mock'] ? 'pay_MOCK' . Str::random(14) : null;
        $d['mock_signature'] = $d['mock']
            ? RazorpayGateway::signPayment($orderId, $d['mock_payment_id'])
            : null;

        return view('pages.parent.pay_checkout', $d);
    }

    public function callback(Request $req)
    {
        $orderId = (string) $req->input('razorpay_order_id', '');
        $paymentId = (string) $req->input('razorpay_payment_id', '');
        $signature = (string) $req->input('razorpay_signature', '');

        $txn = GatewayTransaction::where('order_id', $orderId)->first();

        if (!$txn) {
            return redirect()->route('my_fees')->with('flash_danger', 'Payment could not be verified. Nothing was charged to your account records.');
        }

        // The transaction must belong to a child of the signed-in parent.
        if (!Qs::userIsMyChild($txn->student_id, Auth::user()->id)) {
            abort(403);
        }

        if ($req->filled('error_code') || !RazorpayGateway::verifyPaymentSignature($orderId, $paymentId, $signature)) {
            if ($txn->status !== 'captured') {
                $txn->status = 'verification_failed';
                $txn->save();
            }
            Audit::log('payment.gateway_verification_failed', $txn,
                'Callback signature check failed for order ' . $orderId);
            RazorpayGateway::logEvent('callback signature verification failed', ['order_id' => $orderId]);
            return redirect()->route('my_fees.show', $txn->student_id)
                ->with('flash_danger', 'Payment verification failed. No payment has been recorded. If money left your account, it will be reconciled automatically.');
        }

        $txn = OnlinePaymentRecorder::record($txn, $paymentId);

        if ($txn->status === 'captured' && $txn->receipt_id) {
            return redirect()->route('my_fees.receipt', $txn->pr_id)
                ->with('flash_success', 'Payment successful. Your receipt is ready.');
        }

        return redirect()->route('my_fees.show', $txn->student_id)
            ->with('flash_success', 'Payment received and confirmed.');
    }
}
