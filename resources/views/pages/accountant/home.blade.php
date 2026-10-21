@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 14 (Workstream X): accountant home — the fee office at a
         glance. Every figure comes from HomeController@accountantHomeData:
         collections from the receipts ledger (the collection report's
         source), dues from the fee defaulters computation. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Fee office overview — what came in, what&rsquo;s due, and the latest payments.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                    <small>Current Session: {{ $current_session ?: '—' }}</small>
                </span>
            </div>
            <a href="{{ route('payments.create') }}" class="btn dashboard-primary-action">
                <i class="icon-wallet mr-2"></i> Record Payment
            </a>
        </div>
    </div>

    {{-- Fee summary — five cards, one source of truth per figure --}}
    <div class="acc-stat-grid mb-4">
        @foreach([
            ['label' => 'Collected Today', 'value' => \App\Helpers\Money::inr($collected_today), 'sub' => $payments_today_count . ' ' . ($payments_today_count === 1 ? 'payment' : 'payments') . ' recorded', 'icon' => 'icon-wallet', 'tone' => 'blue'],
            ['label' => 'Collected This Month', 'value' => \App\Helpers\Money::inr($collected_month), 'sub' => $today->format('F Y'), 'icon' => 'icon-stats-growth', 'tone' => 'green'],
            ['label' => 'Total Collected', 'value' => \App\Helpers\Money::inr($collected_session), 'sub' => 'Session ' . ($current_session ?: '—'), 'icon' => 'icon-coins', 'tone' => 'purple'],
            ['label' => 'Outstanding', 'value' => \App\Helpers\Money::inr($outstanding_total), 'sub' => $defaulters_count . ' ' . ($defaulters_count === 1 ? 'student' : 'students') . ' with fees due', 'icon' => 'icon-users4', 'tone' => 'orange'],
            ['label' => 'Payments Today', 'value' => number_format($payments_today_count), 'sub' => 'Receipts issued today', 'icon' => 'icon-file-text2', 'tone' => 'blue'],
        ] as $stat)
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
        @endforeach
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Collection Trend — receipts grouped by month, last six months --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-stats-bars text-blue mr-1"></i> Collection Trend</h5><p class="dashboard-muted mb-0">Fees collected per month, from the receipts ledger</p></div>
                    <span class="dashboard-period">{{ $today->copy()->subMonths(5)->format('M Y') }} – {{ $today->format('M Y') }}</span>
                </div>
                <div class="acc-chart-wrap">
                    @include('partials.charts.bar', [
                        'labels' => $collection_labels,
                        'values' => $collection_values,
                        'chartTitle' => 'Fees collected per month for the last six months, in rupees',
                    ])
                </div>
            </section>

            {{-- Recent Payments — latest receipts (money actually taken) --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-wallet text-blue mr-1"></i> Recent Payments</h5><p class="dashboard-muted mb-0">Latest payments recorded</p></div>
                    <a href="{{ route('payments.manage') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Student</th><th>Class</th><th>Amount</th><th>Method</th><th>Date</th></tr></thead>
                        <tbody>
                        @forelse($recent_payments as $row)
                            <tr>
                                <td><strong>{{ $row['student'] }}</strong><small>{{ $row['title'] }}</small></td>
                                <td>{{ $row['class'] }}</td>
                                <td><strong>{{ \App\Helpers\Money::inr($row['amount']) }}</strong></td>
                                <td>{{ $row['method'] }}</td>
                                <td>{{ $row['date'] ? $row['date']->format('d M Y') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="dashboard-empty">No payments recorded yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Quick Actions --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-rocket text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Your everyday finance tasks.</p></div>
                </div>
                <div class="dashboard-action-grid acc-action-grid">
                    <a href="{{ route('payments.create') }}"><i class="icon-wallet"></i><span>Record Payment</span></a>
                    <a href="{{ route('payments.manage') }}"><i class="icon-list2"></i><span>Payments List</span></a>
                    <a href="{{ route('payments.defaulters') }}"><i class="icon-users4"></i><span>Defaulters Report</span></a>
                    <a href="{{ route('payments.collection') }}"><i class="icon-stats-bars"></i><span>Collection Report</span></a>
                    <a href="{{ route('circulars.index') }}"><i class="icon-megaphone"></i><span>Circulars</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Account</span></a>
                </div>
            </section>

            {{-- Fee Defaulters — top five, same figures as the full report --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-warning2 text-orange mr-1"></i> Fee Defaulters</h5><p class="dashboard-muted mb-0">Highest dues this session</p></div>
                    <a href="{{ route('payments.defaulters') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($defaulters as $row)
                    <div class="acc-defaulter">
                        <span class="acc-defaulter-copy">
                            <strong>{{ optional($row['student']->user)->name ?: '—' }}</strong>
                            <small>{{ optional($row['student']->my_class)->name ?: '—' }}{{ optional($row['student']->section)->name ? ' · ' . $row['student']->section->name : '' }} · {{ $row['items'] }} unpaid {{ $row['items'] === 1 ? 'item' : 'items' }}</small>
                        </span>
                        <strong class="acc-defaulter-amount">{{ \App\Helpers\Money::inr($row['total_due']) }}</strong>
                    </div>
                @empty
                    <div class="dashboard-empty dashboard-event-empty">No outstanding fees — every student is paid up for this session.</div>
                @endforelse
            </section>

            {{-- Recent Circulars — staff-visible feed only --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-megaphone text-blue mr-1"></i> Recent Circulars</h5><p class="dashboard-muted mb-0">Latest announcements for staff</p></div>
                    <a href="{{ route('circulars.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($recent_circulars as $circular)
                    <a href="{{ route('circulars.show', $circular->id) }}" class="acc-circular">
                        <span class="acc-circular-copy">
                            <strong>{{ $circular->title }}</strong>
                            <small>{{ optional($circular->starts_at)->format('d M Y') ?: $circular->created_at->format('d M Y') }} &middot; by {{ optional($circular->publisher)->name ?: 'School Office' }}</small>
                        </span>
                        <span class="acc-circular-chips">
                            <span class="dashboard-status acc-chip-audience">{{ \App\Models\Circular::audiences()[$circular->audience] ?? ucfirst($circular->audience) }}</span>
                            <span class="dashboard-status acc-chip-priority-{{ $circular->priority }}">{{ \App\Models\Circular::priorities()[$circular->priority] ?? ucfirst($circular->priority) }}</span>
                        </span>
                    </a>
                @empty
                    <div class="dashboard-empty dashboard-event-empty">No circulars right now.</div>
                @endforelse
            </section>
        </div>
    </div>

    {{-- Reminders — real counts only --}}
    @if(!empty($reminders))
        <section class="dashboard-panel mb-4">
            <div class="dashboard-panel-heading">
                <div><h5 class="mb-1"><i class="icon-bell2 text-blue mr-1"></i> Reminders</h5><p class="dashboard-muted mb-0">What needs the fee office&rsquo;s attention.</p></div>
            </div>
            <div class="acc-reminder-grid">
                @foreach($reminders as $reminder)
                    <a href="{{ $reminder['link'] }}" class="acc-reminder dashboard-stat-{{ $reminder['tone'] }}">
                        <span class="dashboard-stat-icon"><i class="{{ $reminder['icon'] }}"></i></span>
                        <span class="acc-reminder-copy">
                            <strong>{{ $reminder['title'] }}</strong>
                            <small>{{ $reminder['text'] }}</small>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
