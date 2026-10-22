@extends('layouts.master')
@section('page_title', 'Fees')
@section('content')

    {{-- Workstream CK: rebuilt on the shared list_page pattern
         (Loop 28) — the child's fee detail split into incomplete /
         completed exactly like the staff invoice page, minus the
         payment actions (parents are read-only). The legacy card
         with panel options, striped tables and alert boxes is
         gone; the ownership check in the controller is untouched
         and every figure and receipt link is unchanged. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            <strong>{{ $sr->user->name }}</strong> &mdash; {{ $year }} &middot;
            {{ optional($sr->my_class)->name ?: 'Class —' }} &middot; {{ optional($sr->section)->name ?: 'Section —' }}
            @if($sr->adm_no) &middot; Adm No: {{ $sr->adm_no }} @endif
            @if($total_due > 0)
                &middot; <strong class="text-danger">{{ \App\Helpers\Money::inr($total_due) }} outstanding</strong>
            @else
                &middot; <strong class="text-success">Fees clear for {{ $year }}</strong>
            @endif
        @endslot
        @slot('action')
            <a href="{{ route('my_fees') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> All Children</a>
        @endslot

        <h6 class="font-weight-bold">Incomplete Payments</h6>
        @if($uncleared->count())
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Fee</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Balance</th>
                        <th>Status</th>
                        <th>Receipt</th>
                        @if(!empty($gateway_enabled))<th>Pay</th>@endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($uncleared as $row)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="font-weight-bold">{{ $row['title'] }}</td>
                            <td class="text-right">{{ \App\Helpers\Money::inr($row['total']) }}</td>
                            <td class="text-right text-blue font-weight-bold">{{ \App\Helpers\Money::inr($row['paid']) }}</td>
                            <td class="text-right text-danger font-weight-bold">{{ \App\Helpers\Money::inr($row['balance']) }}</td>
                            <td><span class="badge {{ $row['badge'] }}">{{ $row['status'] }}</span></td>
                            <td>
                                @if($row['has_receipts'])
                                    <a target="_blank" href="{{ route('my_fees.receipt', $row['record']->id) }}"><i class="icon-printer"></i> Receipt</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            @if(!empty($gateway_enabled))
                                <td>
                                    <form method="POST" action="{{ route('my_fees.pay', $row['record']->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="icon-credit-card mr-1"></i> Pay Online {{ \App\Helpers\Money::inr($row['balance']) }}</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="2">Total</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('total')) }}</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('paid')) }}</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('balance')) }}</th>
                        <th colspan="2"></th>
                        @if(!empty($gateway_enabled))<th></th>@endif
                    </tr>
                    </tfoot>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-checkmark-circle2',
                'message' => 'No incomplete payments. Every fee for ' . $year . ' is settled.',
            ])
        @endif

        <h6 class="font-weight-bold mt-4">Completed Payments</h6>
        @if($cleared->count())
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Fee</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Paid</th>
                        <th>Status</th>
                        <th>Receipt</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($cleared as $row)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="font-weight-bold">{{ $row['title'] }}</td>
                            <td class="text-right">{{ \App\Helpers\Money::inr($row['total']) }}</td>
                            <td class="text-right text-blue font-weight-bold">{{ \App\Helpers\Money::inr($row['paid']) }}</td>
                            <td><span class="badge {{ $row['badge'] }}">{{ $row['status'] }}</span></td>
                            <td>
                                @if($row['has_receipts'])
                                    <a target="_blank" href="{{ route('my_fees.receipt', $row['record']->id) }}"><i class="icon-printer"></i> Receipt</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="2">Total</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($cleared->sum('total')) }}</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($cleared->sum('paid')) }}</th>
                        <th colspan="2"></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-coins',
                'message' => 'No completed payments for ' . $year . ' yet.',
            ])
        @endif
    @endcomponent
@endsection
