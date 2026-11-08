@extends('layouts.master')
@section('page_title', 'New Book')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Library &nbsp;/&nbsp; <a href="{{ route('books.index') }}">Library Books</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was a plain card whose header repeated the page title.
         Same POST to books.store; the page's error summary is kept
         (top-of-form, as this module's controllers return errors);
         fields live in books/_form (pattern grid). --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('books.store'),
        'back' => route('books.index'),
        'submit_label' => 'Add Book',
    ])
        <div class="pat-form-section">
            @include('pages.support_team.books._form')
        </div>
    @endcomponent
@endsection
