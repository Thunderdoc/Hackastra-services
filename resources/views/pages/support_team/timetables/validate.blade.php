@extends('layouts.master')
@section('page_title', 'Timetable Validation — ' . $record->name)
@section('content')

    {{-- Timetable Validation (Scheduling Engine Slice E): the
         shared validator's verdict on this record — cross-record
         teacher clashes, class collisions, requirement coverage
         and teacher loads. Errors mean the grid cannot be trusted;
         warnings are planning gaps to close. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            {{ $record->name }} · {{ optional($my_class)->name ?: 'Deleted class' }} · {{ $record->section_id ? 'Section ' . optional($record->section)->name : 'All Sections' }} · {{ $record->year }} — {{ $cells }} periods placed. Checked against every timetable of this session, not just this class.
        @endslot
        @slot('action')
            <a href="{{ route('tt.index') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Manage Timetables</a>
            <a href="{{ route('ttr.manage', $record->id) }}" class="btn btn-light btn-sm"><i class="icon-pencil mr-1"></i> Manage</a>
        @endslot

        {{-- Wave 7 — owner order 2026-10-25: validating turns the
             timetable green, unmistakably. Clean means the green
             banner; any blocking error means the red one instead —
             warnings never block the green, they are listed below. --}}
        @if(count($v_errors))
            <div class="alert alert-danger"><strong>Not validated — {{ count($v_errors) }} blocking error(s) below.</strong> Fix these before this timetable can be trusted.</div>
        @else
            <div class="alert alert-success"><strong>Validated — this timetable is clash-free.</strong>@if(count($v_warnings)) No blocking errors, but {{ count($v_warnings) }} warning(s) below deserve a look.@else No blocking errors and no warnings.@endif</div>
        @endif

        <div class="marx-stats">
            <div class="marx-stat {{ count($v_errors) ? 'marx-stat-absent' : 'marx-stat-present' }}">
                <div class="marx-stat-num">{{ count($v_errors) }}</div>
                <div class="marx-stat-label">Blocking Errors</div>
                <div class="marx-stat-cap">{{ count($v_errors) ? 'Fix these before this timetable can be trusted' : 'No clashes or collisions found' }}</div>
            </div>
            <div class="marx-stat marx-stat-late">
                <div class="marx-stat-num">{{ count($v_warnings) }}</div>
                <div class="marx-stat-label">Warnings</div>
                <div class="marx-stat-cap">Requirement gaps and load risks</div>
            </div>
            <div class="marx-stat marx-stat-periods">
                <div class="marx-stat-num">{{ $cells }}</div>
                <div class="marx-stat-label">Periods Placed</div>
                <div class="marx-stat-cap">In this timetable</div>
            </div>
        </div>

        @if(count($v_errors))
            <h5 class="mt-4 mb-2 text-danger">Blocking Errors — mandatory rules</h5>
            <p class="text-muted">These break rules the system must never break: clashes, collisions and broken periods. The timetable cannot be trusted until they are fixed.</p>
            <ul class="list-group mb-3">
                @foreach($v_errors as $e)<li class="list-group-item list-group-item-danger">{{ $e }}</li>@endforeach
            </ul>
        @endif

        @if(count($v_warnings))
            <h5 class="mt-4 mb-2">Warnings — preferences</h5>
            <p class="text-muted">Best-effort goals the timetable did not fully reach: coverage shortfalls, teacher load over the weekly preference cap, rooms smaller than the class. They do not block the timetable, but they deserve a look.</p>
            <ul class="list-group mb-3">
                @foreach($v_warnings as $w)<li class="list-group-item list-group-item-warning">{{ $w }}</li>@endforeach
            </ul>
        @endif

        <h5 class="mt-4 mb-2">Requirement Coverage — {{ optional($my_class)->name ?: 'Deleted class' }}</h5>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Subject</th><th>Teacher</th><th>Required / Wk</th><th>Placed</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($coverage as $row)
                    <tr>
                        <td>{{ $row['subject']->name }}</td>
                        <td>{{ optional($row['subject']->teacher)->name ?: '—' }}</td>
                        <td>{{ $row['target'] ?? 'Not set' }}</td>
                        <td>{{ $row['placed'] }}</td>
                        <td>
                            @if($row['target'] === null)<span class="badge badge-warning">Cannot check</span>
                            @elseif($row['placed'] >= $row['target'])<span class="badge badge-success">Met</span>
                            @else<span class="badge badge-danger">Short by {{ $row['target'] - $row['placed'] }}</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <h5 class="mt-4 mb-2">Teacher Loads (whole session)</h5>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Teacher</th><th>Periods / Wk</th><th>Cap</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($loads as $row)
                    <tr>
                        <td>{{ optional($row['teacher'])->name ?: '—' }}</td>
                        <td>{{ $row['periods'] }}</td>
                        <td>{{ $row['cap'] }}</td>
                        <td>@if($row['over'])<span class="badge badge-danger">Over cap</span>@else<span class="badge badge-success">Within cap</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endcomponent
@endsection
