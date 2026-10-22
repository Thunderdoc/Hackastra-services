@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('hide_page_header', true)
@section('content')
    {{-- Loop 100: librarian home premium pass — every figure is computed in
         HomeController::librarianHomeData from the books catalogue and the
         book_requests loan ledger; nothing here is decorative data. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Here&rsquo;s your library overview for today.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                </span>
            </div>
            <a href="{{ route('book_requests.index') }}" class="btn dashboard-primary-action">
                <i class="icon-books mr-2"></i> Issue / Return Book
            </a>
        </div>
    </div>

    {{-- Library summary — copies/titles from the catalogue, loan figures from the ledger.
         Each card links to the real list it summarises. --}}
    <div class="row dashboard-stats">
        @foreach([
            ['label' => 'Total Books', 'value' => number_format($book_copies), 'sub' => number_format($book_titles).' titles in the catalogue', 'icon' => 'icon-books', 'tone' => 'blue', 'href' => route('books.index')],
            ['label' => 'Books Issued', 'value' => number_format($books_issued), 'sub' => 'Currently with members', 'icon' => 'icon-list', 'tone' => 'purple', 'href' => route('book_requests.index', ['status' => 'issued'])],
            ['label' => 'Due Today', 'value' => number_format($loans_due_today), 'sub' => 'To be returned today', 'icon' => 'icon-calendar3', 'tone' => 'orange', 'href' => route('book_requests.index', ['status' => 'issued'])],
            ['label' => 'Overdue Books', 'value' => number_format($loans_overdue), 'sub' => 'Past their due date', 'icon' => 'icon-alarm', 'tone' => 'red', 'href' => route('book_requests.index', ['status' => 'issued'])],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                <a href="{{ $stat['href'] }}" class="l100-stat-link" aria-label="{{ $stat['label'] }}: {{ $stat['value'] }} — view list">
                    <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                        <span class="dashboard-stat-top">
                            <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                            <span class="l100-stat-arrow"><i class="icon-arrow-right7"></i></span>
                        </span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                            <strong>{{ $stat['value'] }}</strong>
                            <small>{{ $stat['sub'] }}</small>
                        </span>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Recently Issued Books — the latest loans, newest first; the row
                 action is the module's real Mark Returned flow. --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-books text-blue mr-1"></i> Recently Issued Books</h5><p class="dashboard-muted mb-0">Latest book loans from the library</p></div>
                    <a href="{{ route('book_requests.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>#</th><th>Book Title</th><th>Borrower</th><th>Issue Date</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        @forelse($recent_loans as $loan)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td><strong>{{ optional($loan->book)->name ?: '—' }}</strong></td>
                                <td>
                                    {{ optional($loan->user)->name ?: '—' }}
                                    @if($loan->user)<div class="text-muted">{{ ucfirst(str_replace('_', ' ', $loan->user->user_type)) }}</div>@endif
                                </td>
                                <td>{{ $loan->start_date ? $loan->start_date->format('d M Y') : '—' }}</td>
                                <td>{{ $loan->end_date ? $loan->end_date->format('d M Y') : '—' }}</td>
                                <td>
                                    @if($loan->returned)
                                        <span class="dashboard-status status-success">Returned</span>
                                    @elseif($loan->end_date && $loan->end_date->toDateString() < $today->toDateString())
                                        <span class="dashboard-status lib-pill-overdue">Overdue</span>
                                    @elseif($loan->end_date && $loan->end_date->toDateString() === $today->toDateString())
                                        <span class="dashboard-status status-warning">Due today</span>
                                    @else
                                        <span class="dashboard-status lib-pill-issued">Issued</span>
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
                        @empty
                            <tr><td colspan="7" class="dashboard-empty">No loans yet. Issue a book and it will appear here.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Quick Actions — only routes the librarian role really has --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-rocket text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Common library tasks</p></div>
                </div>
                <div class="dashboard-action-grid l100-actions">
                    <a href="{{ route('book_requests.create') }}" class="l100-tile l100-tile-blue"><i class="icon-redo2"></i><span>Issue Book</span></a>
                    <a href="{{ route('book_requests.index', ['status' => 'issued']) }}" class="l100-tile l100-tile-green"><i class="icon-undo2"></i><span>Return Book</span></a>
                    <a href="{{ route('books.create') }}" class="l100-tile l100-tile-violet"><i class="icon-plus2"></i><span>Add Book</span></a>
                    <a href="{{ route('books.index') }}" class="l100-tile l100-tile-amber"><i class="icon-books"></i><span>Catalogue</span></a>
                    <a href="{{ route('book_requests.index') }}" class="l100-tile l100-tile-rose"><i class="icon-list"></i><span>Book Loans</span></a>
                    <a href="{{ route('circulars.index') }}" class="l100-tile l100-tile-slate"><i class="icon-megaphone"></i><span>Circulars</span></a>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Book Type Overview — copies per real book_type, drawn from the
                 catalogue itself; the slices sum to Total Books (copies). --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-pie-chart text-blue mr-1"></i> Book Type Overview</h5><p class="dashboard-muted mb-0">Copies by catalogue type</p></div>
                </div>
                @if($type_breakdown->isNotEmpty())
                    @php
                        $typeColors = ['Book' => '#3478f6', 'Textbook' => '#22b573', 'Journal' => '#f5a623', 'Magazine' => '#8b5cf6', 'E-Book' => '#e05260', 'Other' => '#8494a7'];
                    @endphp
                    <div class="l100-donut-wrap">
                        @php
                            $circ = 2 * pi() * 42;
                            $offset = 0;
                        @endphp
                        <svg class="l100-donut" viewBox="0 0 120 120" width="132" height="132" role="img" aria-label="Book type overview: @foreach($type_breakdown as $bucket){{ $bucket['label'] }} {{ $bucket['pct'] }}%@if(!$loop->last), @endif @endforeach">
                            <circle class="l100-donut-track" cx="60" cy="60" r="42" fill="none" stroke-width="15"></circle>
                            @foreach($type_breakdown as $bucket)
                                @php
                                    $seg = $book_copies > 0 ? $bucket['copies'] / $book_copies * $circ : 0;
                                @endphp
                                <circle cx="60" cy="60" r="42" fill="none" stroke="{{ $typeColors[$bucket['label']] ?? '#8494a7' }}" stroke-width="15"
                                        stroke-dasharray="{{ round(max(0, $seg - 1.5), 2) }} {{ round($circ, 2) }}"
                                        stroke-dashoffset="{{ round(-$offset, 2) }}"
                                        transform="rotate(-90 60 60)">
                                    <title>{{ $bucket['label'] }}: {{ number_format($bucket['copies']) }} copies ({{ $bucket['pct'] }}%)</title>
                                </circle>
                                @php $offset += $seg; @endphp
                            @endforeach
                            <text x="60" y="58" text-anchor="middle" class="l100-donut-num">{{ number_format($book_copies) }}</text>
                            <text x="60" y="74" text-anchor="middle" class="l100-donut-cap">Total Copies</text>
                        </svg>
                        <div class="l100-donut-legend">
                            @foreach($type_breakdown as $bucket)
                                <div class="l100-legend-row">
                                    <span class="l100-legend-dot" style="background: {{ $typeColors[$bucket['label']] ?? '#8494a7' }}"></span>
                                    <span class="l100-legend-label">{{ $bucket['label'] }}</span>
                                    <span class="l100-legend-fig"><strong>{{ number_format($bucket['copies']) }}</strong><small>{{ $bucket['pct'] }}% · {{ $bucket['titles'] }} {{ $bucket['titles'] === 1 ? 'title' : 'titles' }}</small></span>
                                </div>
                            @endforeach
                            <div class="l100-legend-total">
                                <span>Total Books</span>
                                <strong>{{ number_format($book_copies) }} copies · {{ number_format($book_titles) }} titles</strong>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="dashboard-empty">No books in the catalogue yet. Add a book and the type mix will appear here.</div>
                @endif
            </section>

            {{-- Needs Attention — only real counts from the loan ledger and the
                 catalogue; an honest all-clear state when there is nothing to do. --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-alarm text-blue mr-1"></i> Needs Attention</h5><p class="dashboard-muted mb-0">Items that need a follow-up</p></div>
                    <a href="{{ route('book_requests.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @if($loans_overdue > 0 || $loans_due_today > 0 || $titles_out_of_stock > 0)
                    <div class="l100-attn">
                        @if($loans_overdue > 0)
                            <a href="{{ route('book_requests.index', ['status' => 'issued']) }}" class="l100-attn-row">
                                <span class="l100-attn-icon l100-attn-danger"><i class="icon-alarm"></i></span>
                                <span class="l100-attn-copy"><strong>Overdue Books</strong><small>Past their due date</small></span>
                                <strong class="l100-attn-count text-danger">{{ number_format($loans_overdue) }}</strong>
                                <i class="icon-arrow-right7 l100-attn-arrow"></i>
                            </a>
                        @endif
                        @if($loans_due_today > 0)
                            <a href="{{ route('book_requests.index', ['status' => 'issued']) }}" class="l100-attn-row">
                                <span class="l100-attn-icon l100-attn-warning"><i class="icon-calendar3"></i></span>
                                <span class="l100-attn-copy"><strong>Due Today</strong><small>To be returned today</small></span>
                                <strong class="l100-attn-count">{{ number_format($loans_due_today) }}</strong>
                                <i class="icon-arrow-right7 l100-attn-arrow"></i>
                            </a>
                        @endif
                        @if($titles_out_of_stock > 0)
                            <a href="{{ route('books.index') }}" class="l100-attn-row">
                                <span class="l100-attn-icon l100-attn-info"><i class="icon-books"></i></span>
                                <span class="l100-attn-copy"><strong>Out of Stock</strong><small>Titles with no copy on the shelf</small></span>
                                <strong class="l100-attn-count">{{ number_format($titles_out_of_stock) }}</strong>
                                <i class="icon-arrow-right7 l100-attn-arrow"></i>
                            </a>
                        @endif
                    </div>
                @else
                    <div class="dashboard-empty">Everything is up to date — nothing overdue, nothing due today, and every title has a copy on the shelf.</div>
                @endif
            </section>

            {{-- Recent Circulars — published, in window, addressed to everyone or staff --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-megaphone text-blue mr-1"></i> Recent Circulars</h5><p class="dashboard-muted mb-0">Announcements for you</p></div>
                    <a href="{{ route('circulars.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($recent_circulars as $circular)
                    <a href="{{ route('circulars.show', $circular->id) }}" class="lib-circular">
                        <span class="lib-circular-copy">
                            <strong>{{ $circular->title }}</strong>
                            <small>{{ \App\Models\Circular::audiences()[$circular->audience] ?? ucfirst($circular->audience) }} &middot; {{ ($circular->starts_at ?: $circular->created_at)->format('d M Y') }}</small>
                        </span>
                        @if($circular->priority === 'urgent')
                            <span class="dashboard-status lib-pill-urgent">Urgent</span>
                        @elseif($circular->priority === 'important')
                            <span class="dashboard-status status-warning">Important</span>
                        @else
                            <span class="dashboard-status lib-pill-normal">Normal</span>
                        @endif
                    </a>
                @empty
                    <div class="dashboard-empty">No circulars for you right now.</div>
                @endforelse
            </section>
        </div>
    </div>
@endsection
