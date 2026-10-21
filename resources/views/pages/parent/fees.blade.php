@extends('layouts.master')
@section('page_title', 'Fees')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Fees — {{ $year }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            @forelse($children as $child)
                @php($sr = $child['sr'])
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <h6 class="mb-1">
                        <i class="icon-user text-blue mr-1"></i> {{ optional($sr->user)->name }}
                        <small class="text-muted">
                            {{ optional($sr->my_class)->name ?: 'Class —' }} &middot; {{ optional($sr->section)->name ?: 'Section —' }}
                            @if($sr->adm_no) &middot; Adm No: {{ $sr->adm_no }} @endif
                        </small>
                    </h6>
                    <a href="{{ route('my_fees.show', $sr->user_id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View Details</a>
                </div>

                @if($child['rows']->isEmpty())
                    <div class="alert alert-info border-0">No fee records for {{ optional($sr->user)->name }} in {{ $year }} yet.</div>
                @else
                    <div class="table-responsive mb-4">
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th>Fee</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Paid</th>
                                <th class="text-right">Balance</th>
                                <th>Status</th>
                                <th>Receipt</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($child['rows'] as $row)
                                <tr>
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
                                <th>Total</th>
                                <th class="text-right">{{ \App\Helpers\Money::inr($child['total']) }}</th>
                                <th class="text-right">{{ \App\Helpers\Money::inr($child['paid']) }}</th>
                                <th class="text-right">{{ \App\Helpers\Money::inr($child['due']) }}</th>
                                <th colspan="2">
                                    @if($child['due'] > 0)
                                        <span class="badge badge-danger">{{ \App\Helpers\Money::inr($child['due']) }} due</span>
                                    @else
                                        <span class="badge badge-success">Fees clear</span>
                                    @endif
                                </th>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            @empty
                <div class="alert alert-info border-0 mb-0">No children are linked to your account yet. Once the school links your child&rsquo;s record to your account, their fees will appear here.</div>
            @endforelse
        </div>
    </div>
@endsection
