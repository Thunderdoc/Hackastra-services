@extends('layouts.master')
@section('page_title', 'Net Fees')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder &nbsp;/&nbsp; Net Fees
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Net Fees — {{ optional($sr->user)->name }} ({{ optional($sr->my_class)->name }}, {{ $sr->adm_no }})</h5>
            <a href="{{ route('fee_structure.net.index') }}" class="btn btn-secondary">Back to Students</a>
        </div>
        <div class="card-body">
            @if($rows->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Fee Head</th><th>Session</th><th>Gross</th><th>Concession</th><th>Net Payable</th></tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td>{{ $row['head']->title }}</td>
                                    <td>{{ $row['head']->year }}</td>
                                    <td>{{ number_format($row['gross']) }}</td>
                                    <td>{{ number_format($row['discount']) }}</td>
                                    <td>{{ number_format($row['net']) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td colspan="2"><strong>Total</strong></td>
                                <td><strong>{{ number_format($total_gross) }}</strong></td>
                                <td><strong>{{ number_format($total_discount) }}</strong></td>
                                <td><strong>{{ number_format($total_net) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @else
                <p>No fee heads defined for this student's class yet.</p>
            @endif
        </div>
    </div>
@endsection
