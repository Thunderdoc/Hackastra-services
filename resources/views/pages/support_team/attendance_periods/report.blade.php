@extends('layouts.master')
@section('page_title', 'Subject-wise Period Attendance Report')
@section('content')
    <div class="card">
        <div class="card-body">
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif

            <form method="GET" action="{{ route('attendance.periods.report') }}" class="form-inline mb-3">
                <label for="pr_class_id" class="mr-2">Class</label>
                <select name="class_id" id="pr_class_id" class="form-control mr-3" onchange="this.form.submit()">
                    @foreach($classes as $class)
                        <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                    @endforeach
                </select>
                <label for="pr_subject_id" class="mr-2">Subject</label>
                <select name="subject_id" id="pr_subject_id" class="form-control mr-3" onchange="this.form.submit()">
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" {{ $selected_subject && $selected_subject->id === $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                    @endforeach
                </select>
                <label for="pr_from" class="mr-2">From</label>
                <input type="date" name="from" id="pr_from" class="form-control mr-3" value="{{ $from->format('Y-m-d') }}" onchange="this.form.submit()">
                <label for="pr_to" class="mr-2">To</label>
                <input type="date" name="to" id="pr_to" class="form-control mr-3" value="{{ $to->format('Y-m-d') }}" onchange="this.form.submit()">
                @if($selected_class && $selected_subject)
                    <a class="btn btn-light" href="{{ route('attendance.periods.export', ['class_id' => $selected_class->id, 'subject_id' => $selected_subject->id, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}"><i class="icon-file-download mr-1"></i> Export CSV</a>
                @endif
            </form>

            @if(!$selected_class || !$selected_subject)
                <p class="text-muted mb-0">No class or subject is available yet.</p>
            @elseif($held === 0)
                <p class="text-muted mb-0">
                    No period sessions were submitted for {{ $selected_subject->name }} ({{ $selected_class->name }})
                    between {{ $from->format('d M Y') }} and {{ $to->format('d M Y') }}.
                </p>
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
        </div>
    </div>
@endsection
