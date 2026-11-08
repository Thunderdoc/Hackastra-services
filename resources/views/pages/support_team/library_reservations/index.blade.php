@extends('layouts.master')
@section('page_title', 'Library Reservations')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Library &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Book Search &amp; Reserve</h5>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif

            <p>Search the catalogue by title or author. A book can be reserved only while a copy is on the shelf (total copies above issued copies). Reserving does not issue the book and does not change any copy count; cancelling a reservation frees it again.</p>

            <form method="get" action="{{ route('library_reservations.index') }}">
                <div class="form-group">
                    <label for="lr-q">Search books</label>
                    <input type="text" id="lr-q" name="q" value="{{ $q }}" class="form-control" placeholder="Search by title or author&hellip;">
                </div>
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="{{ route('library_reservations.index') }}" class="btn btn-light">Reset</a>
            </form>

            <h6 class="mt-3">Books</h6>
            @if($books->count() === 0)
                @if($q !== '')
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-search4',
                        'message' => 'No books found for "' . $q . '".',
                        'action_html' => '<a class="btn btn-primary" href="' . route('library_reservations.index') . '">Clear search</a>',
                    ])
                @else
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-books',
                        'message' => 'No books found.',
                    ])
                @endif
            @else
                <div class="table-responsive">
                    <table class="table hk-std hk-std-720">
                        {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                        <colgroup><col style="width:26%"><col style="width:20%"><col style="width:11%"><col style="width:17%"><col style="width:10%"><col style="width:16%"></colgroup>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Type</th>
                                <th>Copies</th>
                                <th>Available</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($books as $book)
                                <tr>
                                    <td class="hk-ellip">{{ $book->name }}</td>
                                    <td>{{ $book->author }}</td>
                                    <td>{{ $book->book_type }}</td>
                                    <td class="hk-nw">{{ $book->total_copies }} total / {{ $book->issued_copies }} issued</td>
                                    <td class="hk-nw">{{ $book->availableCopies() }}</td>
                                    <td>
                                        @if(!$canReserve)
                                            &mdash;
                                        @elseif(in_array($book->id, $myReservedBookIds, true))
                                            Reserved
                                        @elseif($book->availableCopies() < 1)
                                            Not available
                                        @else
                                            <form method="post" action="{{ route('library_reservations.store') }}">
                                                @csrf
                                                <input type="hidden" name="book_id" value="{{ $book->id }}">
                                                @if(Auth::user()->user_type === 'parent')
                                                    <div class="form-group">
                                                        <label for="lr-child-{{ $book->id }}">Reserve for</label>
                                                        <select id="lr-child-{{ $book->id }}" name="student_record_id" class="form-control" required>
                                                            <option value="">Choose your child</option>
                                                            @foreach($children as $child)
                                                                <option value="{{ $child->id }}">{{ optional($child->user)->name ?? ('Student #' . $child->id) }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endif
                                                <button type="submit" class="btn btn-primary btn-sm">Reserve</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $books->links() }}
            @endif

            <h6 class="mt-3">{{ $isStaff ? 'All reservations' : 'My reservations' }}</h6>
            @if($reservations->count() === 0)
                @include('partials.patterns.empty_state', ['icon' => 'icon-books', 'message' => 'No reservations yet.'])
            @else
                <div class="table-responsive">
                    <table class="table hk-std hk-std-720">
                        {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                        @if($isStaff)<colgroup><col style="width:22%"><col style="width:16%"><col style="width:18%"><col style="width:11%"><col style="width:15%"><col style="width:18%"></colgroup>@else<colgroup><col style="width:26%"><col style="width:22%"><col style="width:13%"><col style="width:17%"><col style="width:22%"></colgroup>@endif
                        <thead>
                            <tr>
                                <th>Book</th>
                                @if($isStaff)
                                    <th>Reserved by</th>
                                @endif
                                <th>For student</th>
                                <th>Status</th>
                                <th>Reserved on</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reservations as $reservation)
                                <tr>
                                    <td>{{ optional($reservation->book)->name ?? 'Deleted book' }}</td>
                                    @if($isStaff)
                                        <td>{{ optional($reservation->user)->name ?? 'Unknown' }}</td>
                                    @endif
                                    <td>{{ optional(optional($reservation->studentRecord)->user)->name ?? '&mdash;' }}</td>
                                    <td class="hk-nw">{{ $reservation->status }}</td>
                                    <td class="hk-nw">{{ $reservation->created_at }}</td>
                                    <td>
                                        @if($reservation->status === 'reserved')
                                            <div class="d-flex" style="gap:6px">
                                                @if($isStaff)
                                                    <form method="post" action="{{ route('library_reservations.collect', $reservation) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success btn-sm">Collected</button>
                                                    </form>
                                                @endif
                                                <form method="post" action="{{ route('library_reservations.cancel', $reservation) }}" data-confirm="Cancel the reservation for {{ optional($reservation->book)->name ?? 'this book' }}?" data-confirm-title="Cancel Reservation" data-confirm-action="Cancel Reservation">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-light btn-sm">Cancel</button>
                                                </form>
                                            </div>
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $reservations->links() }}
            @endif
        </div>
    </div>
@endsection
