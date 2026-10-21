@extends('layouts.master')
@section('page_title', 'Attendance Report')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Monthly Attendance Report</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('attendance.report') }}" class="form-inline mb-3">
                <label class="mr-2">Class</label>
                <select name="class_id" class="form-control mr-3" onchange="this.form.submit()">
                    @foreach($classes as $class)
                        <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                    @endforeach
                </select>
                <label class="mr-2">Month</label>
                <input type="month" name="month" class="form-control mr-3" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
                <span class="text-muted">Present: {{ $totals['present'] }} &middot; Absent: {{ $totals['absent'] }} &middot; Late: {{ $totals['late'] }} &middot; Marked days: {{ $totals['marked'] }}</span>
            </form>

            @if($rows->isEmpty())
                <p class="text-muted mb-0">No active students in this class yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Student</th>
                            <th>Section</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Late</th>
                            <th>Marked</th>
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
                </div>
                <p class="text-muted mb-0"><small>Attendance % = (present + late) / marked days. Days with no record are excluded — never counted as absent.</small></p>
            @endif
        </div>
    </div>
@endsection
