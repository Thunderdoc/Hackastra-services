@extends('layouts.master')
@section('page_title', 'Subject-wise Period Attendance Report')
@section('content')

    {{-- Workstream CD: rebuilt on the shared list_page pattern (Loop 28),
         the same chrome as Students / Payments. Class / Subject / From /
         To are the pattern filter row (same GET params, same
         auto-submit); Export CSV is the toolbar action (same route and
         params, shown under the same condition as before). The context
         badge strip, both tables (per-student + session log) and the
         counting rules are unchanged; the two "nothing here" cases are
         the designed empty state carrying the same sentences. --}}
    @component('partials.patterns.list_page')
        @slot('action')
            @if($selected_class && $selected_subject)
                {{-- Audit Fix Wave 1: the export is the session log for
                     the CHOSEN scope — pass class/subject only when the
                     teacher/admin actually chose them (present in this
                     request); on the default landing the export covers
                     every session in the date range instead of silently
                     inheriting the page's defaulted class/subject. --}}
                <a class="btn btn-light btn-sm" href="{{ route('attendance.periods.export', array_filter(['class_id' => request()->query('class_id'), 'subject_id' => request()->query('subject_id'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')])) }}"><i class="icon-file-download mr-1"></i> Export CSV</a>
            @endif
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('attendance.periods.report') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="pr_class_id">Class:</label>
                    <select name="class_id" id="pr_class_id" class="form-control" onchange="this.form.submit()">
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="pr_subject_id">Subject:</label>
                    <select name="subject_id" id="pr_subject_id" class="form-control" onchange="this.form.submit()">
                        @foreach($subjects as $subject)
                            <option value="{{ $subject->id }}" {{ $selected_subject && $selected_subject->id === $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="pr_from">From:</label>
                    <input type="date" name="from" id="pr_from" class="form-control" value="{{ $from->format('Y-m-d') }}" onchange="this.form.submit()">
                </div>
                <div class="pat-filter-field">
                    <label for="pr_to">To:</label>
                    <input type="date" name="to" id="pr_to" class="form-control" value="{{ $to->format('Y-m-d') }}" onchange="this.form.submit()">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        @endslot

        @if(!$selected_class || !$selected_subject)
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-stats-bars2',
                'message' => 'No class or subject is available yet.',
            ])
        @elseif($held === 0)
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-stats-bars2',
                'message' => 'No period sessions were submitted for ' . $selected_subject->name . ' (' . $selected_class->name . ') between ' . $from->format('d M Y') . ' and ' . $to->format('d M Y') . '.',
            ])
        @else
            <div class="d-flex flex-wrap align-items-center mb-3">
                <span class="badge badge-primary mr-2 mb-1" style="font-size: 100%">{{ $selected_subject->name }}</span>
                <span class="badge badge-light mr-2 mb-1" style="font-size: 100%">{{ $selected_class->name }}</span>
                <span class="badge badge-info mr-2 mb-1" style="font-size: 100%">Sessions held: {{ $held }}</span>
                <span class="badge badge-light mr-2 mb-1" style="font-size: 100%">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</span>
            </div>

            <h6 class="mb-2">Per-student attendance in this subject</h6>
            <div class="table-responsive mb-4">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Sessions Held</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Attendance %</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td><strong>{{ optional($row['student']->user)->name }}</strong></td>
                            <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                            <td>{{ $row['held'] }}</td>
                            <td>{{ $row['present'] }}</td>
                            <td>{{ $row['absent'] }}</td>
                            <td>{{ $row['late'] }}</td>
                            <td>
                                @if($row['percent'] === null)
                                    <span class="text-muted">—</span>
                                @else
                                    <span class="badge {{ $row['percent'] >= 75 ? 'badge-success' : ($row['percent'] >= 50 ? 'badge-warning' : 'badge-danger') }}">{{ $row['percent'] }}%</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <h6 class="mb-2">Session log</h6>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Teacher</th>
                        <th>Topic</th>
                        <th>Present</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($log as $entry)
                        <tr>
                            <td style="white-space: nowrap">{{ $entry['date']->format('d M Y') }}</td>
                            <td style="white-space: nowrap">{{ $entry['time_label'] }}</td>
                            <td>{{ $entry['teacher_name'] }}</td>
                            <td>
                                <a href="{{ route('attendance.periods.show', $entry['session']->id) }}">{{ $entry['topic'] }}</a>
                            </td>
                            <td>{{ $entry['present'] }} of {{ $entry['total'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
@endsection
