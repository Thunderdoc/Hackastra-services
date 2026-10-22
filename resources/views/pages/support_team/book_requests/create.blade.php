@extends('layouts.master')
@section('page_title', 'Issue a Book')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was a plain card whose header repeated the page title.
         Same POST to book_requests.store; the flash + error
         summaries are kept at the top of the form, as this
         module's controllers return them; same fields, bindings
         and availability-disabled options. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('book_requests.store'),
        'back' => route('book_requests.index'),
        'submit_label' => 'Issue Book',
    ])
        <div class="pat-form-section">
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
            <div class="pat-fields">
                <div class="pat-field pat-field-wide">
                    <label for="book_id">Book</label>
                    <select id="book_id" name="book_id" class="form-control" required>
                        <option value="">Select a book</option>
                        @foreach($books as $book)
                            <option value="{{ $book->id }}" {{ (string) old('book_id') === (string) $book->id ? 'selected' : '' }} {{ $book->availableCopies() < 1 ? 'disabled' : '' }}>
                                {{ $book->name }}@if($book->author) — {{ $book->author }}@endif ({{ $book->availableCopies() }} available)
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field pat-field-wide">
                    <label for="user_id">Borrower</label>
                    <select id="user_id" name="user_id" class="form-control" required>
                        <option value="">Select a borrower</option>
                        @foreach($borrowers as $user)
                            <option value="{{ $user->id }}" {{ (string) old('user_id') === (string) $user->id ? 'selected' : '' }}>
                                {{ $user->name }} ({{ ucfirst(str_replace('_', ' ', $user->user_type)) }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="start_date">Issue date</label>
                    <input id="start_date" type="date" name="start_date" class="form-control" required value="{{ old('start_date', date('Y-m-d')) }}">
                </div>
                <div class="pat-field">
                    <label for="end_date">Due date</label>
                    <input id="end_date" type="date" name="end_date" class="form-control" required value="{{ old('end_date', date('Y-m-d', strtotime('+14 days'))) }}">
                </div>
            </div>
        </div>
    @endcomponent
@endsection
