@extends('layouts.master')
@section('page_title', 'Edit Dorm - '.$dorm->name)
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card with the title repeated in the card
         header — dropped, the common header carries it. Shell
         only: same PUT to dorms.update (Loop 112: plain form,
         post-save lands on the index), same fields and bindings. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('dorms.update', $dorm->id),
        'form_method' => 'PUT',
        'back' => route('dorms.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $dorm->name }}" required type="text" class="form-control" placeholder="Name of Dormitory">
                </div>
                <div class="pat-field">
                    <label for="description">Description</label>
                    <input id="description" name="description" value="{{ $dorm->description }}" type="text" class="form-control" placeholder="Description of Dormitory">
                </div>
            </div>
        </div>
    @endcomponent
@endsection
