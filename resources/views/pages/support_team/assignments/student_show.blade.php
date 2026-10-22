@extends('layouts.master')
@section("page_title", "Assignment")
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Home</a> &nbsp;/&nbsp; <a href="{{ route('assignments.index') }}">My Assignments</a> &nbsp;/&nbsp; {{ \Illuminate\Support\Str::limit($assignment->title, 40) }}
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): student view of one assignment — full
         instructions, the teacher's file, a due countdown, the submit
         form (POST assignments.submit) while the window is open, and
         — once graded — the marks + teacher feedback up front.
         $my_submission is the student's own submission (nullable). --}}
    @php
        $due = $assignment->due_at ? \Carbon\Carbon::parse($assignment->due_at) : null;
        $isPast = $due ? $due->isPast() : false;
        $sub = $my_submission ?? null;
        $isGraded = $sub && $sub->marks_awarded !== null;
        $canSubmit = !$sub && (!$isPast || $assignment->allow_late);
        if (!$due) {
            $dueLabel = 'No deadline';
            $dueClass = '';
        } elseif ($isPast) {
            $dueLabel = 'Deadline passed — was due ' . $due->diffForHumans();
            $dueClass = 'lms-due-overdue';
        } elseif ($due->isToday() || $due->diffInDays(now()) < 2) {
            $dueLabel = 'Due ' . $due->diffForHumans();
            $dueClass = 'lms-due-soon';
        } else {
            $dueLabel = 'Due ' . $due->diffForHumans();
            $dueClass = 'lms-due-ok';
        }
    @endphp

    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif
    @if(session('flash_danger'))
        <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <div class="mb-2">
                <span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($assignment->subject)->name ?? 'Subject —' }}</span>
                <span class="lms-chip"><i class="icon-windows2 mr-1"></i>{{ optional($assignment->myClass)->name ?? 'Class —' }}{{ $assignment->section ? ' · ' . optional($assignment->section)->name : '' }}</span>
                <span class="lms-chip"><i class="icon-pencil7 mr-1"></i>Max marks: {{ $assignment->max_marks }}</span>
            </div>
            <h4 class="mb-2">{{ $assignment->title }}</h4>
            <div class="text-muted mb-2">
                <i class="icon-user mr-1"></i>{{ optional($assignment->teacher)->name ?? 'Teacher' }}
                &nbsp;·&nbsp;<span class="lms-due {{ $dueClass }}"><i class="icon-alarm mr-1"></i>{{ $dueLabel }}</span>
                @if($due) &nbsp;·&nbsp;{{ $due->format('l, d M Y, h:i A') }}@endif
            </div>

            @if($assignment->description)
                <hr>
                <div class="lms-description">{!! nl2br(e($assignment->description)) !!}</div>
            @endif

            @if($assignment->attachment_path)
                <hr>
                <a href="{{ route('assignments.download', $assignment->id) }}" class="btn btn-light"><i class="icon-download mr-1"></i> Download the assignment file</a>
            @endif
        </div>
    </div>

    @if($isGraded)
        <div class="card lms-result-card">
            <div class="card-body">
                <div class="lms-result">
                    <div class="lms-result-marks">
                        <span class="lms-result-score">{{ $sub->marks_awarded }}<small> / {{ $assignment->max_marks }}</small></span>
                        <span class="lms-chip lms-chip-graded"><i class="icon-checkmark3 mr-1"></i>Graded</span>
                    </div>
                    <div class="lms-result-feedback">
                        <div class="text-muted">Teacher feedback</div>
                        @if($sub->feedback)
                            <p class="mb-0"><strong>{{ $sub->feedback }}</strong></p>
                        @else
                            <p class="mb-0 text-muted">No written feedback — your marks say it all.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($sub)
        <div class="card">
            <div class="card-header">
                <h6 class="card-title">My Submission</h6>
            </div>
            <div class="card-body">
                <div class="text-muted">
                    <i class="icon-checkmark3 mr-1"></i>Submitted
                    @if($sub->submitted_at) on {{ \Carbon\Carbon::parse($sub->submitted_at)->format('d M Y, h:i A') }} ({{ \Carbon\Carbon::parse($sub->submitted_at)->diffForHumans() }})@endif
                    @if($sub->is_late)
                        <span class="lms-chip lms-chip-late ml-1"><i class="icon-alarm mr-1"></i>LATE</span>
                    @endif
                </div>
                @if($sub->attachment_path)
                    <div class="mt-2">
                        <a href="{{ route('assignments.download', [$assignment->id, 'submission' => $sub->id]) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> My submitted file</a>
                    </div>
                @endif
                @if($sub->text_answer)
                    <div class="lms-answer mt-2">{{ $sub->text_answer }}</div>
                @endif
                @if(!$isGraded)
                    <div class="text-muted mt-2">Your teacher has not graded this yet — your marks and feedback will appear here.</div>
                @endif
            </div>
        </div>
    @elseif($canSubmit)
        @component('partials.patterns.form_page', [
            'form_action' => route('assignments.submit', $assignment->id),
            'form_enctype' => 'multipart/form-data',
            'back' => route('assignments.index'),
        ])
            @slot('title')
                Submit Assignment
            @endslot
            @slot('subtitle')
                Upload your work — a photo of your notebook page, a PDF, or a document. You can also type your answer.
            @endslot

            <div class="pat-form-section">
                <div class="pat-form-section-title">Your Work</div>
                <div class="pat-fields">
                    <div class="pat-field pat-field-wide">
                        <label for="attachment">File</label>
                        <input name="attachment" id="attachment" type="file" class="form-control @error('attachment') is-invalid @enderror">
                        @include('partials.errors.field', ['field' => 'attachment'])
                        @if($isPast)
                            <span class="form-text text-danger">The deadline has passed — this submission will be marked LATE.</span>
                        @endif
                    </div>
                    <div class="pat-field pat-field-wide">
                        <label for="text_answer">Typed Answer (optional)</label>
                        <textarea name="text_answer" id="text_answer" rows="5" class="form-control @error('text_answer') is-invalid @enderror" placeholder="Type your answer here if you are not uploading a file">{{ old('text_answer') }}</textarea>
                        @include('partials.errors.field', ['field' => 'text_answer'])
                    </div>
                </div>
            </div>

            @slot('actions')
                <a href="{{ route('assignments.index') }}" class="btn btn-light">Back to My Assignments</a>
                <button type="submit" class="btn btn-primary">Submit Assignment <i class="icon-paperplane ml-2"></i></button>
            @endslot
        @endcomponent
    @else
        <div class="card">
            <div class="card-body">
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-alarm',
                    'message' => 'The submission deadline for this assignment has passed and late submissions are not allowed.',
                    'action_url' => route('assignments.index'),
                    'action_label' => 'Back to My Assignments',
                ])
            </div>
        </div>
    @endif
@endsection
