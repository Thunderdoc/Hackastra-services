@extends('layouts.master')
@section('page_title', 'Fee Defaulters')
@section('content')
    {{-- Workstream BT: print-only document header/footer — the same
         print-doc standard as the students list (Loop 31). Hidden on
         screen; window.print() now produces a branded school report
         instead of a bare screen table (which also spilled onto a
         second page at screen-sized row metrics — the compact print
         metrics for this report live in qs.css, Loop 47). --}}
    <div class="print-doc-head">
        <img src="{{ Qs::getLogo() }}" alt="">
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Fee Defaulters &mdash; Session: {{ $year }}{{ $selected_class ? ' &middot; Class: '.$selected_class->name : '' }} &middot; {{ $rows->count() }} defaulter(s) owing {{ \App\Helpers\Money::inr($total_due) }}</p>
        </div>
    </div>

    <div class="card def-doc">
        <div class="card-header header-elements-inline print-hide">
            <span class="text-muted">Session {{ $year }}</span>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
                <a href="{{ route('payments.defaulters.export', $selected_class ? ['class_id' => $selected_class->id] : []) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export CSV</a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('payments.defaulters') }}" class="form-inline mb-3 print-hide">
                <label class="mr-2">Class</label>
                <select name="class_id" class="form-control mr-3" onchange="this.form.submit()">
                    <option value="">All Classes</option>
                    @foreach($classes as $class)
                        <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                    @endforeach
                </select>
                <span class="text-muted">{{ $rows->count() }} defaulter(s) owing {{ \App\Helpers\Money::inr($total_due) }} in {{ $year }}{{ $selected_class ? ' in ' . $selected_class->name : '' }}</span>
            </form>

            @if($rows->isEmpty())
                <div class="alert alert-success border-0 mb-0">
                    No fee defaulters for {{ $year }}{{ $selected_class ? ' in ' . $selected_class->name : '' }}. Every current-session fee record is fully paid.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Student</th>
                            <th>Adm No</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Unpaid Items</th>
                            <th>Oldest Unpaid Fee</th>
                            <th>Since</th>
                            <th class="text-right">Total Due</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td><strong>{{ optional($row['student']->user)->name }}</strong></td>
                                <td>{{ $row['student']->adm_no ?: '—' }}</td>
                                <td>{{ optional($row['student']->my_class)->name ?: '—' }}</td>
                                <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                                <td>{{ $row['items'] }}</td>
                                <td>{{ $row['oldest_title'] ?: '—' }}</td>
                                <td>{{ $row['oldest_since'] ? $row['oldest_since']->format('d M Y') : '—' }}</td>
                                <td class="text-right text-danger font-weight-bold">{{ \App\Helpers\Money::inr($row['total_due']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr>
                            <th colspan="7">Total Outstanding</th>
                            <th class="text-right">{{ \App\Helpers\Money::inr($total_due) }}</th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="text-muted mb-0 def-note"><small>Balance per record follows the invoice page: the stored balance, or the full fee amount for untouched records. Partially paid records count only their remaining balance. Worst first.</small></p>
            @endif
        </div>
    </div>

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Fee Defaulters &mdash; {{ $year }}</span>
    </div>
@endsection
