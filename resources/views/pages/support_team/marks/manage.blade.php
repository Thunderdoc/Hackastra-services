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
