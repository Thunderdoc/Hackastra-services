@extends('layouts.master')
@section('page_title', 'Book Loans & Requests')
@section('content')
    {{-- Loop 108: rebuilt on the shared list chrome (Loop 28). Was a
         legacy card with header-elements chrome. Behaviour unchanged:
         same status filter links, same loan rows, same Mark Returned
         flow (styled-confirm attributes intact), same pagination.
         The table carries l108-stack so every column stays visible
         on phone widths. --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub">Books currently out and loans already returned.</div>
            </div>
            <div class="pat-toolbar-actions">
                <a href="{{ route('books.index') }}" class="btn btn-light btn-sm"><i class="icon-books mr-1"></i> Catalogue</a>
                @if(Qs::userIsTeamLibraryWrite())
                <a href="{{ route('book_requests.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> Issue a Book</a>
                @endif
            </div>
        </div>

        <div class="pat-filters">
            <div class="btn-group" role="group" aria-label="Filter loans by status">
                <a href="{{ route('book_requests.index') }}" class="btn btn-sm {{ !$status ? 'btn-primary' : 'btn-light' }}">All</a>
                <a href="{{ route('book_requests.index', ['status' => 'issued']) }}" class="btn btn-sm {{ $status === 'issued' ? 'btn-primary' : 'btn-light' }}">Issued</a>
                <a href="{{ route('book_requests.index', ['status' => 'returned']) }}" class="btn btn-sm {{ $status === 'returned' ? 'btn-primary' : 'btn-light' }}">Returned</a>
            </div>
        </div>

        <div class="card pat-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table l108-stack">
                        <thead><tr><th>Book</th><th>Borrower</th><th>Issued</th><th>Due</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        @forelse($loans as $loan)
                            <tr>
                                <td data-label="Book">
                                    <strong>{{ $loan->book->name ?? '—' }}</strong>
                                    @if($loan->book && $loan->book->author)<div class="text-muted">by {{ $loan->book->author }}</div>@endif
                                </td>
                                <td data-label="Borrower">
                                    {{ $loan->user->name ?? '—' }}
                                    @if($loan->user)<div class="text-muted">{{ ucfirst(str_replace('_', ' ', $loan->user->user_type)) }}</div>@endif
                                </td>
                                <td data-label="Issued">{{ $loan->start_date ? $loan->start_date->format('d M Y') : '—' }}</td>
                                <td data-label="Due">
                                    {{ $loan->end_date ? $loan->end_date->format('d M Y') : '—' }}
                                    @if($loan->isOverdue())<div class="text-danger font-weight-bold">Overdue</div>@endif
                                </td>
                                <td data-label="Status">
                                    @if($loan->returned)
                                        <span class="badge badge-success">Returned</span>
                                    @elseif($loan->isOverdue())
                                        <span class="badge badge-danger">Overdue</span>
                                    @else
                                        <span class="badge badge-info">Issued</span>
                                    @endif
                                </td>
                                <td data-label="Action">
                                    @if(!$loan->returned)
                                        <form method="post" action="{{ route('book_requests.return', Qs::hash($loan->id)) }}" style="display:inline">@csrf @method('patch')<button type="submit" class="btn btn-light btn-sm" data-confirm="Mark this book as returned?" data-confirm-title="Mark as Returned" data-confirm-action="Mark Returned">Mark Returned</button></form>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">No loans found. Issue a book to see it here.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $loans->appends(request()->query())->links() }}
            </div>
        </div>
    </div>
@endsection
