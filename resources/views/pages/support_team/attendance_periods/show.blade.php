@extends('layouts.master')
@section('page_title', 'Mark Attendance — Submitted')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">
                {{ optional($session->subject)->name }} — {{ optional($session->my_class)->name }}
                <span class="badge badge-success ml-2">Submitted ✓ Locked</span>
            </h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif

            <div class="row mb-3">
                <div class="col-md-3 col-6 mb-2">
                    <div class="text-muted font-size-sm">Date</div>
                    <strong>{{ $session->date->format('l, d M Y') }}</strong>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="text-muted font-size-sm">Time</div>
                    <strong>{{ $time_label }}</strong>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="text-muted font-size-sm">Teacher</div>
                    <strong>{{ optional($session->teacher)->name ?: '—' }}</strong>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="text-muted font-size-sm">Submitted at</div>
                    <strong>{{ $session->submitted_at ? $session->submitted_at->format('d M Y, g:i A') : '—' }}</strong>
                </div>
            </div>

            <div class="alert alert-light border mb-3">
                <span class="text-muted font-size-sm d-block">TOPIC TAUGHT</span>
                <strong style="font-size: 105%">{{ $session->topic }}</strong>
            </div>

            <div class="d-flex flex-wrap align-items-center mb-3">
                <span class="badge badge-light mr-2 mb-1" style="font-size: 100%">Students: {{ $counts['total'] }}</span>
                <span class="badge badge-success mr-2 mb-1" style="font-size: 100%">Present: {{ $counts['present'] }}</span>
                <span class="badge badge-danger mr-2 mb-1" style="font-size: 100%">Absent: {{ $counts['absent'] }}</span>
                <span class="badge badge-warning mr-2 mb-1" style="font-size: 100%">Late: {{ $counts['late'] }}</span>
                <span class="text-muted mb-1"><i class="icon-lock2 mr-1"></i> This record is locked — a submitted period register cannot be edited or re-taken.</span>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Admission No</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($entries as $entry)
                        <tr>
                            <td><strong>{{ optional(optional($entry->student_record)->user)->name }}</strong></td>
                            <td>{{ optional(optional($entry->student_record)->section)->name ?: '—' }}</td>
                            <td>{{ optional($entry->student_record)->adm_no ?: '—' }}</td>
                            <td>
                                @if($entry->status === 'present')
                                    <span class="badge badge-success">Present</span>
                                @elseif($entry->status === 'late')
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

            <a href="{{ route('attendance.periods.index', ['date' => $session->date->format('Y-m-d')]) }}" class="btn btn-light mt-2">Back to periods</a>
            <a href="{{ route('attendance.periods.report', ['class_id' => $session->my_class_id, 'subject_id' => $session->subject_id]) }}" class="btn btn-light mt-2 ml-2">View subject report</a>
        </div>
    </div>
@endsection
