@extends('layouts.master')
@section('page_title', 'Period Attendance')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Period Attendance — {{ $date->format('l, d M Y') }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif

            <p class="text-muted">
                Attendance taken period by period, by each subject's own teacher.
                Once a period register is submitted it is <strong>locked</strong> — it cannot be edited.
                @if($is_teacher)
                    Showing your periods for the selected date.
                @else
                    You are viewing in oversight mode (read-only): registers are submitted by subject teachers.
                @endif
            </p>

            <form method="GET" action="{{ route('attendance.periods.index') }}" class="form-inline mb-3">
                @if(!$is_teacher)
                    <label for="pa_class_id" class="mr-2">Class</label>
                    <select name="class_id" id="pa_class_id" class="form-control mr-3" onchange="this.form.submit()">
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                @endif
                <label for="pa_date" class="mr-2">Date</label>
                <input type="date" name="date" id="pa_date" class="form-control mr-3" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()">
                <a href="{{ route('attendance.periods.report') }}" class="btn btn-light"><i class="icon-stats-bars2 mr-1"></i> Subject-wise Report</a>
            </form>

            @if($periods->isEmpty())
                <p class="text-muted mb-0">
                    @if($is_teacher)
                        No periods are scheduled for you on {{ $date->format('l') }}.
                    @else
                        No periods are scheduled for this class on {{ $date->format('l') }}.
                    @endif
                </p>
            @else
                <div class="table-responsive">
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
                </div>
            @endif
        </div>
    </div>
@endsection
