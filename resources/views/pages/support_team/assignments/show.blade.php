@extends('layouts.master')
@section('page_title', 'Assignment')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Learning &nbsp;/&nbsp; <a href="{{ route('assignments.index') }}">Assignments</a> &nbsp;/&nbsp; {{ \Illuminate\Support\Str::limit($assignment->title, 40) }}
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): teacher/admin view of one assignment —
         summary card, then the submissions table with per-row grading
         (POST assignments.grade per submission, CS1 contract). The
         internal-marks flow lives on its own panel
         (assignments.internal_preview). --}}
    @php
        $due = $assignment->due_at ? \Carbon\Carbon::parse($assignment->due_at) : null;
        // Loop 82: status follows the model's actual submission rule
        // — open while not past due, or late submissions are allowed.
        $isOpen = !$due || $due->isFuture() || $assignment->allow_late;
        $submittedCount = $submissions ? $submissions->count() : 0;
        $gradedCount = $submissions ? $submissions->filter(function ($s) { return $s->marks_awarded !== null; })->count() : 0;
        $waitingCount = max(0, $submittedCount - $gradedCount);
    @endphp
    {{-- Loop 82: duplicate inline flash alerts removed — the
         app-wide banner renders each message exactly once. --}}

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
                <div>
                    <h4 class="mb-2">{{ $assignment->title }}</h4>
                    <div class="mb-2">
                        <span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($assignment->subject)->name ?? 'Subject —' }}</span>
                        <span class="lms-chip"><i class="icon-windows2 mr-1"></i>{{ optional($assignment->myClass)->name ?? 'Class —' }}{{ $assignment->section ? ' · ' . optional($assignment->section)->name : ' · Whole class' }}</span>
                        @if($isOpen)
                            <span class="lms-chip lms-chip-open">Open</span>
                        @else
                            <span class="lms-chip lms-chip-closed">Closed</span>
                        @endif
                        @if($assignment->allow_late)
                            <span class="lms-chip">Late submissions allowed (marked LATE)</span>
                        @endif
                    </div>
                    <div class="text-muted">
                        <i class="icon-user mr-1"></i>{{ optional($assignment->teacher)->name ?? 'Teacher' }}
                        &nbsp;·&nbsp;<i class="icon-alarm mr-1"></i>Due: <strong>{{ $due ? $due->format('d M Y, h:i A') : '—' }}</strong>@if($due) ({{ $due->diffForHumans() }})@endif
                        &nbsp;·&nbsp;<i class="icon-pencil7 mr-1"></i>Max marks: <strong>{{ $assignment->max_marks }}</strong>
                    </div>
                </div>
                <div class="mt-2 mt-md-0" style="white-space:nowrap">
                    @if($assignment->attachment_path)
                        <a href="{{ route('assignments.download', $assignment->id) }}" class="btn btn-light"><i class="icon-download mr-1"></i> Question File</a>
                    @endif
                    @if(Qs::userIsTeacher() && $assignment->teacher_id == auth()->id())
                        <a href="{{ route('assignments.edit', $assignment->id) }}" class="btn btn-light"><i class="icon-pencil7 mr-1"></i> Edit</a>
                        <a href="{{ route('assignments.internal_preview', $assignment->id) }}" class="btn btn-primary"><i class="icon-stats-bars2 mr-1"></i> Use for Internal Marks</a>
                    @endif
                </div>
            </div>

            @if($assignment->description)
                <hr>
                <div class="lms-description">{!! nl2br(e($assignment->description)) !!}</div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">
                Submissions
                <span class="lms-chip lms-chip-submitted ml-2">{{ $submittedCount }} received</span>
                <span class="lms-chip lms-chip-graded">{{ $gradedCount }} graded</span>
                @if($waitingCount > 0)
                    <span class="lms-chip lms-chip-pending">{{ $waitingCount }} waiting</span>
                @endif
            </h6>
            <a href="{{ route('assignments.index') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left13 mr-1"></i> All Assignments</a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table lms-sub-table">
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Submitted</th>
                        <th>File / Answer</th>
                        <th style="min-width:290px">Marks &amp; Feedback</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($submissions as $submission)
                        @php
                            $isGraded = $submission->marks_awarded !== null;
                            $studentUser = $submission->student ?? null;
                            $admNo = optional(optional($studentUser)->student_record)->adm_no ?? ($submission->adm_no ?? null);
                        @endphp
                        <tr class="{{ $isGraded ? 'lms-row-graded' : '' }}">
                            <td>
                                <strong>{{ optional($studentUser)->name ?? 'Student' }}</strong>
                                @if($admNo)<div class="text-muted">Adm No: {{ $admNo }}</div>@endif
                                @if($isGraded)
                                    <span class="lms-chip lms-chip-graded mt-1"><i class="icon-checkmark3 mr-1"></i>Graded</span>
                                @endif
                            </td>
                            <td>
                                @if($submission->submitted_at)
                                    {{ \Carbon\Carbon::parse($submission->submitted_at)->format('d M Y, h:i A') }}
                                    <div class="text-muted">{{ \Carbon\Carbon::parse($submission->submitted_at)->diffForHumans() }}</div>
                                @else
                                    —
                                @endif
                                @if($submission->is_late)
                                    <span class="lms-chip lms-chip-late mt-1"><i class="icon-alarm mr-1"></i>LATE</span>
                                @endif
                            </td>
                            <td>
                                @if($submission->attachment_path)
                                    <a href="{{ route('assignments.download', [$assignment->id, 'submission' => $submission->id]) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download file</a>
                                @endif
                                @if($submission->text_answer)
                                    <div class="lms-answer mt-1">{{ \Illuminate\Support\Str::limit($submission->text_answer, 140) }}</div>
                                @endif
                                @if(!$submission->attachment_path && !$submission->text_answer)
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if(Qs::userIsTeacher() && $assignment->teacher_id == auth()->id())
                                    <form method="post" action="{{ route('assignments.grade', $submission->id) }}" class="lms-grade-form">
                                        @csrf
                                        <div class="lms-grade-fields">
                                            <span class="lms-grade-marks">
                                                <input type="number" name="marks_awarded" class="form-control" min="0" max="{{ $assignment->max_marks }}" step="0.5" value="{{ $submission->marks_awarded }}" placeholder="Marks" aria-label="Marks awarded">
                                                <span class="text-muted">/ {{ $assignment->max_marks }}</span>
                                            </span>
                                            <input type="text" name="feedback" class="form-control lms-grade-feedback" value="{{ $submission->feedback }}" placeholder="Feedback for the student" aria-label="Feedback">
                                            <button type="submit" class="btn {{ $isGraded ? 'btn-light' : 'btn-primary' }} btn-sm">{{ $isGraded ? 'Update' : 'Save' }}</button>
                                        </div>
                                    </form>
                                @elseif($isGraded)
                                    {{-- Loop 82: read-only roles (parent, admin,
                                         Super Admin) see the result, never the
                                         grading form — grade() refuses them
                                         server-side anyway. --}}
                                    <strong>{{ $submission->marks_awarded }} / {{ $assignment->max_marks }}</strong>
                                    @if($submission->feedback)
                                        <div class="text-muted">{{ $submission->feedback }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">Not graded yet</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                @include('partials.patterns.empty_state', [
                                    'icon' => 'icon-file-text2',
                                    'message' => 'No submissions yet. Once students submit from their portal, their work appears here for grading.',
                                ])
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
