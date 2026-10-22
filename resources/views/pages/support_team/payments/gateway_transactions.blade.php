@extends('layouts.master')
@section('page_title', 'Online Payments')
@section('content')

    {{-- Workstream CU — every Razorpay transaction the gateway has
         seen, including the failed and unverifiable ones (nothing is
         silently dropped). Captured rows link to the receipt the
         payment generated through the normal payment path. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            {{ $txns->count() }} gateway transaction(s) &middot; captured money is recorded as normal fee receipts
        @endslot

        @if($txns->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-credit-card',
                'message' => 'No online payments yet. When a parent pays a fee online, the transaction appears here.',
            ])
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Invoice Ref</th>
                        <th>Gateway Order</th>
                        <th>Payment ID</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>Receipt</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($txns as $t)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $t->created_at->format('d M Y, h:i A') }}</td>
                            <td class="font-weight-bold">{{ optional($t->student)->name ?: '—' }}</td>
                            <td>{{ $t->invoice_ref ?: '—' }}</td>
                            <td><small>{{ $t->order_id }}</small></td>
                            <td><small>{{ $t->payment_id ?: '—' }}</small></td>
                            <td class="text-right">{{ \App\Helpers\Money::inr($t->amount) }}</td>
                            <td>
                                @if($t->status === 'captured')
                                    <span class="badge badge-success">Captured</span>
                                @elseif($t->status === 'verification_failed')
                                    <span class="badge badge-danger">Verification Failed</span>
                                @elseif($t->status === 'failed')
                                    <span class="badge badge-danger">Failed</span>
                                @else
                                    <span class="badge badge-warning">Initiated</span>
                                @endif
                            </td>
                            <td>
                                @if($t->receipt_id)
                                    <a target="_blank" href="{{ route('payments.receipts', \App\Helpers\Qs::hash($t->pr_id)) }}"><i class="icon-printer"></i> Receipt</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
@endsection
