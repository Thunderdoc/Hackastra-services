@extends('layouts.master')
@section('page_title', 'Library Books')
@section('content')

    {{-- Workstream BI: Library Books rebuilt on the list_page pattern in
         the owner's reference layout — real KPI cards, server-side
         search + Type/Class/Availability filters and sort (all carried
         across pagination), copies/status derived from the catalogue
         counters the loan flow keeps in step with book_requests.
         No ISBN search: the books table has no isbn column. No cover
         images exist either (books.url is an online-copy link, kept
         under the author), so every row uses the same placeholder tile. --}}

    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif
    @if(session('flash_danger'))
        <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
    @endif

    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-books mr-2"></i> Library Books
        @endslot
        @slot('subtitle')
            Manage your library collection, track availability, and handle book loans and requests.
        @endslot
        @slot('action')
            <a href="{{ route('book_requests.index') }}" class="btn btn-light"><i class="icon-list mr-1"></i> Loans &amp; Requests</a>
            <a href="{{ route('books.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Book</a>
        @endslot

        @slot('stats')
            <div class="row dashboard-stats">
                @foreach([
                    ['label' => 'Total Titles', 'value' => $stats['titles'], 'sub' => 'All books in collection', 'icon' => 'icon-books', 'tone' => 'purple'],
                    ['label' => 'Total Copies', 'value' => $stats['copies'], 'sub' => 'Across all titles', 'icon' => 'icon-stack', 'tone' => 'green'],
                    ['label' => 'Issued Copies', 'value' => $stats['issued'], 'sub' => 'Currently on loan', 'icon' => 'icon-users', 'tone' => 'orange'],
                    ['label' => 'Available Copies', 'value' => $stats['available'], 'sub' => 'Ready to issue', 'icon' => 'icon-checkmark-circle2', 'tone' => 'blue'],
                ] as $stat)
                    <div class="col-sm-6 col-xl-3">
                        <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                            <span class="dashboard-stat-top">
                                <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                            </span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                                <strong>{{ number_format($stat['value']) }}</strong>
                                <small>{{ $stat['sub'] }}</small>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endslot

        @slot('filters')
            <form method="get" action="{{ route('books.index') }}" class="pat-filter-form">
                <div class="pat-filter-field" style="flex:2 1 240px">
                    <label for="books-q">Search</label>
                    <input type="text" id="books-q" name="q" value="{{ $q }}" class="form-control" placeholder="Search by title, author or location&hellip;">
                </div>
                <div class="pat-filter-field">
                    <label for="books-type">Type</label>
                    <select id="books-type" name="type" class="form-control">
                        <option value="">All Types</option>
                        @foreach($types as $value => $label)
                            <option value="{{ $value }}" {{ $type === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="books-class">Class</label>
                    <select id="books-class" name="class_id" class="form-control">
                        <option value="">All Classes</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ (string) $class_id === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="books-availability">Availability</label>
                    <select id="books-availability" name="availability" class="form-control">
                        <option value="">Any</option>
                        <option value="available" {{ $availability === 'available' ? 'selected' : '' }}>Available</option>
                        <option value="partial" {{ $availability === 'partial' ? 'selected' : '' }}>Partially Issued</option>
                        <option value="issued" {{ $availability === 'issued' ? 'selected' : '' }}>All Issued</option>
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="books-sort">Sort by</label>
                    <select id="books-sort" name="sort" class="form-control">
                        <option value="title_asc" {{ $sort === 'title_asc' ? 'selected' : '' }}>Title (A&ndash;Z)</option>
                        <option value="title_desc" {{ $sort === 'title_desc' ? 'selected' : '' }}>Title (Z&ndash;A)</option>
                        <option value="newest" {{ $sort === 'newest' ? 'selected' : '' }}>Newest first</option>
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-search4 mr-1"></i> Search</button>
                    <a href="{{ route('books.index') }}" class="btn btn-light ml-1"><i class="icon-reset mr-1"></i> Reset</a>
                </div>
            </form>
        @endslot

        <table class="table">
            <caption class="qs-table-caption">Showing {{ $books->firstItem() ?? 0 }} to {{ $books->lastItem() ?? 0 }} of {{ $books->total() }} books</caption>
            <thead>
            <tr>
                <th>Book</th>
                <th>Type</th>
                <th>Class</th>
                <th>Location</th>
                <th>Copies</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($books as $book)
                @php
                    $available = $book->availableCopies();
                    if ((int) $book->issued_copies > 0 && $available > 0) {
                        $statusLabel = 'Partially Issued';
                        $statusTone = 'status-warning';
                    } elseif ($available > 0) {
                        $statusLabel = 'Available';
                        $statusTone = 'status-success';
                    } else {
                        $statusLabel = 'All Issued';
                        $statusTone = 'status-danger';
                    }
                @endphp
                <tr>
                    <td>
                        <div class="qs-book-cell">
                            <span class="qs-book-thumb"><i class="icon-book"></i></span>
                            <span>
                                <strong>{{ $book->name }}</strong>
                                @if($book->author)<div class="text-muted">by {{ $book->author }}</div>@endif
                                @if($book->url)<div><a href="{{ $book->url }}" target="_blank" rel="noopener">Online copy</a></div>@endif
                            </span>
                        </div>
                    </td>
                    <td>@if($book->book_type)<span class="badge badge-light">{{ $book->book_type }}</span>@endif</td>
                    <td>{{ $book->myClass->name ?? '—' }}</td>
                    <td>@if($book->location)<i class="icon-location4 mr-1 text-muted"></i>{{ $book->location }}@else — @endif</td>
                    <td>
                        {{ (int) $book->issued_copies }} issued / {{ (int) $book->total_copies }} total
                        <div class="text-muted">{{ $available }} available</div>
                    </td>
                    <td><span class="dashboard-status {{ $statusTone }}">{{ $statusLabel }}</span></td>
                    <td>
                        <a href="{{ route('books.edit', $book->id) }}" class="btn btn-light btn-sm"><i class="icon-pencil7 mr-1"></i> Edit</a>
                        @if(Qs::userIsTeamSA())
                            <form method="post" action="{{ route('books.destroy', $book->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this book from the catalogue?" data-confirm-title="Delete Book" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        @if(request()->filled('q') || request()->filled('type') || request()->filled('class_id') || request()->filled('availability'))
                            @include('partials.patterns.empty_state', [
                                'icon' => 'icon-books',
                                'message' => 'No books match your search or filters.',
                                'action_url' => route('books.index'),
                                'action_label' => 'Reset Search & Filters',
                            ])
                        @else
                            @include('partials.patterns.empty_state', [
                                'icon' => 'icon-books',
                                'message' => 'No books found. Add the first book to the library catalogue.',
                                'action_url' => route('books.create'),
                                'action_label' => 'New Book',
                            ])
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        {{ $books->links() }}
    @endcomponent
@endsection
