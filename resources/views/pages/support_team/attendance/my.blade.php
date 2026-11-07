@extends('layouts.master')
@section('page_title', 'My Attendance')
@section('content')
    {{-- Loop 108: legacy card-header chrome dropped (panel options +
         the repeated "Child Attendance" title — the common header
         carries the page title). Shared pat shell now; the filter,
         summary and both tables below are unchanged in content. --}}
    <div class="pat-list">
        <div class="card pat-card">
        <div class="card-body">
            @if(!$student)
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => $is_parent
                        ? 'No student record is linked to your account yet. Please contact the school office so they can link your child to your account.'
                        : 'No student record is linked to your account yet. Please contact the school office.',
                ])
            @else
                {{-- Workstream BW: filter bar moved onto the shared
                     pat-filter-form (was form-inline). Same GET
                     fields and auto-submit onchange behaviour. --}}
                <form method="GET" action="{{ route('attendance.my') }}" class="pat-filter-form mb-3">
                    @if($students->count() > 1)
                        <div class="pat-filter-field">
                            <label for="att_my_student">Child</label>
                            <select id="att_my_student" name="student_id" class="form-control" onchange="this.form.submit()">
                                @foreach($students as $s)
                                    <option value="{{ $s->id }}" {{ $student->id === $s->id ? 'selected' : '' }}>{{ optional($s->user)->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="pat-filter-field">
                        <label for="att_my_month">Month</label>
                        <input id="att_my_month" type="month" name="month" class="form-control" value="{{ $view === 'month' ? $month->format('Y-m') : '' }}" onchange="this.form.submit()">
                    </div>
                    @if($view === 'month')
                        <div class="pat-filter-field">
                            <label>&nbsp;</label>
                            <a class="btn btn-light" href="{{ route('attendance.my', $student ? ['student_id' => $student->id] : []) }}">Whole session</a>
                        </div>
                    @endif
                    <div class="pat-filter-action">
                        <span class="text-muted">{{ optional($student->my_class)->name }}@if($student->section) &middot; {{ $student->section->name }}@endif</span>
                    </div>
                </form>

                <div class="d-flex flex-wrap align-items-center mb-3 l108-badges">
                    <span class="badge badge-success mr-2 mb-1">Present: {{ $summary['present'] }}</span>
                    <span class="badge badge-danger mr-2 mb-1">Absent: {{ $summary['absent'] }}</span>
                    <span class="badge badge-warning mr-2 mb-1">Late: {{ $summary['late'] }}</span>
                    <span class="badge badge-info mr-2 mb-1">Periods marked: {{ $summary['marked'] }}</span>
                    @if($summary['percent'] !== null)
                        <span class="badge badge-primary mb-1">Attendance: {{ $summary['percent'] }}%</span>
                    @endif
                </div>

                @if($view === 'session')
                    <p class="mb-2"><strong>Whole session</strong> <span class="text-muted">— every month so far. Pick a month to see its period registers.</span></p>
                    @if(isset($months) && $months->isNotEmpty())
                        <div class="sd-monthstrip">
                            @foreach($months as $m)
                                <a class="sd-monthchip" href="{{ route('attendance.my', ['month' => $m['key'], 'student_id' => $student->id]) }}">
                                    <strong>{{ $m['label'] }}</strong>
                                    <span>{{ $m['percent'] === null ? '—' : $m['percent'] . '%' }} · {{ $m['marked'] }} periods</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if($entries->isEmpty())
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-calendar2',
                        'message' => $view === 'session' ? 'No period attendance has been recorded yet this session.' : 'No period attendance was recorded in ' . $month->format('F Y') . '.',
                    ])
                @else
                    @if($summary['percent'] !== null)
                        <div class="progress mb-3" style="height:8px">
                            <div class="progress-bar {{ $summary['percent'] >= 75 ? 'bg-success' : ($summary['percent'] >= 50 ? 'bg-warning' : 'bg-danger') }}" role="progressbar" style="width: {{ $summary['percent'] }}%" aria-valuenow="{{ $summary['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    @endif

                    <h6 class="mb-2">By Subject</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-striped l108-stack hk-std">
                            <colgroup><col style="width:30%"><col style="width:14%"><col style="width:14%"><col style="width:12%"><col style="width:14%"><col style="width:16%"></colgroup>
                            <thead><tr><th>Subject</th><th>Present</th><th>Absent</th><th>Late</th><th>Periods</th><th>Attendance %</th></tr></thead>
                            <tbody>
                            @foreach($subjects as $row)
                                <tr>
                                    <td data-label="Subject">{{ optional($row['subject'])->name ?: '—' }}</td>
                                    <td data-label="Present">{{ $row['present'] }}</td>
                                    <td data-label="Absent">{{ $row['absent'] }}</td>
                                    <td data-label="Late">{{ $row['late'] }}</td>
                                    <td data-label="Periods">{{ $row['marked'] }}</td>
                                    <td data-label="Attendance %" class="hk-nw">{{ $row['percent'] === null ? '—' : $row['percent'] . '%' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($view === 'month')
                    <h6 class="mb-2">Period Registers</h6>
                    <div class="table-responsive">
                        <table class="table table-striped l108-stack hk-std">
                            <colgroup><col style="width:26%"><col style="width:24%"><col style="width:30%"><col style="width:20%"></colgroup>
                            <thead><tr><th>Date</th><th>Subject</th><th>Topic Taught</th><th>Status</th></tr></thead>
                            <tbody>
                            @foreach($entries as $entry)
                                <tr>
                                    <td data-label="Date" class="hk-nw">{{ $entry->session->date->format('d M Y') }} <span class="text-muted">({{ $entry->session->date->format('l') }})</span></td>
                                    <td data-label="Subject">{{ optional($entry->session->subject)->name ?: '—' }}</td>
                                    <td data-label="Topic Taught">{{ $entry->session->topic ?: '—' }}</td>
                                    <td data-label="Status" class="hk-nw">
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
                    @endif
                    <p class="text-muted mb-0"><small>Attendance % = (present + late) / periods marked. Periods with no submitted register are not counted as absent.</small></p>
                @endif
            @endif
        </div>
        </div>
    </div>
@endsection