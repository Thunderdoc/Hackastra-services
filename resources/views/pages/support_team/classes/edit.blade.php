@extends('layouts.master')
@section('page_title', 'Edit Class - '.$c->name)
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card with the title repeated in the card
         header — dropped, the common header carries it. Shell
         only: same PUT to classes.update (Loop 112: plain form,
         post-save lands on the index), same fields/bindings.
         (The long-dead commented-out teacher block is removed;
         it never rendered.) --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('classes.update', $c->id),
        'form_method' => 'PUT',
        'back' => route('classes.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $c->name }}" required type="text" class="form-control" placeholder="Name of Class">
                </div>
                <div class="pat-field">
                    <label for="class_type_id">Class Type</label>
                    <input class="form-control" id="class_type_id" disabled="disabled" value="{{ $c->class_type->name }}" title="Class Type" type="text">
                </div>
            </div>
        </div>
    @endcomponent
@endsection
