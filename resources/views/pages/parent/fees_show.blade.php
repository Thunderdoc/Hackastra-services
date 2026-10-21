@extends('layouts.master')
@section('page_title', 'Fees')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <span class="text-muted">{{ $sr->user->name }} &mdash; {{ $year }}</span>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <a href="{{ route('my_fees') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> All Children</a>
            </div>
        </div>

        <div class="card-body">
            <p class="text-muted">
                {{ optional($sr->my_class)->name ?: 'Class —' }} &middot; {{ optional($sr->section)->name ?: 'Section —' }}
                @if($sr->adm_no) &middot; Adm No: {{ $sr->adm_no }} @endif
                @if($total_due > 0)
                    &middot; <strong class="text-danger">{{ \App\Helpers\Money::inr($total_due) }} outstanding</strong>
                @else
                    &middot; <strong class="text-success">Fees clear for {{ $year }}</strong>
                @endif
            </p>

            <h6 class="font-weight-bold mt-4">Incomplete Payments</h6>
            @if($uncleared->count())
                <div class="table-responsive">
                    <table class="table table-striped">
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
                            <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('total')) }}</th>
                            <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('paid')) }}</th>
                            <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('balance')) }}</th>
                            <th colspan="2"></th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <div class="alert alert-success border-0">No incomplete payments. Every fee for {{ $year }} is settled.</div>
            @endif

            <h6 class="font-weight-bold mt-4">Completed Payments</h6>
            @if($cleared->count())
                <div class="table-responsive">
                    <table class="table table-striped">
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
                <div class="alert alert-info border-0">No completed payments for {{ $year }} yet.</div>
            @endif
        </div>
    </div>
@endsection
