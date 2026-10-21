@extends('layouts.master')
@section('page_title', 'Attendance Defaulters')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Attendance Defaulters — below {{ $threshold }}%</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('attendance.defaulters') }}" class="form-inline mb-3">
                <label class="mr-2">Class</label>
                <select name="class_id" class="form-control mr-3" onchange="this.form.submit()">
                    <option value="">All Classes</option>
                    @foreach($classes as $class)
                        <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                    @endforeach
                </select>
                <label class="mr-2">Month</label>
                <input type="month" name="month" class="form-control mr-3" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
                <label class="mr-2">Below</label>
                <select name="threshold" class="form-control mr-3" onchange="this.form.submit()">
                    @foreach($thresholds as $option)
                        <option value="{{ $option }}" {{ $threshold === $option ? 'selected' : '' }}>{{ $option }}%</option>
                    @endforeach
                </select>
                <span class="text-muted">{{ $rows->count() }} defaulter(s) out of {{ $scanned }} student(s) with records in {{ $month->format('F Y') }}</span>
            </form>

            @if($rows->isEmpty())
                <div class="alert alert-success border-0 mb-0">
                    No students below {{ $threshold }}% attendance for {{ $month->format('F Y') }}{{ $selected_class ? ' in ' . $selected_class->name : '' }}. 
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Student</th>
                            <th>Class</th>
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
                                <td><strong>{{ optional($row['student']->user)->name }}</strong></td>
                                <td>{{ optional($row['student']->my_class)->name ?: '—' }}</td>
                                <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                                <td>{{ $row['present'] }}</td>
                                <td>{{ $row['absent'] }}</td>
                                <td>{{ $row['late'] }}</td>
                                <td>{{ $row['marked'] }}</td>
                                <td>
                                    <div class="progress" style="height:8px">
                                        <div class="progress-bar {{ $row['percent'] >= 50 ? 'bg-warning' : 'bg-danger' }}" role="progressbar" style="width: {{ $row['percent'] }}%" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small>{{ $row['percent'] }}%</small>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-muted mb-0"><small>Attendance % = (present + late) / marked days, worst first. Students with no attendance records in the month are excluded — never counted as 0%.</small></p>
            @endif
        </div>
    </div>
@endsection
