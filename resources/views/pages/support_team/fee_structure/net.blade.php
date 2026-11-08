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
                    <table class="table hk-std hk-std-640">
                        {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                        <colgroup><col style="width:36%"><col style="width:13%"><col style="width:17%"><col style="width:17%"><col style="width:17%"></colgroup>
                        <thead>
                            <tr><th>Fee Head</th><th>Session</th><th>Gross</th><th>Concession</th><th>Net Payable</th></tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td>{{ $row['head']->title }}</td>
                                    <td class="hk-nw">{{ $row['head']->year }}</td>
                                    <td class="hk-nw">{{ number_format($row['gross']) }}</td>
                                    <td class="hk-nw">{{ number_format($row['discount']) }}</td>
                                    <td class="hk-nw">{{ number_format($row['net']) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td colspan="2"><strong>Total</strong></td>
                                <td class="hk-nw"><strong>{{ number_format($total_gross) }}</strong></td>
                                <td class="hk-nw"><strong>{{ number_format($total_discount) }}</strong></td>
                                <td class="hk-nw"><strong>{{ number_format($total_net) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @else
                @include('partials.patterns.empty_state', ['icon' => 'icon-cash2', 'message' => 'No fee heads defined for this student\'s class yet.'])
            @endif
        </div>
    </div>
@endsection
