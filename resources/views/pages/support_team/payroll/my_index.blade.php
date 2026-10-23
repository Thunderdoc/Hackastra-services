@extends('layouts.master')
@section('page_title', 'My Payslips')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('my_account') }}">Account</a> &nbsp;/&nbsp; My Payslips
@endsection
@section('content')

    {{-- Loop 89 (redesign pass): premium self-service payslips — summary
         cards over the selected year (PAID records only ever count as
         received), year/month filters drawn from the user's own
         records, the payslip history table, per-payslip PDF download
         and a combined year PDF. Every figure comes from the Payroll
         model via PayrollController@myIndex — nothing is computed in
         parallel here. View opens the Loop 71 payslip document. --}}
    <div class="mp89-wrap">
    @component('partials.patterns.list_page')
        @slot('subtitle')
            View and download your salary slips and payment details.
        @endslot
        @slot('action')
            @if($has_records && $summary['count'] > 0)
                <a href="{{ route('my_payslips.pdf_all', ['year' => $selected_year]) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Download All</a>
            @endif
        @endslot
        @slot('stats')
            <div class="row dashboard-stats">
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-green">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-wallet"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Total Received (YTD)</span>
                            <strong>{{ \App\Helpers\Money::inr($summary['total_received']) }}</strong>
                            <small></small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-blue">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-file-text2"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Total Payslips</span>
                            <strong>{{ number_format($summary['count']) }}</strong>
                            <small></small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-purple">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-stats-growth"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Average Monthly Pay</span>
                            <strong>{{ $summary['average'] !== null ? \App\Helpers\Money::inr($summary['average']) : '—' }}</strong>
                            <small>{{ $summary['average'] !== null ? '' : 'No paid payslips yet' }}</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-orange">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar3"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Latest Payment</span>
                            <strong>{{ $summary['latest_paid'] ? date('M', mktime(0, 0, 0, (int) $summary['latest_paid']->month, 1)).' '.$summary['latest_paid']->year : '—' }}</strong>
                            <small>{{ $summary['latest_paid'] ? '' : 'No payments yet' }}</small>
                        </span>
                    </div>
                </div>
            </div>
        @endslot
        @slot('filters')
            <form method="get" action="{{ route('my_payslips.index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="mp89-year">Year</label>
                    <select id="mp89-year" name="year" class="form-control">
                        @foreach($years as $y)
                            <option value="{{ $y }}" {{ $y == $selected_year ? 'selected' : '' }}>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="mp89-month">Month</label>
                    <select id="mp89-month" name="month" class="form-control">
                        <option value="0" {{ $selected_month == 0 ? 'selected' : '' }}>All Months</option>
                        @foreach($months as $mNum => $mName)
                            <option value="{{ $mNum }}" {{ $mNum == $selected_month ? 'selected' : '' }}>{{ $mName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    @if($selected_month || ($years->isNotEmpty() && $selected_year != $years->first()))
                        <a href="{{ route('my_payslips.index') }}" class="btn btn-light">Reset</a>
                    @endif
                </div>
            </form>
        @endslot

        <div class="mp89-history-head">
            <span class="mp89-history-icon"><i class="icon-file-text2"></i></span>
            <div class="mp89-history-text">
                <h5>Payslip History</h5>
                <p>View, download and keep a record of all your salary slips.</p>
            </div>
        </div>

        @if(!$has_records)
            <div class="mp89-empty">
                <div class="mp89-empty-icon"><i class="icon-file-text2"></i></div>
                <h6>No payslips available yet</h6>
                <p>Your salary statements will appear here when they are published.</p>
            </div>
        @elseif($records->isEmpty())
            <div class="mp89-empty">
                <div class="mp89-empty-icon"><i class="icon-search4"></i></div>
                <h6>No payslips match the selected filters</h6>
                <p>Try a different month, or reset the filters to see all your payslips for {{ $selected_year }}.</p>
                <a href="{{ route('my_payslips.index') }}" class="btn btn-primary btn-sm">Reset Filters</a>
            </div>
        @else
            <table class="table mp89-table l108-stack">
                <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Month</th>
                    <th class="text-right">Basic</th>
                    <th class="text-right">Allowances</th>
                    <th class="text-right">Deductions</th>
                    <th class="text-right">Net Pay</th>
                    <th>Status</th>
                    <th>Paid On</th>
                    <th class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($records as $rec)
                    <tr>
                        <td data-label="#">{{ $records->firstItem() + $loop->index }}</td>
                        <td data-label="Month">
                            <div class="mp89-month">
                                <span class="mp89-month-icon"><i class="icon-calendar3"></i></span>
                                <span class="mp89-month-text">
                                    <strong>{{ $rec->monthName() }} {{ $rec->year }}</strong>
                                    <small>Salary for {{ $rec->monthName() }} {{ $rec->year }}</small>
                                </span>
                            </div>
                        </td>
                        <td class="text-right" data-label="Basic">{{ \App\Helpers\Money::inr($rec->basic_salary) }}</td>
                        <td class="text-right" data-label="Allowances">{{ \App\Helpers\Money::inr($rec->allowances) }}</td>
                        <td class="text-right" data-label="Deductions">{{ \App\Helpers\Money::inr($rec->deductions) }}</td>
                        <td class="text-right" data-label="Net Pay"><span class="mp89-net">{{ \App\Helpers\Money::inr($rec->net_pay) }}</span></td>
                        <td data-label="Status">
                            @if($rec->isPaid())
                                <span class="badge pay-badge-paid">✓ Paid</span>
                            @else
                                <span class="badge pay-badge-pending">Pending</span>
                            @endif
                        </td>
                        <td data-label="Paid On">{{ $rec->paid_at ? $rec->paid_at->format('d M Y') : '—' }}</td>
                        <td class="text-right mp89-actions" data-label="Actions">
                            <a href="{{ route('my_payslips.payslip', $rec->id) }}" class="btn btn-light btn-sm"><i class="icon-file-text2 mr-1"></i> View</a>
                            <a href="{{ route('my_payslips.pdf', $rec->id) }}" class="btn btn-light btn-sm"><i class="icon-file-pdf mr-1"></i> Download PDF</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="mp89-pager">
                <span class="mp89-pager-info">@if($records->firstItem() == $records->lastItem())Showing {{ $records->total() }} of {{ $records->total() }} {{ $records->total() == 1 ? 'payslip' : 'payslips' }}@elseShowing {{ $records->firstItem() }} to {{ $records->lastItem() }} of {{ $records->total() }} {{ $records->total() == 1 ? 'payslip' : 'payslips' }}@endif</span>
                @if($records->hasPages())
                    <div class="mp89-pager-links">
                        <a class="mp89-pager-link {{ $records->onFirstPage() ? 'mp89-pager-disabled' : '' }}" href="{{ $records->onFirstPage() ? '#' : $records->previousPageUrl() }}">&lsaquo;</a>
                        @for($p = 1; $p <= $records->lastPage(); $p++)
                            <a class="mp89-pager-link {{ $p == $records->currentPage() ? 'mp89-pager-active' : '' }}" href="{{ $records->url($p) }}">{{ $p }}</a>
                        @endfor
                        <a class="mp89-pager-link {{ !$records->hasMorePages() ? 'mp89-pager-disabled' : '' }}" href="{{ $records->hasMorePages() ? $records->nextPageUrl() : '#' }}">&rsaquo;</a>
                    </div>
                @endif
            </div>
        @endif
    @endcomponent

    <div class="mp89-about">
        <span class="mp89-about-icon"><i class="icon-info22"></i></span>
        <div class="mp89-about-text">
            <h6>About Payslips</h6>
            <ul>
                <li>Your payslips are generated by the school administration.</li>
                <li>You can download and keep a copy for your records.</li>
                <li>Contact the school administration if you find any discrepancy.</li>
            </ul>
        </div>
    </div>
    </div>
@endsection
