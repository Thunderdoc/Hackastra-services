@extends('layouts.master')
@section('page_title', 'Attendance')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Daily Attendance</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif

            <form method="GET" action="{{ route('attendance.index') }}" class="form-inline mb-3">
                <label class="mr-2">Class</label>
                <select name="class_id" class="form-control mr-3" onchange="this.form.submit()">
                    @foreach($classes as $class)
                        <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                    @endforeach
                </select>
                <label class="mr-2">Date</label>
                <input type="date" name="date" class="form-control mr-3" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()">
                <span class="text-muted">Present: {{ $summary['present'] }} &middot; Absent: {{ $summary['absent'] }} &middot; Late: {{ $summary['late'] }}</span>
            </form>

            @if($students->isEmpty())
                <p class="text-muted mb-0">No active students in this class yet.</p>
            @else
                <form method="POST" action="{{ route('attendance.store') }}">
                    @csrf
                    <input type="hidden" name="class_id" value="{{ $selected_class->id }}">
                    <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>Student</th><th>Section</th><th>Admission No</th><th>Status</th></tr></thead>
                            <tbody>
                            @foreach($students as $student)
                                @php($current = $existing[$student->id] ?? 'present')
                                <tr>
                                    <td><strong>{{ optional($student->user)->name }}</strong></td>
                                    <td>{{ optional($student->section)->name ?: '—' }}</td>
                                    <td>{{ $student->adm_no ?: '—' }}</td>
                                    <td>
                                        @foreach(['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late'] as $value => $label)
                                            <label class="mr-3">
                                                <input type="radio" name="statuses[{{ $student->id }}]" value="{{ $value }}" {{ $current === $value ? 'checked' : '' }}> {{ $label }}
                                            </label>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn btn-primary mt-2">Save Attendance</button>
                </form>
            @endif
        </div>
    </div>
@endsection
