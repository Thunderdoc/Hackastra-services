@extends('layouts.master')
@section('page_title', 'Pay Online')
@section('content')

    {{-- Workstream CU — gateway checkout step. The amount on this page
         was computed on the server from the invoice balance; the
         browser cannot change it. Success only counts when the
         callback's signature verifies on the server. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            <strong>{{ $pr->payment->title }}</strong> &middot; {{ $school }}
        @endslot
        @slot('action')
            <a href="{{ route('my_fees.show', $pr->student_id) }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Back to Fees</a>
        @endslot

        <div class="text-center py-4">
            <i class="icon-credit-card text-blue" style="font-size: 3rem;"></i>
            <h5 class="font-weight-bold mt-2">Pay {{ \App\Helpers\Money::inr($amount) }}</h5>
            <p class="text-muted">
                Invoice Ref: {{ $pr->ref_no }} &middot; Order: {{ $txn->order_id }}<br>
                You will complete this payment securely with Razorpay (UPI, cards, netbanking, wallets).
            </p>

            @if($mock)
                <div class="alert alert-warning d-inline-block text-left">
                    <strong>Test mode:</strong> this gateway is running with test keys / mock mode, so the
                    checkout below is simulated locally. No real money moves — but the callback you
                    send back is signed and verified exactly like a real Razorpay payment.
                </div>
                <div class="mt-3">
                    <form method="POST" action="{{ route('my_fees.pay_callback') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="razorpay_order_id" value="{{ $txn->order_id }}">
                        <input type="hidden" name="razorpay_payment_id" value="{{ $mock_payment_id }}">
                        <input type="hidden" name="razorpay_signature" value="{{ $mock_signature }}">
                        <button type="submit" class="btn btn-success btn-lg"><i class="icon-checkmark3 mr-1"></i> Simulate Successful Payment</button>
                    </form>
                    <form method="POST" action="{{ route('my_fees.pay_callback') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="razorpay_order_id" value="{{ $txn->order_id }}">
                        <input type="hidden" name="error_code" value="PAYMENT_CANCELLED">
                        <button type="submit" class="btn btn-light btn-lg">Simulate Cancel / Failure</button>
                    </form>
                </div>
            @else
                <button id="rzp-pay-btn" type="button" class="btn btn-primary btn-lg"><i class="icon-lock mr-1"></i> Pay {{ \App\Helpers\Money::inr($amount) }} Securely</button>
                <form id="rzp-callback-form" method="POST" action="{{ route('my_fees.pay_callback') }}" class="d-none">
                    @csrf
                    <input type="hidden" name="razorpay_order_id" id="rzp_order_id">
                    <input type="hidden" name="razorpay_payment_id" id="rzp_payment_id">
                    <input type="hidden" name="razorpay_signature" id="rzp_signature">
                </form>
                <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                <script>
                    (function () {
                        var options = {
                            key: @json($key_id),
                            amount: @json($amount_paise),
                            currency: 'INR',
                            name: @json($school),
                            description: @json($pr->payment->title),
                            order_id: @json($txn->order_id),
                            prefill: { name: @json($parent->name), email: @json($parent->email) },
                            handler: function (response) {
                                document.getElementById('rzp_order_id').value = response.razorpay_order_id;
                                document.getElementById('rzp_payment_id').value = response.razorpay_payment_id;
                                document.getElementById('rzp_signature').value = response.razorpay_signature;
                                document.getElementById('rzp-callback-form').submit();
                            },
                            modal: {
                                ondismiss: function () {
                                    window.location = @json(route('my_fees.show', $pr->student_id));
                                }
                            }
                        };
                        var rzp = new Razorpay(options);
                        document.getElementById('rzp-pay-btn').addEventListener('click', function () { rzp.open(); });
                    })();
                </script>
            @endif

            <p class="text-muted mt-3"><small>Nothing is recorded in your fee account until the payment is verified. If a payment fails or is cancelled, no charge is recorded.</small></p>
        </div>
    @endcomponent
@endsection
