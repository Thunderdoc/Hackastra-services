@extends('layouts.master')
@section('page_title', 'Fee Collection Report')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('payments.collection') }}" class="form-inline mb-3">
                <label class="mr-2">From</label>
                <input type="date" name="from" class="form-control mr-3" value="{{ $from->format('Y-m-d') }}">
                <label class="mr-2">To</label>
                <input type="date" name="to" class="form-control mr-3" value="{{ $to->format('Y-m-d') }}">
                <button type="submit" class="btn btn-primary btn-sm"><i class="icon-filter3 mr-1"></i> Filter</button>
                <span class="text-muted ml-3">{{ $receipts_count }} receipt(s) between {{ $from->format('d M Y') }} and {{ $to->format('d M Y') }}</span>
            </form>

            @if($rows->isEmpty())
                <div class="alert alert-info border-0 mb-0">
                    No fee collections were recorded between {{ $from->format('d M Y') }} and {{ $to->format('d M Y') }}.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Receipts</th>
                            <th class="text-right">Collected</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td><strong>{{ \Carbon\Carbon::parse($row->day)->format('d M Y') }}</strong></td>
                                <td>{{ $row->receipts_count }}</td>
                                <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($row->total) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr>
                            <th>Grand Total</th>
                            <th>{{ $receipts_count }}</th>
                            <th class="text-right">{{ \App\Helpers\Money::inr($grand_total) }}</th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="text-muted mb-0"><small>From the receipts ledger: one receipt is recorded per payment taken, dated the day the money was received.</small></p>
            @endif
        </div>
    </div>
@endsection
