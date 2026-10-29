@extends('layouts.master')
@section('page_title', 'Manage Marks')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; Exams &nbsp;/&nbsp; Manage Marks
@endsection
@section('page_subtitle', 'Select examination details and enter student marks.')
{{-- Loop 115 note: the reference header's "View Exam Schedule"
     button is deliberately NOT added here — its route
     (exams.index) is teamSA-only by the settled exam-access rule,
     so for teachers it dead-ends at a login redirect. --}}
@section('content')

    {{-- Loop 115 (reference replica): the reference's four-step
         progress strip. Steps 1-3 are complete (the selection that
         brought the user here), step 4 is the current step. Static
         presentation — the re-selection form below is untouched. --}}
    <ol class="l115-steps" aria-label="Marks entry progress">
        <li class="l115-step is-done">
            <span class="l115-step-dot">1</span>
            <span class="l115-step-text"><strong>Select Exam</strong><small>Choose examination</small></span>
        </li>
        <li class="l115-step is-done">
            <span class="l115-step-dot">2</span>
            <span class="l115-step-text"><strong>Select Class &amp; Section</strong><small>Choose class and section</small></span>
        </li>
        <li class="l115-step is-done">
            <span class="l115-step-dot">3</span>
            <span class="l115-step-text"><strong>Select Subject</strong><small>Choose subject</small></span>
        </li>
        <li class="l115-step is-current" aria-current="step">
            <span class="l115-step-dot">4</span>
            <span class="l115-step-text"><strong>Manage Marks</strong><small>Enter and update marks</small></span>
        </li>
    </ol>

    {{-- Workstream BW: the picker now sits in a pat-form-card like
         marks/index (its legacy "Fill The Form To Manage Marks"
         header card is dropped). Loop 115: the card carries the
         reference's "Examination Details" title. The marks grid
         card below is unchanged. --}}
    <div class="card pat-form-card">
        <div class="pat-form-head">
            <h5 class="pat-form-title"><i class="icon-filter3 mr-2"></i>Examination Details</h5>
        </div>
        @include('pages.support_team.marks.selector')
    </div>

    {{-- Batch 114 (Loop 86): context chips under the selector — the
         subject with its REAL component maxima read from the marking
         scheme, the class/section with its student count, and the
         exam with the session. Nothing here is hardcoded: a legacy
         (unconfigured) subject reads CA 20 + CA 20 + Exam 60 from the
         same scheme helper the entry grid uses. --}}
    <div class="l86-chips">
        <div class="l86-chip">
            <span class="l86-chip-ic l86-chip-ic-subject"><i class="icon-books"></i></span>
            <div class="l86-chip-body">
                <strong>Subject: {{ $m->subject->name }}</strong>
                <span>Maximum Marks: {{ $scheme['max_total'] }} ({{ collect($scheme['components'])->map(function($c){ return $c['name'].': '.$c['max_marks']; })->implode(', ') }})</span>
            </div>
        </div>
        <div class="l86-chip">
            <span class="l86-chip-ic l86-chip-ic-class"><i class="icon-users"></i></span>
            <div class="l86-chip-body">
                <strong>Class: {{ $m->my_class->name }} ({{ $m->section->name }})</strong>
                <span>Total Students: {{ $marks->count() }}</span>
            </div>
        </div>
        <div class="l86-chip">
            <span class="l86-chip-ic l86-chip-ic-exam"><i class="icon-calendar"></i></span>
            <div class="l86-chip-body">
                <strong>Exam: {{ $m->exam->name }}</strong>
                <span>Academic Session: {{ $m->year }}</span>
            </div>
        </div>
    </div>
    <div class="card l76-grid-card">

    @if($m->exam->results_published)
        {{-- Batch 114 (Part A): publishing is a visibility gate, not an
             edit lock — the grid stays editable by design. The page
             must SAY the results are live instead of letting a teacher
             silently change marks students can already see. --}}
        <div class="l86-pub-banner">
            <i class="icon-eye2"></i>
            <span><strong>Results published.</strong> Students and parents can see these marks now. Changes you save update the published results immediately, and every change is recorded in the audit log.</span>
        </div>
    @endif

    {{-- Marks lifecycle (owner order 2026-10-29): entry opens on the
         subject's exam date; submitting locks the unit for the
         teacher; only the school Admin can reopen it. --}}
    @if(!empty($lifecycle['submitted']))
        <div class="l86-pub-banner">
            <i class="icon-lock2"></i>
            <span><strong>Marks submitted.</strong> {{ optional($lifecycle['submitted']->submitter)->name }} submitted these marks on {{ $lifecycle['submitted']->created_at->format('d M Y, g:i A') }}. They are locked for editing.@if(\App\Helpers\Qs::userIsAdmin()) Use Reopen only for a genuine correction — reopening is recorded in the audit log.@endif</span>
            @if(\App\Helpers\Qs::userIsAdmin())
                <form method="POST" action="{{ route('marks.reopen', [$exam_id, $my_class_id, $section_id, $subject_id]) }}" onsubmit="return confirm('Reopen these marks for correction? The reopening is recorded in the audit log.');" style="margin-left:auto;">
                    @csrf
                    <button type="submit" class="btn btn-warning btn-sm">Reopen for correction</button>
                </form>
            @endif
        </div>
    @elseif(!empty($lifecycle['opens_on']))
        <div class="l86-pub-banner">
            <i class="icon-lock2"></i>
            <span><strong>Marks entry opens on {{ $lifecycle['opens_on'] }} — the day after the exam ({{ $lifecycle['exam_date'] }}).</strong> The class list is visible now; marks can be entered and submitted from that date, once the paper is corrected.</span>
        </div>
    @else
        <div class="l86-pub-banner">
            <i class="icon-pencil7"></i>
            <span><strong>Draft — not yet submitted.</strong> Save as often as needed. When the marks are final, submit them: after submission they are locked and only the school Admin can reopen them.</span>
            <form method="POST" action="{{ route('marks.submit', [$exam_id, $my_class_id, $section_id, $subject_id]) }}" onsubmit="return confirm('Submit these marks? After submission you will not be able to edit them.');" style="margin-left:auto;">
                @csrf
                <button type="submit" class="btn btn-success btn-sm">Submit Marks</button>
            </form>
        </div>
    @endif

    {{-- Internal-marks importer (owner order 2026-10-29): fill the
         Internal component from the teacher's assignments or
         quizzes instead of typing it — average of all, best N, or
         latest N, scaled onto the component's maximum. Students
         with no graded work keep a blank cell. --}}
    @if(!empty($internal_component) && empty($lifecycle['locked']))
        <div class="card" style="margin-top:14px;">
            <div class="card-header">
                <h6 class="card-title"><i class="icon-import mr-2"></i>Fill {{ $internal_component['name'] }} marks (max {{ $internal_component['max_marks'] }}) from assignments or quizzes</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('marks.import_internal', [$exam_id, $my_class_id, $section_id, $subject_id]) }}" onsubmit="return confirm('Fill the {{ $internal_component['name'] }} marks from the chosen source? Existing {{ $internal_component['name'] }} scores in this grid will be replaced for students who have graded work.');">
                    @csrf
                    <div class="row">
                        <div class="col-md-3">
                            <label>Source</label>
                            <select name="source_kind" id="rbImportKind" class="form-control">
                                <option value="assignments">Assignments ({{ $import_assignment_count }} for this subject)</option>
                                <option value="quizzes" @if($import_quizzes->isEmpty()) disabled @endif>Quizzes</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Take</label>
                            <select name="mode" class="form-control">
                                <option value="all">Average of all</option>
                                <option value="best">Best N</option>
                                <option value="latest">Latest N</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>N (for Best/Latest)</label>
                            <input type="number" name="avg_n" class="form-control" min="1" max="50" value="3">
                        </div>
                        <div class="col-md-4" style="display:flex; align-items:flex-end;">
                            <button type="submit" class="btn btn-primary"><i class="icon-import mr-1"></i> Fill Internal Marks</button>
                        </div>
                    </div>
                    @if($import_quizzes->isNotEmpty())
                        <div id="rbImportQuizzes" style="display:none; margin-top:12px;">
                            <label>Quizzes to include</label>
                            @foreach($import_quizzes as $qz)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="quiz_ids[]" value="{{ $qz->id }}" id="qz{{ $qz->id }}" checked>
                                    <label class="form-check-label" for="qz{{ $qz->id }}">{{ $qz->title }}</label>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <p class="text-muted" style="margin-top:10px;">Each score becomes a percentage of its own maximum first; the chosen average is then scaled onto {{ $internal_component['max_marks'] }}. Only students with graded work are filled — the rest stay blank.</p>
                </form>
            </div>
        </div>
        <script>
            (function(){
                var kind = document.getElementById('rbImportKind');
                var box = document.getElementById('rbImportQuizzes');
                if(!kind || !box) return;
                kind.addEventListener('change', function(){ box.style.display = kind.value === 'quizzes' ? 'block' : 'none'; });
            })();
        </script>
    @endif


        <div class="card-header l86-entry-head">
            <div class="l86-entry-title">
                <h6 class="card-title"><i class="icon-table2 mr-2"></i>Student Marks Entry</h6>
            </div>
            <div class="l86-search">
                <input type="text" id="l86-search" class="form-control" placeholder="Search by name or admission no..." autocomplete="off">
            </div>
        </div>

        <div class="card-body">
            @include('pages.support_team.marks.edit')
            {{--@include('pages.support_team.marks.random')--}}
        </div>
    </div>

    {{--Marks Manage End--}}

@endsection
