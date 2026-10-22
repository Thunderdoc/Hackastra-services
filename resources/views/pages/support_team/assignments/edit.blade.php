@extends('layouts.master')
@section('page_title', 'Edit Assignment')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Home</a> &nbsp;/&nbsp; <a href="{{ route('assignments.index') }}">Assignments</a> &nbsp;/&nbsp; Edit Assignment
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): edit form on the shared form_page
         pattern — PUT assignments.update, fields via the shared _form. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('assignments.update', $assignment->id),
        'form_method' => 'PUT',
        'form_enctype' => 'multipart/form-data',
        'back' => route('assignments.show', $assignment->id),
    ])
        @slot('title')
            Edit Assignment
        @endslot
        @slot('subtitle')
            {{ $assignment->title }}
        @endslot

        @include('pages.support_team.assignments._form')

        @slot('actions')
            <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Changes <i class="icon-paperplane ml-2"></i></button>
        @endslot
    @endcomponent
@endsection
