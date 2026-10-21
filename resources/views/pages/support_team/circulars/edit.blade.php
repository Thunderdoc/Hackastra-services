@extends('layouts.master')
@section('page_title', 'Edit Circular')
@section('content')
    {{-- Workstream BA: rebuilt on the shared form_page pattern (Loop 28) —
         full-width sectioned form with a sticky action bar. Same contract
         as before: PUT circulars.update, fields via the shared _form. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('circulars.update', $circular->id),
        'form_method' => 'PUT',
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
            <a href="{{ route('circulars.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Circular <i class="icon-paperplane ml-2"></i></button>
        @endslot
    @endcomponent
@endsection
