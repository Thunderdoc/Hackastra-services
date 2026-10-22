@extends('layouts.master')
@section('page_title', 'Attendance Report')
@section('content')

    {{-- Monthly Attendance Report — screen. Workstream CD rebuilt it
         on the shared list_page pattern (Loop 28); this redesign
         (Loop 80) gives it the document's summary chips, status
         pills and class summary, all from the same
         AttendanceController::monthlyReportData assembly the print
         preview and the PDF render — screen, paper and download can
         never disagree. The old in-page print-doc-head/foot retired:
         the printed document now lives on its own preview page
         (attendance.report.print) with a real downloadable PDF
         beside it (attendance.report.pdf). Filters keep the exact
         existing contract: class_id + month, GET, auto-submit. --}}
    @php
        $marxPalette = ['#e8effc', '#e6f6ec', '#fdf3e0', '#efe9fd', '#fdecec', '#e0f2f1'];
        $marxParams = ['class_id' => $selected_class ? $selected_class->id : '', 'month' => $month->format('Y-m')];
    @endphp

    @component('partials.patterns.list_page')
        @slot('action')
            @if($selected_class)
                <a href="{{ route('attendance.report.print', $marxParams) }}" target="_blank" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> Preview</a>
                <a href="{{ route('attendance.report.print', $marxParams + ['autoprint' => 1]) }}" target="_blank" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Print</a>
                <a href="{{ route('attendance.report.pdf', $marxParams) }}" class="btn btn-light btn-sm"><i class="icon-file-pdf mr-1"></i> PDF</a>
            @endif
            <a href="{{ route('attendance.export', $marxParams) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export</a>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('attendance.report') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="ar_class_id">Class:</label>
                    <select name="class_id" id="ar_class_id" class="form-control" onchange="this.form.submit()">
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="ar_month">Month:</label>
                    <input type="month" name="month" id="ar_month" class="form-control" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        @endslot

        @if($selected_class)
            {{-- Summary chips — entries vs registers kept distinct,
                 exactly as the document labels them. --}}
            <div class="marx-stats">
                <div class="marx-stat marx-stat-present">
                    <div class="marx-stat-num">{{ $totals['present'] }}</div>
                    <div class="marx-stat-label">Total Present</div>
                    <div class="marx-stat-cap">{{ number_format($shares['present'], 1) }}% of all marked entries</div>
                </div>
                <div class="marx-stat marx-stat-absent">
                    <div class="marx-stat-num">{{ $totals['absent'] }}</div>
                    <div class="marx-stat-label">Total Absent</div>
                    <div class="marx-stat-cap">{{ number_format($shares['absent'], 1) }}% of all marked entries</div>
                </div>
                <div class="marx-stat marx-stat-late">
                    <div class="marx-stat-num">{{ $totals['late'] }}</div>
                    <div class="marx-stat-label">Total Late</div>
                    <div class="marx-stat-cap">{{ number_format($shares['late'], 1) }}% of all marked entries</div>
                </div>
                <div class="marx-stat marx-stat-periods">
                    <div class="marx-stat-num">{{ $totals['marked'] }}</div>
                    <div class="marx-stat-label">Total Periods Marked</div>
                    <div class="marx-stat-cap">Student-period entries this month</div>
                </div>
                <div class="marx-stat marx-stat-registers">
                    <div class="marx-stat-num">{{ $sessions_held }}</div>
                    <div class="marx-stat-label">Registers Submitted</div>
                    <div class="marx-stat-cap">Period registers submitted this month</div>
                </div>
            </div>
        @endif

        @if($rows->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users',
                'message' => 'No active students in this class yet.',
            ])
        @else
            <table class="table">
                <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>Student</th>
                    <th>Section</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Late</th>
                    <th>Periods</th>
                    <th style="min-width:170px">Attendance %</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    @php
                        $marxName = optional($row['student']->user)->name;
                        $marxInitials = '';
                        foreach (preg_split('/\s+/', trim((string) $marxName)) as $w) {
                            if ($w !== '') {
                                $marxInitials .= strtoupper(substr($w, 0, 1));
                                if (strlen($marxInitials) >= 2) { break; }
                            }
                        }
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><span class="marx-ava" style="background:{{ $marxPalette[$loop->index % count($marxPalette)] }}" aria-hidden="true">{{ $marxInitials ?: '?' }}</span>{{ $marxName }}</td>
                        <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                        <td>{{ $row['present'] }}</td>
                        <td>@if($row['absent'] > 0)<span class="marx-num-bad">{{ $row['absent'] }}</span>@else{{ $row['absent'] }}@endif</td>
                        <td>@if($row['late'] > 0)<span class="marx-num-late">{{ $row['late'] }}</span>@else{{ $row['late'] }}@endif</td>
                        <td>{{ $row['marked'] }}</td>
                        <td>
                            @if($row['percent'] === null)
                                <span class="text-muted">—</span>
                            @else
                                <div class="marx-bar">
                                    <div class="marx-bar-fill marx-fill-{{ $row['status_band'] }}" role="progressbar" style="width: {{ $row['percent'] }}%" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <small>{{ $row['percent'] }}%</small>
                            @endif
                        </td>
                        <td><span class="marx-pill marx-pill-{{ $row['status_band'] }}">{{ $row['status_label'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="marx-classline">
                <span><strong>{{ $class_summary['students'] }}</strong> Total Students</span>
                <span><strong>{{ $class_summary['with_100'] }}</strong> With 100%</span>
                <span><strong>{{ $class_summary['below_75'] }}</strong> Below 75%</span>
                <span><strong>{{ $class_summary['registers'] }}</strong> Registers Submitted</span>
            </div>

            <p class="text-muted mb-0 mt-2"><small>Counts are period registers: Attendance % = (present + late) / periods marked. Periods with no submitted register are excluded — never counted as absent.<br>
            Status policy: <strong>Excellent</strong> 90% and above &middot; <strong>Good</strong> 75&ndash;89% &middot; <strong>Needs Attention</strong> 50&ndash;74% &middot; <strong>Critical</strong> below 50%.</small></p>
        @endif
    @endcomponent

@endsection
