@extends('layouts.master')
@section('page_title', 'Student Attendance Report')
@section('content')
    @php
        $studentUser = $student->user;
        $srParams = ['month' => $month->format('Y-m'), 'months' => $months ?? 1];
    @endphp

    @component('partials.patterns.list_page')
        @slot('subtitle')
            {{ $studentUser->name }} · {{ $student->adm_no }} · {{ optional($student->my_class)->name }} {{ optional($student->section)->name }} · {{ $range_label }}
        @endslot
        @slot('action')
            <a href="{{ route('attendance.defaulters') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Low Attendance</a>
            <a href="{{ route('attendance.student_report.pdf', array_merge([$student_hash], $srParams)) }}" class="btn btn-light btn-sm"><i class="icon-file-pdf mr-1"></i> Download PDF</a>
            <a href="{{ route('attendance.student_report.export', array_merge([$student_hash], $srParams)) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export CSV</a>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('attendance.student_report', $student_hash) }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="sr_month">Month</label>
                    <input type="month" name="month" id="sr_month" class="form-control" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
                </div>
                <div class="pat-filter-field">
                    <label for="sr_months">Period</label>
                    <select name="months" id="sr_months" class="form-control" onchange="this.form.submit()">
                        @foreach([1 => 'This month', 2 => 'Last 2 months', 3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'] as $mVal => $mLabel)
                            <option value="{{ $mVal }}" {{ ($months ?? 1) === $mVal ? 'selected' : '' }}>{{ $mLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        @endslot

        <div class="marx-stats">
            <div class="marx-stat marx-stat-present">
                <div class="marx-stat-num">{{ $counts['present'] }}</div>
                <div class="marx-stat-label">Present</div>
                <div class="marx-stat-cap">Periods attended on time</div>
            </div>
            <div class="marx-stat marx-stat-absent">
                <div class="marx-stat-num">{{ $counts['absent'] }}</div>
                <div class="marx-stat-label">Absent</div>
                <div class="marx-stat-cap">Periods missed</div>
            </div>
            <div class="marx-stat marx-stat-late">
                <div class="marx-stat-num">{{ $counts['late'] }}</div>
                <div class="marx-stat-label">Late</div>
                <div class="marx-stat-cap">Counted as attended</div>
            </div>
            <div class="marx-stat marx-stat-periods">
                <div class="marx-stat-num">{{ $counts['marked'] }}</div>
                <div class="marx-stat-label">Periods Marked</div>
                <div class="marx-stat-cap">{{ $range_label }}</div>
            </div>
            <div class="marx-stat marx-stat-registers">
                <div class="marx-stat-num">{{ $percent === null ? '—' : $percent . '%' }}</div>
                <div class="marx-stat-label">Attendance</div>
                <div class="marx-stat-cap">{{ $status_label }}</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h6 class="card-title">Subject Breakdown</h6></div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Subject</th><th>Present</th><th>Absent</th><th>Late</th><th>Periods</th><th>Attendance %</th></tr></thead>
                    <tbody>
                    @forelse($subjects as $row)
                        <tr>
                            <td>{{ optional($row['subject'])->name ?: '—' }}</td>
                            <td>{{ $row['present'] }}</td><td>{{ $row['absent'] }}</td><td>{{ $row['late'] }}</td><td>{{ $row['marked'] }}</td>
                            <td>{{ $row['percent'] === null ? '—' : $row['percent'] . '%' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No periods marked in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h6 class="card-title">Register — Latest Entries @if($entries_total > $entries->count())<span class="text-muted">(showing {{ $entries->count() }} of {{ $entries_total }})</span>@endif</h6></div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Subject</th><th>Period</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($entries as $entry)
                        @php $session = $entry->session; @endphp
                        <tr>
                            <td>{{ $session && $session->date ? \Illuminate\Support\Carbon::parse($session->date)->format('d M Y') : '—' }}</td>
                            <td>{{ optional(optional($session)->subject)->name ?: '—' }}</td>
                            <td>{{ optional(optional(optional($session)->time_table)->time_slot)->full_time ?? '—' }}</td>
                            <td><span class="badge badge-{{ $entry->status === 'present' ? 'success' : ($entry->status === 'late' ? 'warning' : 'danger') }}">{{ ucfirst($entry->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No register entries in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endcomponent
@endsection
