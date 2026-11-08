@extends('layouts.master')
@section('page_title', 'New Event')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Communication &nbsp;/&nbsp; <a href="{{ route('events.index') }}">School Events</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was a plain card whose header repeated the page title.
         Same POST to events.store; error summary kept (top-of-form,
         as this module's controllers return errors); fields live
         in events/_form (pattern grid). --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('events.store'),
        'back' => route('events.index'),
        'submit_label' => 'Create Event',
    ])
        <div class="pat-form-section">
            @include('pages.support_team.events._form')
        </div>
    @endcomponent
@endsection
