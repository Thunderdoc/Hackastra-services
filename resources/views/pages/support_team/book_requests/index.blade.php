@extends('layouts.master')
@section('page_title', 'Book Loans & Requests')
@section('content')
    {{-- Loop 108: rebuilt on the shared list chrome (Loop 28). Was a
         legacy card with header-elements chrome. Behaviour unchanged:
         same status filter links, same loan rows, same Mark Returned
         flow (styled-confirm attributes intact), same pagination.
         The table rides the shared data_table pattern (Round 5
         P11, hk-std-loans): phones scroll it sideways inside its
         one responsive wrapper. --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub">Books currently out and loans already returned.</div>
            </div>
            <div class="pat-toolbar-actions">
                <a href="{{ route('books.index') }}" class="btn btn-light btn-sm"><i class="icon-books mr-1"></i> Catalogue</a>
                <a href="{{ route('library_fines.index') }}" class="btn btn-light btn-sm"><i class="icon-coin-dollar mr-1"></i> Library Fines</a>
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
                @component('partials.patterns.data_table', [
                    'columns' => [
                        ['key' => 'book', 'label' => 'Book', 'width' => 28, 'class' => 'hk-ellip'],
                        ['key' => 'borrower', 'label' => 'Borrower', 'width' => 22, 'class' => 'hk-ellip'],
                        ['key' => 'issued', 'label' => 'Issued', 'width' => 12, 'class' => 'hk-nw'],
                        ['key' => 'due', 'label' => 'Due', 'width' => 13, 'class' => 'hk-nw'],
                        ['key' => 'status', 'label' => 'Status', 'width' => 11, 'class' => 'hk-nw'],
                        ['key' => 'action', 'label' => 'Action', 'width' => 14],
                    ],
                    'variant' => 'loans',
                    'min_width' => 820,
                    'is_empty' => $loans->isEmpty(),
                    'empty' => [
                        'icon' => 'icon-books',
                        'message' => 'No loans found. Issue a book to see it here.',
                    ],
                ])
                    @foreach($loans as $loan)
                        <tr>
                            <td>
                                <strong>{{ $loan->book->name ?? '—' }}</strong>
                                @if($loan->book && $loan->book->author)<div class="text-muted">by {{ $loan->book->author }}</div>@endif
                            </td>
                            <td>
                                {{ $loan->user->name ?? '—' }}
                                @if($loan->user)<div class="text-muted">{{ ucfirst(str_replace('_', ' ', $loan->user->user_type)) }}</div>@endif
                            </td>
                            <td>{{ $loan->start_date ? $loan->start_date->format('d M Y') : '—' }}</td>
                            <td>
                                {{ $loan->end_date ? $loan->end_date->format('d M Y') : '—' }}
                                @if($loan->isOverdue())<div class="text-danger font-weight-bold">Overdue</div>@endif
                            </td>
                            <td>
                                @if($loan->returned)
                                    <span class="badge badge-success">Returned</span>
                                @elseif($loan->isOverdue())
                                    <span class="badge badge-danger">Overdue</span>
                                @else
                                    <span class="badge badge-info">Issued</span>
                                @endif
                            </td>
                            <td>
                                @if(!$loan->returned)
                                    <form method="post" action="{{ route('book_requests.return', Qs::hash($loan->id)) }}" style="display:inline">@csrf @method('patch')<button type="submit" class="btn btn-light btn-sm" data-confirm="Mark this book as returned?" data-confirm-title="Mark as Returned" data-confirm-action="Mark Returned">Mark Returned</button></form>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endcomponent
                {{ $loans->appends(request()->query())->links() }}
            </div>
        </div>
    </div>
@endsection
