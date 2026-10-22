@extends('layouts.master')
@section('page_title', 'Attendance Report')
@section('content')

    {{-- Workstream CD: rebuilt on the shared list_page pattern (Loop 28),
         the same chrome as Students / Payments. Print / Export are the
         toolbar actions (same window.print() + export route), Class /
         Month are the pattern filter row (same GET params, same
         auto-submit), and the totals line rides at the top of the card
         so it still prints with the table, exactly as before. Rows,
         % bars and the counting footnote are unchanged. --}}
    @component('partials.patterns.list_page')
        @slot('action')
            <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
            <a href="{{ route('attendance.export', ['class_id' => $selected_class ? $selected_class->id : '', 'month' => $month->format('Y-m')]) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export</a>
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

        <p class="text-muted">Present: {{ $totals['present'] }} &middot; Absent: {{ $totals['absent'] }} &middot; Late: {{ $totals['late'] }} &middot; Periods marked: {{ $totals['marked'] }} &middot; Registers submitted: {{ $sessions_held }}</p>

        @if($rows->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users',
                'message' => 'No active students in this class yet.',
            ])
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Section</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Late</th>
                    <th>Periods</th>
                    <th style="min-width:180px">Attendance %</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ optional($row['student']->user)->name }}</td>
                        <td>{{ optional($row['student']->section)->name }}</td>
                        <td>{{ $row['present'] }}</td>
                        <td>{{ $row['absent'] }}</td>
                        <td>{{ $row['late'] }}</td>
                        <td>{{ $row['marked'] }}</td>
                        <td>
                            @if($row['percent'] === null)
                                <span class="text-muted">No records</span>
                            @else
                                <div class="progress" style="height:8px">
                                    <div class="progress-bar {{ $row['percent'] >= 75 ? 'bg-success' : ($row['percent'] >= 50 ? 'bg-warning' : 'bg-danger') }}" role="progressbar" style="width: {{ $row['percent'] }}%" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <small>{{ $row['percent'] }}%</small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="text-muted mb-0"><small>Counts are period registers: Attendance % = (present + late) / periods marked. Periods with no submitted register are excluded — never counted as absent.</small></p>
        @endif
    @endcomponent
@endsection
