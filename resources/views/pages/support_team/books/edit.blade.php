@extends('layouts.master')
@section('page_title', 'Edit Book')
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
            @if($errors->any())
                <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
            @include('pages.support_team.books._form')
        </div>
    @endcomponent
@endsection
