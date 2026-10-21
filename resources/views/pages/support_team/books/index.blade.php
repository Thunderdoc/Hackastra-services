@extends('layouts.master')
@section('page_title', 'Library Books')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Library Books</h6>
            <div>
                <a href="{{ route('book_requests.index') }}" class="btn btn-light btn-sm"><i class="icon-list mr-1"></i> Loans &amp; Requests</a>
                <a href="{{ route('books.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New Book</a>
            </div>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif

            <form method="get" action="{{ route('books.index') }}" class="form-inline mb-3">
                <input type="text" name="q" value="{{ $q }}" class="form-control mr-2" placeholder="Search title, author, type or location" style="min-width:260px">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                @if($q)
                    <a href="{{ route('books.index') }}" class="btn btn-light btn-sm ml-2">Clear</a>
                @endif
            </form>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Book</th><th>Type</th><th>Class</th><th>Location</th><th>Copies</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse($books as $book)
                        <tr>
                            <td>
                                <strong>{{ $book->name }}</strong>
                                @if($book->author)<div class="text-muted">by {{ $book->author }}</div>@endif
                                @if($book->url)<div><a href="{{ $book->url }}" target="_blank" rel="noopener">Online copy</a></div>@endif
                            </td>
                            <td>@if($book->book_type)<span class="badge badge-light">{{ $book->book_type }}</span>@endif</td>
                            <td>{{ $book->myClass->name ?? '—' }}</td>
                            <td>{{ $book->location ?? '—' }}</td>
                            <td>
                                {{ (int) $book->issued_copies }} issued / {{ (int) $book->total_copies }} total
                                <div class="text-muted">{{ $book->availableCopies() }} available</div>
                            </td>
                            <td>
                                <a href="{{ route('books.edit', $book->id) }}" class="btn btn-light btn-sm">Edit</a>
                                @if(Qs::userIsTeamSA())
                                    <form method="post" action="{{ route('books.destroy', $book->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this book from the catalogue?">Delete</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No books found. Add the first book to the library catalogue.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $books->appends(request()->query())->links() }}
        </div>
    </div>
@endsection
