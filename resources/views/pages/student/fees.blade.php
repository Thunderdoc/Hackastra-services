@extends('layouts.master')
@section('page_title', 'My Fees')
@section('content')

    {{-- Student fees portal (2026-10-23): the student's own fee
         statement for the current session on the shared list_page
         pattern — the same split (incomplete / completed), figures
         and receipt links as the parent's per-child page, minus
         every payment action (paying stays with parents and the
         school office). Scope is server-side: the controller loads
         only the signed-in student's records. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            <strong>{{ $sr->user->name }}</strong> &mdash; {{ $year }} &middot;
            {{ optional($sr->my_class)->name ?: 'Class —' }} &middot; {{ optional($sr->section)->name ?: 'Section —' }}
            @if($sr->adm_no) &middot; Adm No: {{ $sr->adm_no }} @endif
            @if($total_due > 0)
                &middot; <strong class="text-danger">{{ \App\Helpers\Money::inr($total_due) }} outstanding</strong>
            @else
                &middot; <strong class="text-success">Your fees are clear for {{ $year }}</strong>
            @endif
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
                                    <a target="_blank" href="{{ route('student.fees.receipt', $row['record']->id) }}"><i class="icon-printer"></i> Receipt</a>
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
                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('total')) }}</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('paid')) }}</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('balance')) }}</th>
                        <th colspan="2"></th>
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
                                    <a target="_blank" href="{{ route('student.fees.receipt', $row['record']->id) }}"><i class="icon-printer"></i> Receipt</a>
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
