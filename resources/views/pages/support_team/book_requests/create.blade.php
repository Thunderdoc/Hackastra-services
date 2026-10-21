@extends('layouts.master')
@section('page_title', 'Issue a Book')
@section('content')
    <div class="card">
        <div class="card-header"><h6 class="card-title">Issue a Book</h6></div>
        <div class="card-body">
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
            <form method="POST" action="{{ route('book_requests.store') }}">
                @csrf
                <div class="form-group">
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
                <div class="form-group">
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
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="start_date">Issue date</label>
                        <input id="start_date" type="date" name="start_date" class="form-control" required value="{{ old('start_date', date('Y-m-d')) }}">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="end_date">Due date</label>
                        <input id="end_date" type="date" name="end_date" class="form-control" required value="{{ old('end_date', date('Y-m-d', strtotime('+14 days'))) }}">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Issue Book</button>
                <a href="{{ route('book_requests.index') }}" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
@endsection
