@extends('layouts.master')
@section('page_title', 'Exam Date Sheet')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Exam Date Sheet</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            @if($exams->isEmpty())
                <div class="alert alert-warning border-0 mb-0">
                    <span>No exams have been created for the current session ({{ Qs::getCurrentSession() }}) yet.</span>
                </div>
            @elseif(!$my_class)
                <div class="alert alert-warning border-0 mb-0">
                    <span>
                    @if($viewer === 'parent')
                        No children are linked to your account yet, so there is no class date sheet to show.
                    @elseif($viewer === 'teacher')
                        You are not assigned to any class yet, so there is no date sheet to show.
                    @else
                        No class is available for a date sheet.
                    @endif
                    </span>
                </div>
            @else
                {{-- Scope selectors: the exam always; the child (parent)
                     or the class (teacher/admin) only within the set
                     the server has already scoped for this viewer. --}}
                <form method="get" action="{{ route('exam_dates.show') }}" class="l103-toolbar">
                    <div class="pat-fields">
                        <div class="pat-field">
                            <label for="ds_exam">Exam</label>
                            <select id="ds_exam" name="exam_id" class="form-control" onchange="this.form.submit()">
                                @foreach($exams as $ex)
                                    <option value="{{ $ex->id }}" {{ $ex->id === $exam->id ? 'selected' : '' }}>
                                        {{ $ex->name }} — Term {{ $ex->term }} ({{ $ex->year }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        @if($viewer === 'parent')
                            <div class="pat-field">
                                <label for="ds_child">Child</label>
                                <select id="ds_child" name="student_id" class="form-control" onchange="this.form.submit()">
                                    @foreach($children as $ch)
                                        <option value="{{ $ch->user_id }}" {{ $child && $ch->user_id === $child->user_id ? 'selected' : '' }}>
                                            {{ $ch->user->name }} — {{ $ch->my_class->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @elseif(in_array($viewer, ['teacher', 'admin']))
                            <div class="pat-field">
                                <label for="ds_class">Class</label>
                                <select id="ds_class" name="class_id" class="form-control" onchange="this.form.submit()">
                                    @foreach($classes as $mc)
                                        <option value="{{ $mc->id }}" {{ $mc->id === $my_class->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($sections->isNotEmpty())
                                <div class="pat-field">
                                    <label for="ds_section">Section</label>
                                    <select id="ds_section" name="section_id" class="form-control" onchange="this.form.submit()">
                                        <option value="">All sections</option>
                                        @foreach($sections as $sec)
                                            <option value="{{ $sec->id }}" {{ $section && $sec->id === $section->id ? 'selected' : '' }}>{{ $sec->name }}@if($sec->stream) ({{ strtoupper($sec->stream) }})@endif</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        @endif
                    </div>
                </form>

                <div class="l103-head">
                    <div>
                        <h5 class="mb-0">{{ $exam->name }}</h5>
                        <span class="text-muted">
                            Term {{ $exam->term }} &middot; {{ $exam->year }} &middot; Class: <strong>{{ $my_class->name }}</strong>
                            @if($section)
                                &middot; Section: <strong>{{ $section->name }}@if($section->stream) ({{ strtoupper($section->stream) }})@endif</strong>
                            @endif
                            @if($child)
                                &middot; {{ $viewer === 'parent' ? 'Child' : 'Student' }}: <strong>{{ $child->user->name }}</strong>
                            @endif
                        </span>
                    </div>
                    <div class="l103-actions">
                        <a href="{{ route('exam_dates.print', $scope_params) }}" target="_blank" class="btn btn-light">
                            <i class="icon-printer mr-1"></i> Print
                        </a>
                        <a href="{{ route('exam_dates.pdf', $scope_params) }}" class="btn btn-primary">
                            <i class="icon-file-pdf mr-1"></i> Download PDF
                        </a>
                    </div>
                </div>

                @if($announced === 0)
                    <div class="l103-empty">
                        <i class="icon-calendar3"></i>
                        <h6>Dates not announced yet</h6>
                        <p class="text-muted mb-0">
                            The school has not announced any dates for {{ $exam->name }} ({{ $my_class->name }}) yet.
                            This page will list every subject's date as soon as the school office publishes them.
                        </p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table l103-table">
                            <thead>
                            <tr>
                                <th style="width: 210px;">Date</th>
                                <th style="width: 120px;">Time</th>
                                <th>Subject</th>
                                <th>Note</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($rows as $r)
                                <tr>
                                    @if($r['entry'])
                                        <td class="font-weight-semibold">{{ $r['date_label'] }}</td>
                                        <td>{{ $r['time_label'] ?: '—' }}</td>
                                        <td>{{ $r['subject']->name }}</td>
                                        <td>{{ $r['entry']->note ?: '—' }}</td>
                                    @else
                                        <td><span class="badge badge-secondary">Not announced</span></td>
                                        <td>—</td>
                                        <td>{{ $r['subject']->name }}</td>
                                        <td>—</td>
                                    @endif
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted font-size-sm mb-0 mt-2">
                        {{ $announced }} of {{ $rows->count() }} subject{{ $rows->count() === 1 ? '' : 's' }} announced.
                        Dates are set by the school office and can change — check back before the exam.
                    </p>
                @endif
            @endif
        </div>
    </div>

@endsection
