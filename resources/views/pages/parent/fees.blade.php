@extends('layouts.master')
@section('page_title', 'Fees')
@section('content')

    {{-- Workstream CK: rebuilt on the shared pattern chrome
         (Loop 28) — toolbar + one card — replacing the legacy card
         with panel options, striped tables and alert boxes. The
         portal stays read-only and the isolation is untouched:
         the controller scopes every row to this parent's linked
         children. All figures and receipt links are unchanged. --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub">Session {{ $year }} &mdash; fees for your children, as recorded by the school office.</div>
            </div>
        </div>

        <div class="card pat-card">
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
                        <span>
                            @if(!empty($gateway_enabled) && $child['due'] > 0)
                                <a href="{{ route('my_fees.show', $sr->user_id) }}" class="btn btn-primary btn-sm"><i class="icon-credit-card mr-1"></i> Pay Online</a>
                            @endif
                            <a href="{{ route('my_fees.show', $sr->user_id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View Details</a>
                        </span>
                    </div>

                    @if($child['rows']->isEmpty())
                        @include('partials.patterns.empty_state', [
                            'icon' => 'icon-coins',
                            'message' => 'No fee records for ' . optional($sr->user)->name . ' in ' . $year . ' yet.',
                        ])
                    @else
                        <div class="table-responsive mb-4">
                            <table class="table">
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
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-users',
                        'message' => 'No children are linked to your account yet. Once the school links your child’s record to your account, their fees will appear here.',
                    ])
                @endforelse
            </div>
        </div>
    </div>
@endsection
