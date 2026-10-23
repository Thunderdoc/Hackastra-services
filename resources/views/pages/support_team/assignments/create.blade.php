@extends('layouts.master')
@section('page_title', 'New Assignment')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('assignments.index') }}">Assignments</a> &nbsp;/&nbsp; New Assignment
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): create form on the shared form_page
         pattern (Loop 28) — same shell as circulars/books create. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('assignments.store'),
        'form_enctype' => 'multipart/form-data',
        'back' => route('assignments.index'),
    ])
        @slot('title')
            New Assignment
        @endslot
        @slot('subtitle')
            Students in the chosen class see it instantly and submit online — nothing on paper.
        @endslot

        @include('pages.support_team.assignments._form')

        @slot('actions')
            <a href="{{ route('assignments.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Publish Assignment <i class="icon-paperplane ml-2"></i></button>
        @endslot
    @endcomponent
@endsection
