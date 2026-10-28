@extends('layouts.master')
@section('page_title', 'Homework Tracker')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Homework Tracker
@endsection
@section('page_actions')
    <a href="{{ route('homework_tracker.index') }}" class="btn btn-light">All Assignments</a>
@endsection
@section('content')
    <p>Track submission progress per assignment. Counts are read from existing submissions only; nothing here can be edited or submitted.</p>

    @if($assignments->isEmpty())
        <div class="card">
            <div class="card-body">
                <p class="mb-0">No assignments found.</p>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Class / Subject</th>
                                <th>Due Date</th>
                                <th>Submitted</th>
                                <th>Pending</th>
                                <th>Graded</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($assignments as $assignment)
                                <tr>
                                    <td>{{ $assignment->title }}</td>
                                    <td>{{ optional($assignment->my_class)->name }} @if(optional($assignment->section)->name) ({{ optional($assignment->section)->name }}) @endif — {{ optional($assignment->subject)->name }}</td>
                                    <td>{{ $assignment->due_at ? $assignment->due_at->format('d M Y') : '—' }}</td>
                                    <td>Submitted: {{ $assignment->submitted_count }} of {{ $assignment->roster_count }}</td>
                                    <td>Pending: {{ $assignment->pending_count }}</td>
                                    <td>Graded: {{ $assignment->graded_count }}</td>
                                    <td>
                                        <a href="{{ route('homework_tracker.index', ['assignment_id' => $assignment->id]) }}" class="btn btn-primary btn-sm">View Pending</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if($selected)
        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">{{ $selected->title }}</h5>
                <p class="text-muted">{{ optional($selected->my_class)->name }} @if(optional($selected->section)->name) ({{ optional($selected->section)->name }}) @endif — {{ optional($selected->subject)->name }} &middot; Due {{ $selected->due_at ? $selected->due_at->format('d M Y') : '—' }}</p>
                <p>Submitted: {{ $selected->submitted_count }} of {{ $selected->roster_count }} &middot; Pending: {{ $selected->pending_count }} &middot; Graded: {{ $selected->graded_count }}</p>

                <h6>Students still pending ({{ $selected->pending_count }})</h6>
                @if($selected->pending_students->isEmpty())
                    <p class="mb-0">Every student on the roster has submitted this assignment.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student</th>
                                    <th>Section</th>
                                    <th>Admission No</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($selected->pending_students as $index => $record)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ optional($record->user)->name }}</td>
                                        <td>{{ optional($record->section)->name ?: '—' }}</td>
                                        <td>{{ $record->adm_no ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection
