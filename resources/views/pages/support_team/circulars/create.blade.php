@extends('layouts.master')
@section('page_title', 'New Circular')
@section('content')
    {{-- Workstream BA: rebuilt on the shared form_page pattern (Loop 28) —
         full-width sectioned form with a sticky action bar. Same contract
         as before: POST circulars.store, fields via the shared _form. --}}
    {{-- Loop 75: two-column composer — form left, live preview
         right (owner steering 2026-10-22). The action bar carries
         Save as Draft / Cancel / Publish Circular; the draft and
         publish buttons only set the existing Status field
         (is_published) before the same submit runs. --}}
    <div class="l75-circ-layout">
        <div class="l75-circ-main">
            @component('partials.patterns.form_page', [
                'form_action' => route('circulars.store'),
                'form_enctype' => 'multipart/form-data',
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
                    <button type="submit" class="btn btn-light mr-auto" onclick="var s=document.getElementById('is_published'); if(s){s.value='0';}">Save as Draft</button>
                    <a href="{{ route('circulars.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary" onclick="var s=document.getElementById('is_published'); if(s){s.value='1';}">Publish Circular <i class="icon-paperplane ml-2"></i></button>
                @endslot
            @endcomponent
        </div>
        @include('pages.support_team.circulars._preview')
    </div>
@endsection
