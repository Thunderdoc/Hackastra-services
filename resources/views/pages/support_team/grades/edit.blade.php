@extends('layouts.master')
@section('page_title', 'Edit Grade')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card with the title repeated in the card
         header — dropped, the common header carries it. Shell
         only: same plain PUT to grades.update (this form never
         used the AJAX handler), same fields and bindings. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('grades.update', $gr->id),
        'form_method' => 'PUT',
        'back' => route('grades.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $gr->name }}" required type="text" class="form-control" placeholder="Eg. C4">
                </div>
                <div class="pat-field">
                    <label for="class_type_id">Grade Type</label>
                    <select class="form-control select" name="class_type_id" id="class_type_id">
                        <option value="">Not Applicable</option>
                        @foreach($class_types as $ct)
                            <option {{ $gr->class_type_id == $ct->id ? 'selected' : '' }} value="{{ $ct->id }}">{{ $ct->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="mark_from">Mark From <span class="text-danger">*</span></label>
                    <input id="mark_from" name="mark_from" min="0" max="100" value="{{ $gr->mark_from }}" required type="number" class="form-control" placeholder="0">
                </div>
                <div class="pat-field">
                    <label for="mark_to">Mark To <span class="text-danger">*</span></label>
                    <input id="mark_to" name="mark_to" min="0" max="100" value="{{ $gr->mark_to }}" required type="number" class="form-control" placeholder="0">
                </div>
                <div class="pat-field">
                    <label for="remark">Remark</label>
                    <select class="form-control select" name="remark" id="remark">
                        <option value="">Select Remark...</option>
                        @foreach(Mk::getRemarks() as $rem)
                            <option {{ $gr->remark == $rem ? 'selected' : '' }} value="{{ $rem }}">{{ $rem }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    @endcomponent
@endsection
