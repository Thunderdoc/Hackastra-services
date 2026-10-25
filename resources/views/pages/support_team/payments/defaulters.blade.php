@extends('layouts.master')
@section('page_title', 'Fee Defaulters')
@section('page_icon')
    <span class="l118-page-icon l118-red-tile" aria-hidden="true"><i class="icon-users4"></i></span>
@endsection
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('payments.manage') }}">Payments</a> &nbsp;/&nbsp; Fee Defaulters
@endsection
@section('content')
    {{-- Workstream BT: print-only document header/footer — the same
         print-doc standard as the students list (Loop 31). Hidden on
         screen; window.print() produces a branded school report. --}}
    {{-- Document design v2 (Workstream DB, Loop 71): the head carries
         the uploaded logo, or a navy monogram tile when none is
         uploaded (never the theme's light fallback logo), and the
         page carries the watermark — logo or app-code monogram —
         behind the content. Print metrics are untouched (Loop 62):
         the report still fits exactly one A4 page. --}}
    {{-- Loop 95: the printed document is fed by $print_rows — the
         defaulter set with class scope only, exactly the semantics
         this page has always printed with. The redesigned screen
         (cards, filters, paginated table) is print-hidden and never
         reaches the paper. --}}
    @php
        $dbInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $dbWord) {
            if ($dbWord !== '') {
                $dbInitials .= strtoupper(substr($dbWord, 0, 1));
                if (strlen($dbInitials) >= 2) { break; }
            }
        }
        if ($dbInitials === '') { $dbInitials = 'S'; }
    @endphp
    <div class="print-doc-head">
        @if(Qs::getSetting('logo'))
            <img src="{{ Qs::getSetting('logo') }}" alt="">
        @else
            <span class="print-doc-mono" aria-hidden="true">{{ $dbInitials }}</span>
        @endif
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Fee Defaulters &mdash; Session: {{ $year }}{{ $selected_class ? ' &middot; Class: '.$selected_class->name : '' }} &middot; {{ $print_rows->count() }} defaulter(s) owing {{ \App\Helpers\Money::inr($print_total_due) }}</p>
        </div>
    </div>
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    @php
        $def95ExportParams = array_filter([
            'class_id' => $selected_class ? $selected_class->id : null,
            'section_id' => $selected_section ? $selected_section->id : null,
            'status' => $status !== '' ? $status : null,
            'q' => $q !== '' ? $q : null,
        ]);
    @endphp
    <div class="pay-def">
        @component('partials.patterns.list_page')
            @slot('subtitle')
                View students with pending fee payments and take necessary action.
            @endslot
            @slot('action')
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
                <a href="{{ route('payments.defaulters.export', $def95ExportParams) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export CSV</a>
                {{-- Wave 5 — owner order 2026-10-25: one click reminds
                     the whole filtered defaulter set (student + linked
                     parents, bell). Same filter params as the export,
                     so a filtered view reminds only that set. --}}
                <form method="post" action="{{ route('payments.defaulters.remind', $def95ExportParams) }}" style="display:inline">
                    @csrf
                    <button type="submit" class="btn btn-danger btn-sm"><i class="icon-bell2 mr-1"></i> Send fee reminders</button>
                </form>
            @endslot
            @slot('stats')
                <div class="row dashboard-stats print-hide">
                    <div class="col-sm-6 col-xl-4">
                        <div class="dashboard-stat-card dashboard-stat-red">
                            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-users4"></i></span></span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">Total Defaulters</span>
                                <strong>{{ number_format($summary['count']) }}</strong>
                                <small>{{ \App\Helpers\Money::inr($summary['due']) }} total outstanding{{ $filters_active ? ' — matching current filters' : '' }}</small>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-4">
                        <div class="dashboard-stat-card dashboard-stat-orange">
                            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-wallet"></i></span></span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">Partially Paid</span>
                                <strong>{{ number_format($summary['partial_count']) }}</strong>
                                <small>{{ \App\Helpers\Money::inr($summary['partial_due']) }} still due</small>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-4">
                        <div class="dashboard-stat-card dashboard-stat-pink">
                            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-warning2"></i></span></span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">Fully Unpaid</span>
                                <strong>{{ number_format($summary['unpaid_count']) }}</strong>
                                <small>{{ \App\Helpers\Money::inr($summary['unpaid_due']) }} still due — nothing paid yet</small>
                            </span>
                        </div>
                    </div>
                </div>
            @endslot
            @slot('filters')
                <form method="GET" action="{{ route('payments.defaulters') }}" class="pat-filter-form print-hide">
                    <div class="pat-filter-field">
                        <label for="def_class_id">Class</label>
                        <select id="def_class_id" name="class_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Classes</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-field">
                        <label for="def_section_id">Section</label>
                        @if($selected_class)
                            <select id="def_section_id" name="section_id" class="form-control">
                                <option value="">All Sections</option>
                                @foreach($sections as $section)
                                    <option value="{{ $section->id }}" {{ $selected_section && $selected_section->id === $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                                @endforeach
                            </select>
                        @else
                            <select id="def_section_id" class="form-control" disabled>
                                <option value="">Select a class first</option>
                            </select>
                        @endif
                    </div>
                    <div class="pat-filter-field">
                        <label for="def_status">Status</label>
                        <select id="def_status" name="status" class="form-control">
                            <option value="" {{ $status === '' ? 'selected' : '' }}>All Statuses</option>
                            <option value="unpaid" {{ $status === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                            <option value="partial" {{ $status === 'partial' ? 'selected' : '' }}>Partially Paid</option>
                        </select>
                    </div>
                    <div class="pat-filter-field">
                        <label for="def_q">Search</label>
                        <input type="text" id="def_q" name="q" class="form-control" value="{{ $q }}" placeholder="Search by name, admission no&hellip;">
                    </div>
                    <div class="pat-filter-action">
                        <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Filter</button>
                        <a href="{{ route('payments.defaulters') }}" class="btn btn-light"><i class="icon-reset mr-1"></i> Reset</a>
                    </div>
                </form>
            @endslot

            @if($print_rows->isEmpty() && $rows->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-checkmark-circle2',
                    'message' => 'No fee defaulters for ' . $year . ($selected_class ? ' in ' . $selected_class->name : '') . '. Every current-session fee record is fully paid.',
                ])
            @else
                @if($rows->isEmpty())
                    <div class="print-hide">
                        @include('partials.patterns.empty_state', [
                            'icon' => 'icon-search4',
                            'message' => 'No students found. Try another search or reset the filters.',
                            'action_url' => route('payments.defaulters'),
                            'action_label' => 'Reset Filters',
                        ])
                    </div>
                @else
                    <div class="print-hide">
                        {{-- Loop 118: the reference's table-card head —
                             title + subtext above the screen table. --}}
                        <div class="l118-card-head">
                            <div>
                                <h6 class="l118-card-title"><i class="icon-users4 mr-1"></i> Defaulter Students</h6>
                                <p class="l118-card-sub">List of students with pending fee payments.</p>
                            </div>
                        </div>
                        <table class="table def95-table l108-stack">
                            <thead>
                            <tr>
                                <th style="width:34px">#</th>
                                <th>Student Name</th>
                                <th>Admission No</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th class="text-right">Total Fees</th>
                                <th class="text-right">Paid</th>
                                <th class="text-right">Total Due (₹)</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($rows as $row)
                                @php
                                    $def95Words = preg_split('/\s+/', trim((string) optional($row['student']->user)->name));
                                    $def95Ini = strtoupper(substr($def95Words[0] ?? '', 0, 1) . (isset($def95Words[1]) ? substr($def95Words[1], 0, 1) : ''));
                                @endphp
                                <tr>
                                    <td data-label="#">{{ $rows->firstItem() + $loop->index }}</td>
                                    <td data-label="Student Name">
                                        <span class="def95-student"><span class="l7-avatar l7-avatar-md" aria-hidden="true">{{ $def95Ini }}@if(Qs::hasRealPhoto($row['student']->user->photo))<img src="{{ $row['student']->user->photo }}" alt="" onerror="this.remove()">@endif</span><strong>{{ optional($row['student']->user)->name }}</strong></span>
                                    </td>
                                    <td data-label="Admission No">{{ $row['student']->adm_no ?: '—' }}</td>
                                    <td data-label="Class">{{ optional($row['student']->my_class)->name ?: '—' }}</td>
                                    <td data-label="Section">{{ optional($row['student']->section)->name ?: '—' }}</td>
                                    <td class="text-right" data-label="Total Fees">{{ \App\Helpers\Money::inr($row['total_fees']) }}</td>
                                    <td class="text-right" data-label="Paid">{{ \App\Helpers\Money::inr($row['total_paid']) }}</td>
                                    <td class="text-right def95-due" data-label="Total Due (₹)">{{ \App\Helpers\Money::inr($row['total_due']) }}</td>
                                    <td data-label="Status">
                                        @if($row['status'] === 'partial')
                                            <span class="def95-pill def95-pill-partial">Partially Paid</span>
                                        @else
                                            <span class="def95-pill def95-pill-unpaid">Unpaid</span>
                                        @endif
                                    </td>
                                    <td class="text-right" data-label="Action">
                                        <a href="{{ route('payments.invoice', Qs::hash($row['student']->user_id)) }}" class="btn btn-light btn-sm" aria-label="View payments for {{ optional($row['student']->user)->name }}"><i class="icon-eye mr-1"></i> View</a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        @php
                            $def95Cur = $rows->currentPage(); $def95Last = $rows->lastPage();
                            $def95From = max(1, $def95Cur - 2); $def95To = min($def95Last, $def95Cur + 2);
                        @endphp
                        <div class="def95-pagination">
                            <span class="def95-pagination-info">Showing {{ $rows->firstItem() }} to {{ $rows->lastItem() }} of {{ $rows->total() }} records</span>
                            @if($def95Last > 1)
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item {{ $def95Cur <= 1 ? 'disabled' : '' }}"><a class="page-link" href="{{ $def95Cur > 1 ? $rows->url($def95Cur - 1) : '#' }}">&laquo; Previous</a></li>
                                    @if($def95From > 1)
                                        <li class="page-item"><a class="page-link" href="{{ $rows->url(1) }}">1</a></li>
                                        @if($def95From > 2)<li class="page-item disabled"><span class="page-link">&hellip;</span></li>@endif
                                    @endif
                                    @for($def95i = $def95From; $def95i <= $def95To; $def95i++)
                                        <li class="page-item {{ $def95i === $def95Cur ? 'active' : '' }}"><a class="page-link" href="{{ $rows->url($def95i) }}">{{ $def95i }}</a></li>
                                    @endfor
                                    @if($def95To < $def95Last)
                                        @if($def95To < $def95Last - 1)<li class="page-item disabled"><span class="page-link">&hellip;</span></li>@endif
                                        <li class="page-item"><a class="page-link" href="{{ $rows->url($def95Last) }}">{{ $def95Last }}</a></li>
                                    @endif
                                    <li class="page-item {{ $def95Cur >= $def95Last ? 'disabled' : '' }}"><a class="page-link" href="{{ $def95Cur < $def95Last ? $rows->url($def95Cur + 1) : '#' }}">Next &raquo;</a></li>
                                </ul>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- The printed table — markup, rows, order and figures
                     exactly as the Wave 3 document has always produced
                     them (Loop 95 changed none of it). Hidden on screen;
                     Loop 62's .pay-def print metrics compact it onto
                     exactly one A4 page. --}}
                <div class="def95-print-only">
                    <table class="table">
                        <thead>
                        <tr>
                            <th style="width:34px">#</th>
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
                        @foreach($print_rows as $row)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
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
                            <th colspan="8">Total Outstanding</th>
                            <th class="text-right">{{ \App\Helpers\Money::inr($print_total_due) }}</th>
                        </tr>
                        </tfoot>
                    </table>
                    <p class="text-muted mb-0 def-note"><small>Balance per record follows the invoice page: the stored balance, or the full fee amount for untouched records. Partially paid records count only their remaining balance. Worst first.</small></p>
                </div>
            @endif
        @endcomponent
    </div>

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Fee Defaulters &mdash; {{ $year }} &middot; Generated by Hackstra</span>
    </div>
@endsection
