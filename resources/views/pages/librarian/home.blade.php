@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Workstream Y: librarian home — catalogue, loans and due dates from the real library tables. --}}
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
                    <small>Current Session: {{ $current_session ?: '—' }}</small>
                </span>
            </div>
            <a href="{{ route('book_requests.index') }}" class="btn dashboard-primary-action">
                <i class="icon-books mr-2"></i> Issue / Requests
            </a>
        </div>
    </div>

    {{-- Library summary — loan figures read the book_requests ledger directly --}}
    <div class="row dashboard-stats">
        @foreach([
            ['label' => 'Total Books', 'value' => number_format($book_copies), 'sub' => number_format($book_titles).' titles in the catalogue', 'icon' => 'icon-books', 'tone' => 'blue'],
            ['label' => 'Books Currently Issued', 'value' => number_format($books_issued), 'sub' => 'Out with borrowers now', 'icon' => 'icon-list', 'tone' => 'green'],
            ['label' => 'Due Today', 'value' => number_format($loans_due_today), 'sub' => 'To be returned today', 'icon' => 'icon-calendar3', 'tone' => 'purple'],
            ['label' => 'Overdue', 'value' => number_format($loans_overdue), 'sub' => 'Past their due date', 'icon' => 'icon-alarm', 'tone' => 'orange'],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                    <span class="dashboard-stat-top">
                        <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                    </span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                        <strong>{{ $stat['value'] }}</strong>
                        <small>{{ $stat['sub'] }}</small>
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Recent Activity — the latest loan events, newest first --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-books text-blue mr-1"></i> Recent Activity</h5><p class="dashboard-muted mb-0">Latest book loans and returns</p></div>
                    <a href="{{ route('book_requests.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Book</th><th>Borrower</th><th>Issued</th><th>Due</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($recent_loans as $loan)
                            <tr>
                                <td><strong>{{ optional($loan->book)->name ?: '—' }}</strong></td>
                                <td>{{ optional($loan->user)->name ?: '—' }}</td>
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
                            </tr>
                        @empty
                            <tr><td colspan="5" class="dashboard-empty">No loans yet. Issue a book and it will appear here.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Needs Attention — only real counts, only when there is something to act on --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-alarm text-blue mr-1"></i> Needs Attention</h5><p class="dashboard-muted mb-0">Library items that need a follow-up</p></div>
                </div>
                @if($loans_overdue > 0 || $loans_due_today > 0 || $titles_out_of_stock > 0)
                    <div class="lib-reminders">
                        @if($loans_overdue > 0)
                            <a href="{{ route('book_requests.index') }}" class="lib-reminder lib-reminder-danger">
                                <i class="icon-alarm"></i>
                                <span><strong>{{ number_format($loans_overdue) }} overdue</strong><small>Books past their due date</small></span>
                            </a>
                        @endif
                        @if($loans_due_today > 0)
                            <a href="{{ route('book_requests.index') }}" class="lib-reminder lib-reminder-warning">
                                <i class="icon-calendar3"></i>
                                <span><strong>{{ number_format($loans_due_today) }} due today</strong><small>Expected back by end of day</small></span>
                            </a>
                        @endif
                        @if($titles_out_of_stock > 0)
                            <a href="{{ route('books.index') }}" class="lib-reminder lib-reminder-info">
                                <i class="icon-books"></i>
                                <span><strong>{{ number_format($titles_out_of_stock) }} out of stock</strong><small>Titles with no copy on the shelf</small></span>
                            </a>
                        @endif
                    </div>
                @else
                    <div class="dashboard-empty">All clear — nothing overdue, nothing due today, and every title has a copy on the shelf.</div>
                @endif
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Quick Actions --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Your everyday library tasks.</p></div>
                    <span class="dashboard-heading-icon"><i class="icon-arrow-right7"></i></span>
                </div>
                <div class="dashboard-action-grid">
                    <a href="{{ route('books.index') }}"><i class="icon-books"></i><span>All Books</span></a>
                    <a href="{{ route('book_requests.index') }}"><i class="icon-list"></i><span>Issue Requests</span></a>
                    <a href="{{ route('books.create') }}"><i class="icon-plus2"></i><span>Add Book</span></a>
                    <a href="{{ route('circulars.index') }}"><i class="icon-megaphone"></i><span>Circulars</span></a>
                </div>
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
