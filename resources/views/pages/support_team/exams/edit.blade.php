@extends('layouts.master')
@section('page_title', 'Edit Exam - '.$ex->name. ' ('.$ex->year.')')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card with the title repeated in the card
         header — dropped, the common header carries it. Shell
         only: same plain PUT to exams.update, same fields and
         bindings. The term select's data-placeholder said
         "Select Teacher" (copy-paste relic) — now "Select Term". --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('exams.update', $ex->id),
        'form_method' => 'PUT',
        'back' => route('exams.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $ex->name }}" required type="text" class="form-control" placeholder="Name of Exam">
                </div>
                <div class="pat-field">
                    <label for="term">Term</label>
                    <select data-placeholder="Select Term" class="form-control select-search" name="term" id="term">
                        <option {{ $ex->term == 1 ? 'selected' : '' }} value="1">First Term</option>
                        <option {{ $ex->term == 2 ? 'selected' : '' }} value="2">Second Term</option>
                        <option {{ $ex->term == 3 ? 'selected' : '' }} value="3">Third Term</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label>Class scope</label>
                    <input class="form-control" disabled type="text" value="{{ empty($ex->my_class_id) ? 'All classes (global exam)' : ($ex->my_class->name ?? 'Class '.$ex->my_class_id) }}">
                    <small class="text-muted">Scope is fixed at creation — a global exam covers every class for the term, a class exam only its class.</small>
                </div>
            </div>
        </div>
    @endcomponent
@endsection
