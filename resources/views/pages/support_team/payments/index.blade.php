@extends('layouts.master')
@section('page_title', 'Manage Payments')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Manage Payments
@endsection
@section('page_actions')
    @if(Qs::userIsTeamAccountWrite())
        <a href="{{ route('payments.create') }}" class="btn btn-primary"><i class="icon-plus3 mr-1"></i> Create Payment</a>
    @endif
@endsection
@section('content')

    {{-- Loop 94 (2026-10-22): premium redesign on the BT/CK build.
         Section 1 keeps the Collect Fee counter contract byte-for-byte
         (Workstream BJ: endpoint, results dropdown, invoice links, the
         script below). Section 2 keeps the session picker POST and the
         tabbed fee-definition tables (payments/_table, Edit/Delete
         unchanged) and adds, for the selected session: the four
         stat cards (receipts ledger + the Fee Defaulters rule) and
         the Payment Records table — receipts for the session, latest
         first, server-paginated, each row linking to the record's
         real receipt document (View) and its PDF (Print). Figures
         reconcile with the Fee Defaulters and Collection Report
         pages, which read the same ledger by the same rules. --}}

    {{-- Collect Fee (Workstream BJ) --}}
    {{-- Loop 93: premium skin for the counter search — same BJ
         contract (endpoint, #fee-student-search, #fee-search-results,
         result URLs) under the collect-fee header + step guide. --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub"><i class="icon-cash2 mr-1"></i> <strong>Find Student</strong> &mdash; a student comes to pay: find them here and jump straight to their payment records.</div>
            </div>
        </div>

        <div class="card pat-card">
            <div class="card-body">
                <div class="l93-search-head">
                    <span class="l93-search-icon"><i class="icon-search4"></i></span>
                    <div>
                        <h5>Collect Fee / Create Payment</h5>
                        <p>Search for a student, review outstanding fees, and record a payment.</p>
                    </div>
                </div>

                {{-- Visual guide only: the flow is this search, then the
                     student's invoice page (dues + payment + receipt). --}}
                <ol class="l93-steps" aria-label="Payment progress">
                    <li class="l93-step is-active"><span class="l93-step-dot">1</span><span class="l93-step-text"><strong>Find Student</strong><small>Search &amp; select</small></span></li>
                    <li class="l93-step"><span class="l93-step-dot">2</span><span class="l93-step-text"><strong>Fee Details</strong><small>View dues &amp; select</small></span></li>
                    <li class="l93-step"><span class="l93-step-dot">3</span><span class="l93-step-text"><strong>Payment Info</strong><small>Enter payment details</small></span></li>
                    <li class="l93-step"><span class="l93-step-dot">4</span><span class="l93-step-text"><strong>Confirm &amp; Save</strong><small>Review &amp; record</small></span></li>
                </ol>

                <div class="bt-find mp94-find">
                    <div class="form-group mb-1" style="position: relative;">
                        <label for="fee-student-search" class="col-form-label font-weight-bold">Search Student</label>
                        <div class="l93-search-box">
                            <i class="icon-search4"></i>
                            <input type="text" id="fee-student-search" class="form-control" autocomplete="off"
                                   placeholder="Type student name, admission number or class&hellip;">
                        </div>
                        <div id="fee-search-results" class="list-group l93-results" style="display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; max-height: 340px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,.15);"></div>
                    </div>
                    <small class="form-text text-muted">Search by name, admission number or class. Pick a student to open their payment records and collect the fee.</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Session payments --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            @if($selected)
                <strong>{{ $year }} Session</strong> &mdash; receipts, collections and outstanding dues for the selected session.
            @else
                Select a session year to view and manage its fee payments.
            @endif
        @endslot
        @if($selected)
            @slot('stats')
                <div class="row dashboard-stats">
                    <div class="col-sm-6 col-xl-3">
                        <div class="dashboard-stat-card dashboard-stat-blue">
                            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-cash2"></i></span></span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">Total Payments</span>
                                <strong>{{ number_format($stat_total_payments) }}</strong>
                                <small>Receipts recorded &middot; {{ $year }}</small>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="dashboard-stat-card dashboard-stat-green">
                            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-coins"></i></span></span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">Total Collected</span>
                                <strong>{{ \App\Helpers\Money::inr($stat_total_collected) }}</strong>
                                <small>For the selected session</small>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="dashboard-stat-card dashboard-stat-orange">
                            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-alarm"></i></span></span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">Pending Dues</span>
                                <strong>{{ \App\Helpers\Money::inr($stat_pending_dues) }}</strong>
                                <small>Outstanding on unpaid records</small>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="dashboard-stat-card dashboard-stat-red">
                            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-users"></i></span></span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">Defaulters</span>
                                <strong>{{ number_format($stat_defaulters) }}</strong>
                                <small>Students with dues &middot; <a href="{{ route('payments.defaulters') }}">View report</a></small>
                            </span>
                        </div>
                    </div>
                </div>
            @endslot
        @endif
        @slot('filters')
            <form method="post" action="{{ route('payments.select_year') }}" class="pat-filter-form">
                @csrf
                <div class="pat-filter-field">
                    <label for="year">Academic Session <span class="text-danger">*</span></label>
                    <select data-placeholder="Select Year" required id="year" name="year" class="form-control select">
                        @foreach($years as $yr)
                            <option {{ ($selected && $year == $yr->year) ? 'selected' : '' }} value="{{ $yr->year }}">{{ $yr->year }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Submit <i class="icon-paperplane ml-2"></i></button>
                </div>
                <div class="pat-filter-field">
                    <span class="text-muted">All figures on this page &mdash; cards, records and fee structure &mdash; are for the selected session.</span>
                </div>
            </form>
        @endslot

        @if($selected)
            {{-- Payment Records (Loop 94) --}}
            <div class="mp94-records">
                <div class="mp94-records-head">
                    <div>
                        <h6 class="mp94-block-title"><i class="icon-file-text2 mr-1"></i> Payment Records</h6>
                        <p class="mp94-block-sub">Receipts recorded in the {{ $year }} session, latest first.</p>
                    </div>
                    @if($records->total())
                        <span class="mp94-showing">Showing {{ $records->firstItem() }} to {{ $records->lastItem() }} of {{ $records->total() }} records</span>
                    @endif
                </div>

                @if($records->count())
                    <div class="table-responsive mp94-table-wrap">
                        <table class="table mp94-table">
                            <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th>Receipt No</th>
                                <th>Student</th>
                                <th>Class &amp; Section</th>
                                <th class="text-right">Amount</th>
                                <th>Method</th>
                                <th>Payment Date</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($records as $r)
                                @php
                                    $pr = $r->pr;
                                    $sr = $pr ? $record_students->get($pr->student_id) : null;
                                    $method = ($r->method === 'online_razorpay')
                                        ? 'Online (Razorpay)'
                                        : ucwords(optional(optional($pr)->payment)->method ?? 'Cash');
                                    $settled = (int) $r->balance <= 0;
                                @endphp
                                <tr>
                                    <td>{{ $records->firstItem() + $loop->index }}</td>
                                    <td><span class="mp94-ref">{{ $pr->ref_no }}</span></td>
                                    <td>
                                        <strong>{{ optional(optional($pr)->student)->name }}</strong>
                                        @if($sr && $sr->adm_no)<br><small class="text-muted">Adm {{ $sr->adm_no }}</small>@endif
                                    </td>
                                    <td>{{ optional(optional($sr)->my_class)->name ?: '—' }}@if($sr && $sr->section) &middot; {{ $sr->section->name }}@endif</td>
                                    <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($r->amt_paid) }}</td>
                                    <td>{{ $method }}</td>
                                    <td>{{ $r->created_at->format('d M Y') }}<br><small class="text-muted">{{ $r->created_at->format('h:i A') }}</small></td>
                                    <td><span class="mp94-pill {{ $settled ? 'mp94-pill-paid' : 'mp94-pill-partial' }}">{{ $settled ? 'Paid' : 'Partial' }}</span></td>
                                    <td class="text-center text-nowrap">
                                        <a href="{{ route('payments.receipts', Qs::hash($pr->id)) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                        <a href="{{ route('payments.pdf_receipts', Qs::hash($pr->id)) }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> PDF</a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($records->lastPage() > 1)
                        @php
                            $winStart = max(1, $records->currentPage() - 2);
                            $winEnd = min($records->lastPage(), $records->currentPage() + 2);
                        @endphp
                        <div class="mp94-pagination">
                            <span class="mp94-pagination-info">Showing {{ $records->firstItem() }} to {{ $records->lastItem() }} of {{ $records->total() }} records</span>
                            <nav class="mp94-pages" aria-label="Payment records pages">
                                @if($records->onFirstPage())
                                    <span class="mp94-page is-disabled">&lsaquo;</span>
                                @else
                                    <a class="mp94-page" href="{{ $records->previousPageUrl() }}">&lsaquo;</a>
                                @endif
                                @for($i = $winStart; $i <= $winEnd; $i++)
                                    @if($i === $records->currentPage())
                                        <span class="mp94-page is-current">{{ $i }}</span>
                                    @else
                                        <a class="mp94-page" href="{{ $records->url($i) }}">{{ $i }}</a>
                                    @endif
                                @endfor
                                @if($records->hasMorePages())
                                    <a class="mp94-page" href="{{ $records->nextPageUrl() }}">&rsaquo;</a>
                                @else
                                    <span class="mp94-page is-disabled">&rsaquo;</span>
                                @endif
                            </nav>
                        </div>
                    @endif
                @else
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-cash2',
                        'message' => 'No receipts have been recorded for the ' . $year . ' session yet. Receipts appear here the moment a payment is collected.',
                    ])
                @endif
            </div>

            {{-- Fee Structure (BT tabs — unchanged contract) --}}
            <div class="mp94-fees">
                <div class="mp94-records-head">
                    <div>
                        <h6 class="mp94-block-title"><i class="icon-stack mr-1"></i> Fee Structure</h6>
                        <p class="mp94-block-sub">Fee definitions for the {{ $year }} session &mdash; what each fee charges. Use the Class Payments tab to see one class at a time.</p>
                    </div>
                </div>

                <ul class="nav nav-tabs nav-tabs-highlight">
                    <li class="nav-item"><a href="#all-payments" class="nav-link active" data-toggle="tab">All Classes</a></li>
                    <li class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">Class Payments</a>
                        <div class="dropdown-menu dropdown-menu-right">
                            @foreach($my_classes as $mc)
                                <a href="#pc-{{ $mc->id }}" class="dropdown-item" data-toggle="tab">{{ $mc->name }}</a>
                            @endforeach
                        </div>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="all-payments">
                        @if($payments->count())
                            @include('pages.support_team.payments._table', ['rows' => $payments, 'total_label' => 'Total (' . $payments->count() . ' payments)'])
                        @else
                            <div class="lav-empty">
                                <p class="mb-0">No payments have been created for the {{ $year }} session yet. Use <strong>Create Payment</strong> to add the first fee.</p>
                            </div>
                        @endif
                    </div>

                    @foreach($my_classes as $mc)
                        <div class="tab-pane fade" id="pc-{{ $mc->id }}">
                            @php $class_payments = $payments->where('my_class_id', $mc->id); @endphp
                            @if($class_payments->count())
                                @include('pages.support_team.payments._table', ['rows' => $class_payments, 'total_label' => 'Class-specific total — ' . $mc->name])
                            @else
                                <p class="text-muted mb-0" style="padding: 18px 4px;">No class-specific payments for {{ $mc->name }} in {{ $year }}. General payments (All Classes) still apply to this class.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-cash2',
                'message' => 'Select a session year above to view its payments.',
            ])
        @endif
    @endcomponent

    {{--Payments List Ends--}}

