@extends('layouts.master')
@section('page_title', 'Edit Subject - '.$s->name. ' ('.$s->my_class->name.')')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card with the title repeated in the card
         header — dropped, the common header carries it. Shell
         only: same PUT to subjects.update via .ajax-update, same
         fields, bindings and hashed teacher values. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('subjects.update', $s->id),
        'form_method' => 'PUT',
        'form_class' => 'ajax-update',
        'back' => route('subjects.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $s->name }}" required type="text" class="form-control" placeholder="Name of Subject">
                </div>
                <div class="pat-field">
                    <label for="slug">Short Name</label>
                    <input id="slug" name="slug" value="{{ $s->slug }}" type="text" class="form-control" placeholder="Short Name">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class <span class="text-danger">*</span></label>
                    <select required data-placeholder="Select Class" class="form-control select" name="my_class_id" id="my_class_id">
                        @foreach($my_classes as $c)
                            <option {{ $s->my_class_id == $c->id ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
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
