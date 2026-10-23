@extends('layouts.master')
@section('page_title', 'Mark Attendance — Submitted')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Attendance &nbsp;/&nbsp; <a href="{{ route('attendance.periods.index', ['date' => $session->date->format('Y-m-d')]) }}">Mark Attendance</a> &nbsp;/&nbsp; Submitted
@endsection
@section('page_actions')
    <a href="{{ route('attendance.periods.index', ['date' => $session->date->format('Y-m-d')]) }}" class="btn btn-light"><i class="icon-arrow-left8 mr-1"></i> Back to Periods</a>
@endsection
@section('content')

    {{-- Loop 77 (owner redesign, second addendum): the Submitted
         Register page. A locked, read-only record: register info card,
         totals computed from the register's own saved entries, the
         student table (no remark column — the register stores none;
         no row actions — a locked register has none), a client-side
         name/admission search, and a bottom action panel: Print
         (v2 document view), Export CSV and Download PDF (dompdf is
         in-tree). Server-side scoping is the controller's show() rule,
         unchanged. --}}
    <p class="ma-intro">View the submitted attendance register for this period. This record is locked and cannot be edited.</p>

    <div class="card rg-info-card">
        <div class="card-body">
            <div class="rg-info-top">
                <div class="rg-info-subject">
                    <div class="rg-subject-name">{{ optional($session->subject)->name }}</div>
                    <div class="rg-subject-meta">
                        {{ optional($session->my_class)->name }}@if(optional($session->section)->name) &bull; Section: {{ $session->section->name }}@endif
                        @if(optional($session->subject)->slug)<span class="ma-code-chip ml-2">{{ $session->subject->slug }}</span>@endif
                    </div>
                </div>
                <div class="rg-lock">
                    <span class="ma-pill ma-pill-done rg-lock-pill"><i class="icon-lock2"></i> Submitted &middot; Locked</span>
                    <div class="rg-lock-copy">This register cannot be edited or re-taken.</div>
                </div>
            </div>

            <div class="rg-blocks">
                <div class="rg-block">
                    <span class="rg-block-icon"><i class="icon-calendar3"></i></span>
                    <span class="rg-block-copy"><span class="rg-block-label">Date</span><span class="rg-block-value">{{ $session->date->format('l, d M Y') }}</span></span>
                </div>
                <div class="rg-block">
                    <span class="rg-block-icon"><i class="icon-alarm"></i></span>
                    <span class="rg-block-copy"><span class="rg-block-label">Time</span><span class="rg-block-value">{{ $time_label }}</span></span>
                </div>
                <div class="rg-block">
                    <span class="rg-block-icon"><i class="icon-user"></i></span>
                    <span class="rg-block-copy"><span class="rg-block-label">Teacher</span><span class="rg-block-value">{{ optional($session->teacher)->name ?: '—' }}</span></span>
                </div>
                <div class="rg-block">
                    <span class="rg-block-icon"><i class="icon-checkmark-circle2"></i></span>
                    <span class="rg-block-copy"><span class="rg-block-label">Submitted At</span><span class="rg-block-value">{{ $session->submitted_at ? $session->submitted_at->format('d M Y, g:i A') : '—' }}</span></span>
                </div>
                <div class="rg-block rg-block-wide">
                    <span class="rg-block-icon"><i class="icon-book"></i></span>
                    <span class="rg-block-copy"><span class="rg-block-label">Topic Taught</span><span class="rg-block-value">{{ $session->topic ?: '—' }}</span></span>
                </div>
            </div>
        </div>
    </div>

    <div class="rg-chips">
        <div class="rg-chip">
            <span class="rg-chip-value">{{ $counts['total'] }}</span>
            <span class="rg-chip-label">Total Students</span>
        </div>
        <div class="rg-chip rg-chip-present">
            <span class="rg-chip-value">{{ $counts['present'] }}</span>
            <span class="rg-chip-label">Present</span>
        </div>
        <div class="rg-chip rg-chip-absent">
            <span class="rg-chip-value">{{ $counts['absent'] }}</span>
            <span class="rg-chip-label">Absent</span>
        </div>
        <div class="rg-chip rg-chip-late">
            <span class="rg-chip-value">{{ $counts['late'] }}</span>
            <span class="rg-chip-label">Late</span>
        </div>
        <div class="rg-chip rg-chip-note">
            <i class="icon-lock2"></i>
            <span>This record is locked — a submitted period register cannot be edited or re-taken.</span>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="rg-table-head">
                <div>
                    <h6 class="ma-schedule-title"><i class="icon-users mr-2"></i> Student Attendance</h6>
                    <p class="rg-table-sub">List of all students with their attendance status for this period.</p>
                </div>
                <div class="rg-search">
                    <i class="icon-search4"></i>
                    <input type="text" id="rg-search-input" class="form-control" placeholder="Search student..." autocomplete="off">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table ma-table" id="rg-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Section</th>
                        <th>Admission No</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($entries as $entry)
                        @php $studentUser = optional(optional($entry->student_record)->user); @endphp
                        <tr data-search="{{ strtolower(($studentUser->name ?? '') . ' ' . (optional($entry->student_record)->adm_no ?? '')) }}">
                            <td>{{ $loop->iteration }}</td>
                            <td>
                                <div class="ma-teacher">
                                    <span class="ma-avatar" aria-hidden="true">{{ strtoupper(substr($studentUser->name ?? '?', 0, 1)) }}@if($studentUser->name && \App\Helpers\Qs::hasRealPhoto($studentUser->photo))<img src="{{ $studentUser->photo }}" alt="" onerror="this.remove()">@endif</span>
                                    <span class="ma-teacher-name">{{ $studentUser->name }}</span>
                                </div>
                            </td>
                            <td>{{ optional(optional($entry->student_record)->section)->name ?: '—' }}</td>
                            <td>{{ optional($entry->student_record)->adm_no ?: '—' }}</td>
                            <td>
                                @if($entry->status === 'present')
                                    <span class="rg-pill rg-pill-present">Present</span>
                                @elseif($entry->status === 'late')
                                    <span class="rg-pill rg-pill-late">Late</span>
                                @else
                                    <span class="rg-pill rg-pill-absent">Absent</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr id="rg-no-results" style="display:none">
                        <td colspan="5" class="text-center text-muted py-3">No students match your search.</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card rg-actions-card">
        <div class="card-body rg-actions">
            <div class="rg-actions-btns">
                <a href="{{ route('attendance.periods.print', $session->id) }}" target="_blank" class="btn btn-primary"><i class="icon-printer mr-1"></i> Print</a>
                <a href="{{ route('attendance.periods.export_register', $session->id) }}" class="btn btn-light"><i class="icon-file-spreadsheet mr-1"></i> Export CSV</a>
                <a href="{{ route('attendance.periods.pdf', $session->id) }}" class="btn btn-light"><i class="icon-file-pdf mr-1"></i> Download PDF</a>
                <a href="{{ route('attendance.periods.report', ['class_id' => $session->my_class_id, 'subject_id' => $session->subject_id]) }}" class="btn btn-light"><i class="icon-stats-bars2 mr-1"></i> View subject report</a>
            </div>
            <p class="rg-readonly-note">This is a read-only view of a submitted attendance register. For any corrections, please contact the administrator.</p>
        </div>
    </div>

    <script>
        // Client-side only: filters the displayed register rows by
        // student name / admission number. Never touches the data.
        (function () {
            var input = document.getElementById('rg-search-input');
            var table = document.getElementById('rg-table');
            if (!input || !table) { return; }
            var rows = table.querySelectorAll('tbody tr[data-search]');
            var empty = document.getElementById('rg-no-results');
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase();
                var visible = 0;
                rows.forEach(function (row) {
                    var show = q === '' || row.getAttribute('data-search').indexOf(q) !== -1;
                    row.style.display = show ? '' : 'none';
                    if (show) { visible++; }
                });
                if (empty) { empty.style.display = visible === 0 ? '' : 'none'; }
            });
        })();
    </script>
@endsection
