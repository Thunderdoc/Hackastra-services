@extends('layouts.master')
@section('page_title', 'My Attendance')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">{{ $is_parent ? 'Child Attendance' : 'My Attendance' }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if(!$student)
                <p class="text-muted mb-0">
                    {{ $is_parent
                        ? 'No student record is linked to your account yet. Please contact the school office so they can link your child to your account.'
                        : 'No student record is linked to your account yet. Please contact the school office.' }}
                </p>
            @else
                <form method="GET" action="{{ route('attendance.my') }}" class="form-inline mb-3">
                    @if($students->count() > 1)
                        <label class="mr-2">Child</label>
                        <select name="student_id" class="form-control mr-3" onchange="this.form.submit()">
                            @foreach($students as $s)
                                <option value="{{ $s->id }}" {{ $student->id === $s->id ? 'selected' : '' }}>{{ optional($s->user)->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <label class="mr-2">Month</label>
                    <input type="month" name="month" class="form-control mr-3" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
                    <span class="text-muted">{{ optional($student->my_class)->name }}@if($student->section) &middot; {{ $student->section->name }}@endif</span>
                </form>

                <div class="d-flex flex-wrap align-items-center mb-3">
                    <span class="badge badge-success mr-2 mb-1" style="font-size: 100%">Present: {{ $summary['present'] }}</span>
                    <span class="badge badge-danger mr-2 mb-1" style="font-size: 100%">Absent: {{ $summary['absent'] }}</span>
                    <span class="badge badge-warning mr-2 mb-1" style="font-size: 100%">Late: {{ $summary['late'] }}</span>
                    <span class="badge badge-info mr-2 mb-1" style="font-size: 100%">Marked days: {{ $summary['marked'] }}</span>
                    @if($summary['percent'] !== null)
                        <span class="badge badge-primary mb-1" style="font-size: 100%">Attendance: {{ $summary['percent'] }}%</span>
                    @endif
                </div>

                @if($days->isEmpty())
                    <p class="text-muted mb-0">No attendance was recorded in {{ $month->format('F Y') }}.</p>
                @else
                    @if($summary['percent'] !== null)
                        <div class="progress mb-3" style="height:8px">
                            <div class="progress-bar {{ $summary['percent'] >= 75 ? 'bg-success' : ($summary['percent'] >= 50 ? 'bg-warning' : 'bg-danger') }}" role="progressbar" style="width: {{ $summary['percent'] }}%" aria-valuenow="{{ $summary['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead><tr><th>Date</th><th>Day</th><th>Status</th></tr></thead>
                            <tbody>
                            @foreach($days as $day)
                                <tr>
                                    <td>{{ $day->date->format('d M Y') }}</td>
                                    <td>{{ $day->date->format('l') }}</td>
                                    <td>
                                        @if($day->status === 'present')
                                            <span class="badge badge-success">Present</span>
                                        @elseif($day->status === 'late')
                                            <span class="badge badge-warning">Late</span>
                                        @else
                                            <span class="badge badge-danger">Absent</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted mb-0"><small>Attendance % = (present + late) / marked days. Days with no record are not counted as absent.</small></p>
                @endif
            @endif
        </div>
    </div>
@endsection
