@extends('layouts.master')
@section('page_title', 'Edit Section of '.$s->my_class->name)
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card from the owner's screenshot, with the
         page title repeated as the card heading — the heading is
         dropped, the common header carries the title. Shell only:
         same PUT to sections.update (Loop 112: plain form, post-save lands on the index), same fields,
         bindings and hashed teacher values. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('sections.update', $s->id),
        'form_method' => 'PUT',
        'back' => route('sections.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $s->name }}" required type="text" class="form-control" placeholder="Name of Class">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class</label>
                    <input class="form-control" id="my_class_id" disabled="disabled" type="text" value="{{ $s->my_class->name }}">
                </div>
                <div class="pat-field">
                    <label for="teacher_id">Teacher</label>
                    <select data-placeholder="Select Teacher" class="form-control select-search" name="teacher_id" id="teacher_id">
                        <option value=""></option>
                        @foreach($teachers as $t)
                            <option {{ $s->teacher_id == $t->id ? 'selected' : '' }} value="{{ Qs::hash($t->id) }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    @endcomponent
@endsection
