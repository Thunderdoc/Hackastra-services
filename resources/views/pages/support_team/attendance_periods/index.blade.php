@extends('layouts.master')
@section('page_title', 'Mark Attendance')
@section('content')

    {{-- Workstream CD: rebuilt on the shared list_page pattern (Loop 28),
         the same chrome as Students / Payments. The date the header used
         to carry now opens the pattern subtitle, followed by the
         explainer (lock rule + oversight note, kept verbatim); the
         Subject-wise Report link is the toolbar action; Class / Date
         are the pattern filter row (same GET params, same auto-submit).
         Register rows, status pills, View / Take Attendance links and
         the teacher-vs-oversight rules are unchanged. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            {{ $date->format('l, d M Y') }} &middot; Attendance taken period by period, by each subject's own teacher.
            Once a period register is submitted it is <strong>locked</strong> — it cannot be edited.
            @if($is_teacher)
                Showing your periods for the selected date.
            @else
                You are viewing in oversight mode (read-only): registers are submitted by subject teachers.
            @endif
        @endslot
        @slot('action')
            <a href="{{ route('attendance.periods.report') }}" class="btn btn-light btn-sm"><i class="icon-stats-bars2 mr-1"></i> Subject-wise Report</a>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('attendance.periods.index') }}" class="pat-filter-form">
                @if(!$is_teacher)
                    <div class="pat-filter-field">
                        <label for="pa_class_id">Class:</label>
                        <select name="class_id" id="pa_class_id" class="form-control" onchange="this.form.submit()">
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="pat-filter-field">
                    <label for="pa_date">Date:</label>
                    <input type="date" name="date" id="pa_date" class="form-control" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        @endslot

        @if(session('flash_success'))
            <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
        @endif
        @if(session('flash_danger'))
            <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
        @endif

        @if($periods->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-calendar3',
                'message' => $is_teacher
                    ? 'No periods are scheduled for you on ' . $date->format('l') . '.'
                    : 'No periods are scheduled for this class on ' . $date->format('l') . '.',
            ])
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Subject</th>
                    <th>Class</th>
                    @if(!$is_teacher)<th>Teacher</th>@endif
                    <th>Status</th>
                    <th>Topic</th>
                    <th class="text-right">Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($periods as $row)
                    <tr>
                        <td style="white-space: nowrap">{{ $row['time_label'] }}</td>
                        <td><strong>{{ optional($row['subject'])->name }}</strong></td>
                        <td>{{ optional($row['class'])->name }}</td>
                        @if(!$is_teacher)<td>{{ optional($row['teacher'])->name ?: '—' }}</td>@endif
                        <td>
                            @if($row['session'])
                                <span class="badge badge-success">Submitted ✓</span>
                            @else
                                <span class="badge badge-warning">Not taken</span>
                            @endif
                        </td>
                        <td>{{ $row['session'] ? $row['session']->topic : '—' }}</td>
                        <td class="text-right" style="white-space: nowrap">
                            @if($row['session'])
                                <a href="{{ route('attendance.periods.show', $row['session']->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                            @elseif($is_teacher)
                                <a href="{{ route('attendance.periods.take', [$row['tt']->id, 'date' => $date->format('Y-m-d')]) }}" class="btn btn-primary btn-sm"><i class="icon-pencil7 mr-1"></i> Take Attendance</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endcomponent
@endsection
