@extends('layouts.master')
@section('page_title', 'New Circular')
@section('content')
    {{-- Workstream BA: rebuilt on the shared form_page pattern (Loop 28) —
         full-width sectioned form with a sticky action bar. Same contract
         as before: POST circulars.store, fields via the shared _form. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('circulars.store'),
        'back' => route('circulars.index'),
    ])
        @slot('title')
            New Circular
        @endslot
        @slot('subtitle')
            It will appear in the Circulars feed of the chosen audience.
        @endslot

        @include('pages.support_team.circulars._form')

        @slot('actions')
            <a href="{{ route('circulars.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Circular <i class="icon-paperplane ml-2"></i></button>
        @endslot
    @endcomponent
@endsection
