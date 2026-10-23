@extends('layouts.master')
@section('page_title', 'Edit Circular')
{{-- Loop 114: same header Back action as the create page. --}}
@section('page_actions')
    <a href="{{ route('circulars.index') }}" class="btn btn-light"><i class="icon-arrow-left13 mr-1"></i> Back to Circulars</a>
@endsection
@section('content')
    {{-- Workstream BA: rebuilt on the shared form_page pattern (Loop 28) —
         full-width sectioned form with a sticky action bar. Same contract
         as before: PUT circulars.update, fields via the shared _form. --}}
    {{-- Loop 75: same two-column composer as create (form left,
         live preview right); the action bar mirrors create, with
         Save as Draft setting the existing Status field. --}}
    <div class="l75-circ-layout">
        <div class="l75-circ-main">
            @component('partials.patterns.form_page', [
                'form_action' => route('circulars.update', $circular->id),
                'form_method' => 'PUT',
                'form_enctype' => 'multipart/form-data',
                'back' => route('circulars.index'),
            ])
                @slot('title')
                    Edit Circular
                @endslot
                @slot('subtitle')
                    It will appear in the Circulars feed of the chosen audience.
                @endslot

                @include('pages.support_team.circulars._form')

                @slot('actions')
                    <button type="submit" class="btn btn-light mr-auto" onclick="var s=document.getElementById('is_published'); if(s){s.value='0';}">Save as Draft</button>
                    <a href="{{ route('circulars.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Circular <i class="icon-paperplane ml-2"></i></button>
                @endslot
            @endcomponent
        </div>
        @include('pages.support_team.circulars._preview')
    </div>
@endsection
