@extends('layouts.master')
@section('page_title', 'Collection — ' . $date->format('d M Y'))
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Payments &nbsp;/&nbsp; <a href="{{ route('payments.collection', $f['query']) }}">Fee Collection Report</a> &nbsp;/&nbsp; {{ $date->format('d M Y') }}
@endsection
@section('content')

    {{-- Loop 97: per-day receipts breakdown behind the collection
         report's "View Details" action. Same filters, same ledger —
         every receipt recorded on the chosen date, each linking to
         its real receipt document. --}}
    <div class="cr97-wrap">
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Receipts recorded on {{ $date->format('l, d M Y') }}@if($f['class']) for {{ $f['class']->name }}@endif @if($f['section']) &middot; {{ $f['section']->name }}@endif.
        @endslot
        @slot('action')
            <a href="{{ route('payments.collection', $f['query']) }}" class="btn btn-light btn-sm"><i class="icon-arrow-left8 mr-1"></i> Back to Report</a>
        @endslot
        @slot('stats')
            <div class="row dashboard-stats">
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-green">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-wallet"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Collected on {{ $date->format('d M Y') }}</span>
                            <strong>{{ \App\Helpers\Money::inr($day_total) }}</strong>
                            <small>{{ number_format($day_count) }} receipt(s)</small>
                        </span>
                    </div>
                </div>
            </div>
        @endslot

        @if($receipts->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-coins',
                'message' => 'No receipts were recorded on ' . $date->format('d M Y') . ' for the selected filters.',
            ])
        @else
            <table class="table">
                <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Student</th>
                    <th>Admission No</th>
                    <th>Class &amp; Section</th>
                    <th>Fee</th>
                    <th>Time</th>
                    <th class="text-right">Amount Paid</th>
                    <th class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($receipts as $receipt)
                    @php
                        $pr = $receipt->pr;
                        $sr = $pr ? $records->get($pr->student_id) : null;
                        $payment = $pr ? $payments->get($pr->payment_id) : null;
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ optional(optional($sr)->user)->name ?? '—' }}</strong></td>
                        <td>{{ optional($sr)->adm_no ?? '—' }}</td>
                        <td>{{ optional(optional($sr)->my_class)->name ?? '—' }}@if($sr && $sr->section) &middot; {{ $sr->section->name }}@endif</td>
                        <td>{{ optional($payment)->title ?? '—' }}</td>
                        <td>{{ $receipt->created_at->format('h:i A') }}</td>
                        <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($receipt->amt_paid) }}</td>
                        <td class="text-right">
                            @if($pr)
                                <a target="_blank" href="{{ route('payments.receipts', Qs::hash($pr->id)) }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Receipt</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <th></th>
                    <th colspan="5">Total for {{ $date->format('d M Y') }}</th>
                    <th class="text-right">{{ \App\Helpers\Money::inr($day_total) }}</th>
                    <th></th>
                </tr>
                </tfoot>
            </table>
        @endif
    @endcomponent
    </div>

@endsection