@endsection

@section('scripts')
<script>
    (function () {
        var $input = $('#fee-student-search');
        var $box = $('#fee-search-results');
        if (!$input.length || !$box.length) { return; }

        function esc(s) {
            return $('<div>').text(s == null ? '' : s).html();
        }
        function hide() { $box.hide().empty(); }

        var timer = null;
        $input.on('input', function () {
            var q = $input.val().trim();
            clearTimeout(timer);
            if (q.length < 2) { hide(); return; }
            timer = setTimeout(function () {
                $.getJSON('{{ route('payments.student_search') }}', {q: q})
                    .done(function (data) {
                        if ($input.val().trim() !== q) { return; }
                        var rows = (data.results || []).map(function (s) {
                            var meta = [s.adm_no ? 'Adm ' + s.adm_no : '', s.class + (s.section ? ' · ' + s.section : '')]
                                .filter(Boolean).join(' — ');
                            var bal = s.has_outstanding
                                ? '<span class="l93-due-pill">' + esc(s.outstanding) + ' due</span>'
                                : '<span class="l93-nodue-pill">No dues</span>';
                            // Loop 93: the stock silhouette is not a real
                            // photo — fall back to initials (Qs::hasRealPhoto rule).
                            var hasPhoto = s.photo && s.photo.indexOf('global_assets/images/user.png') === -1;
                            var initials = (s.name || '?').trim().split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join('').toUpperCase();
                            var avatar = hasPhoto
                                ? '<img class="rounded-circle" style="height:38px;width:38px;object-fit:cover;flex:none" src="' + esc(s.photo) + '" alt="">'
                                : '<span class="l93-avatar-sm">' + esc(initials) + '</span>';
                            return '<a href="' + s.url + '" class="list-group-item list-group-item-action l93-result">'
                                + avatar
                                + '<span class="l93-result-main"><strong>' + esc(s.name) + '</strong><small>' + esc(meta) + '</small></span>'
                                + bal + '</a>';
                        }).join('');
                        $box.html(rows || '<div class="list-group-item text-muted">No students match &ldquo;' + esc(q) + '&rdquo;.</div>').show();
                    })
                    .fail(function () { hide(); });
            }, 250);
        });

        $(document).on('click', function (e) {
            if (!$box.is(e.target) && $box.has(e.target).length === 0 && !$input.is(e.target)) { hide(); }
        });
        $input.on('keydown', function (e) { if (e.key === 'Escape') { hide(); } });
    })();
</script>
@endsection
