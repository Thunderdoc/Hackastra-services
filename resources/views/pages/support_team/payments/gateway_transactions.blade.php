@extends('layouts.master')
@section('page_title', 'Online Payments')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Finance &nbsp;/&nbsp; <a href="{{ route('payments.index') }}">Manage Payments</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')

    {{-- Workstream CU — every Razorpay transaction the gateway has
         seen, including the failed and unverifiable ones (nothing is
         silently dropped). Captured rows link to the receipt the
         payment generated through the normal payment path. --}}
  <div class="l75-gw">
    @component('partials.patterns.list_page', ['pat_no_wrap' => true])
        @slot('subtitle')
            {{ $txns->count() }} gateway transaction(s) &middot; captured money is recorded as normal fee receipts
        @endslot

        @if($txns->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-credit-card',
                'message' => 'No online payments yet. When a parent pays a fee online, the transaction appears here.',
            ])
        @else
            @component('partials.patterns.data_table', [
                'columns' => [
                    ['key' => 'sn', 'label' => '#', 'width' => 5, 'class' => 'hk-nw'],
                    ['key' => 'date', 'label' => 'Date', 'width' => 14, 'class' => 'hk-nw'],
                    ['key' => 'student', 'label' => 'Student', 'width' => 15],
                    ['key' => 'invoice_ref', 'label' => 'Invoice Ref', 'width' => 11, 'class' => 'hk-ellip'],
                    ['key' => 'order', 'label' => 'Gateway Order', 'width' => 15, 'class' => 'hk-ellip'],
                    ['key' => 'payment_id', 'label' => 'Payment ID', 'width' => 15, 'class' => 'hk-ellip'],
                    ['key' => 'amount', 'label' => 'Amount', 'width' => 10, 'class' => 'hk-nw', 'align' => 'right'],
                    ['key' => 'status', 'label' => 'Status', 'width' => 9, 'class' => 'hk-nw'],
                    ['key' => 'receipt', 'label' => 'Receipt', 'width' => 6, 'align' => 'center'],
                ],
                'variant' => 'gateway',
                'min_width' => 1080,
            ])
                @foreach($txns as $t)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $t->created_at->format('d M Y, h:i A') }}</td>
                        <td class="font-weight-bold">{{ optional($t->student)->name ?: '—' }}</td>
                        <td>{{ $t->invoice_ref ?: '—' }}</td>
                        <td><small class="gw-id">{{ $t->order_id }}</small></td>
                        <td><small class="gw-id">{{ $t->payment_id ?: '—' }}</small></td>
                        <td class="text-right gw-amount">{{ \App\Helpers\Money::inr($t->amount) }}</td>
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
            @endcomponent
        @endif
    @endcomponent
  </div>
@endsection
