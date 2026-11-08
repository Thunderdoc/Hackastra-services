@extends('layouts.master')
@section('page_title', 'Edit Exam - '.$ex->name. ' ('.$ex->year.')')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Examinations &nbsp;/&nbsp; <a href="{{ route('exams.index') }}">Manage Exams</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
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
                    <label for="period_id">Assessment Period</label>
                    <select data-placeholder="Select Period" class="form-control select-search" name="period_id" id="period_id" @if(!empty($has_marks)) disabled @endif>
                        @foreach(['term' => 'Terms', 'unit' => 'Unit Tests', 'standalone' => 'Other'] as $pk => $plabel)
                            @php $group = $periods->where('kind', $pk); @endphp
                            @if($group->isNotEmpty())
                            <optgroup label="{{ $plabel }}">
                                @foreach($group as $per)
                                    <option {{ (int) $ex->period_id === (int) $per->id ? 'selected' : '' }} value="{{ $per->id }}">{{ $per->name }}@if($per->kind === 'unit' && $per->feedsPeriod) (feeds {{ $per->feedsPeriod->name }})@endif</option>
                                @endforeach
                            </optgroup>
                            @endif
                        @endforeach
                    </select>
                    @if(!empty($has_marks))
                        <small class="text-muted">Period is locked: this exam already has marks, and moving it would re-point those marks' totals.</small>
                    @elseif(!$ex->period_id)
                        <small class="text-muted">This exam is not linked to a period yet — pick one to link it.</small>
                    @endif
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
