@extends('layouts.master')
@section('page_title', 'Add Study Material')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('materials.index') }}">Study Materials</a> &nbsp;/&nbsp; Add Study Material
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): material create form on the shared
         form_page pattern (Loop 28) — POST materials.store. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('materials.store'),
        'form_enctype' => 'multipart/form-data',
        'back' => route('materials.index'),
    ])
        @slot('title')
            Add Study Material
        @endslot
        @slot('subtitle')
            Notes, slides, videos and links — students open them from their portal, no photocopies.
        @endslot

        @include('pages.support_team.materials._form')

        @slot('actions')
            <a href="{{ route('materials.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Share Material <i class="icon-paperplane ml-2"></i></button>
        @endslot
    @endcomponent
@endsection
