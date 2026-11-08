@extends('layouts.master')
@section('page_title', 'Edit Book')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Library &nbsp;/&nbsp; <a href="{{ route('books.index') }}">Library Books</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was a plain card whose header repeated the page title.
         Same PUT to books.update; error summary kept (top-of-form,
         as this module's controllers return errors); fields live
         in books/_form (pattern grid). --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('books.update', $book->id),
        'form_method' => 'PUT',
        'back' => route('books.index'),
        'submit_label' => 'Update Book',
    ])
        <div class="pat-form-section">
            @include('pages.support_team.books._form')
        </div>
    @endcomponent
@endsection
