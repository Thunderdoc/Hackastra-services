@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('hide_page_header', true)
@section('content')
    {{-- Loop 92: accountant home — premium fee-office dashboard.
         Every figure comes from HomeController@accountantHomeData:
         collections from the receipts ledger (the collection report's
         source), dues from the fee defaulters computation. KPI deltas
         print only when both compared periods hold receipts. --}}
    @php
        $todayDelta = ($collected_today > 0 && $collected_yesterday > 0)
            ? (int) round(($collected_today - $collected_yesterday) / $collected_yesterday * 100)
            : null;
        $monthDelta = ($collected_month > 0 && $collected_prev_month > 0)
            ? (int) round(($collected_month - $collected_prev_month) / $collected_prev_month * 100)
            : null;
        $trendMax = $collection_values ? max($collection_values) : 0;
        $methodColors = ['Counter' => '#3478f6', 'Online (Razorpay)' => '#6845d8'];
    @endphp
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Fee office overview — collections, outstanding balances and recent payments.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
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
            ['label' => 'Collected Today', 'value' => \App\Helpers\Money::inr($collected_today), 'delta' => $todayDelta, 'delta_vs' => 'yesterday', 'sub' => $payments_today_count . ' ' . ($payments_today_count === 1 ? 'payment' : 'payments') . ' recorded', 'icon' => 'icon-wallet', 'tone' => 'blue'],
            ['label' => 'Collected This Month', 'value' => \App\Helpers\Money::inr($collected_month), 'delta' => $monthDelta, 'delta_vs' => 'previous month', 'sub' => $today->format('F Y'), 'icon' => 'icon-stats-growth', 'tone' => 'green'],
            ['label' => 'Total Collected', 'value' => \App\Helpers\Money::inr($collected_session), 'delta' => null, 'delta_vs' => '', 'sub' => 'Session ' . ($current_session ?: '—'), 'icon' => 'icon-coins', 'tone' => 'purple'],
            ['label' => 'Outstanding Fees', 'value' => \App\Helpers\Money::inr($outstanding_total), 'delta' => null, 'delta_vs' => '', 'sub' => $defaulters_count . ' ' . ($defaulters_count === 1 ? 'student' : 'students') . ' with fees due', 'icon' => 'icon-users4', 'tone' => 'orange'],
            ['label' => 'Payments Today', 'value' => number_format($payments_today_count), 'delta' => null, 'delta_vs' => '', 'sub' => 'Receipts issued today', 'icon' => 'icon-file-text2', 'tone' => 'blue'],
        ] as $stat)
            <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                    <strong>{{ $stat['value'] }}</strong>
                    @if($stat['delta'] !== null)
                        <small><span class="l92-delta {{ $stat['delta'] < 0 ? 'l92-delta-down' : 'l92-delta-up' }}">{{ $stat['delta'] < 0 ? '▼' : '▲' }} {{ abs($stat['delta']) }}% vs {{ $stat['delta_vs'] }}</span></small>
                    @else
                        <small>{{ $stat['sub'] }}</small>
                    @endif
                </span>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Collection Trend — one bar per month present in the
                 session ledger, from the receipts table --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-stats-bars text-blue mr-1"></i> Collection Trend</h5><p class="dashboard-muted mb-0">Fees collected per month, from the receipts ledger</p></div>
                    @if($collection_period)
                        <span class="dashboard-period"><i class="icon-calendar3 mr-1"></i>{{ $collection_period }}</span>
                    @endif
                </div>
                @if($collection_values)
                    <div class="l92-trend" role="img" aria-label="Fees collected per month this session: @foreach($collection_labels as $i => $label){{ $label }} {{ \App\Helpers\Money::inr($collection_values[$i]) }}@if(!$loop->last), @endif @endforeach">
                        @foreach($collection_values as $i => $value)
                            <div class="l92-trend-col" title="{{ $collection_labels[$i] }}: {{ \App\Helpers\Money::inr($value) }}">
                                <span class="l92-trend-value">{{ \App\Helpers\Money::inr($value) }}</span>
                                <span class="l92-trend-bar" style="height: {{ $trendMax > 0 ? max(4, (int) round($value / $trendMax * 100)) : 0 }}%"></span>
                                <small>{{ $collection_labels[$i] }}</small>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="dashboard-empty">No collections recorded this session yet.</p>
                @endif
            </section>

            {{-- Recent Payments — latest receipts (money actually taken) --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-wallet text-blue mr-1"></i> Recent Payments</h5><p class="dashboard-muted mb-0">Latest fee payments recorded in the system</p></div>
                    <a href="{{ route('payments.manage') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>#</th><th>Student</th><th>Receipt No.</th><th>Class</th><th>Method</th><th>Amount</th><th>Date &amp; Time</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse($recent_payments as $row)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td><strong>{{ $row['student'] }}</strong><small>{{ $row['title'] }}</small></td>
                                <td><span class="l92-ref">{{ $row['ref'] }}</span></td>
                                <td>{{ $row['class'] }}</td>
                                <td>{{ $row['method'] }}</td>
                                <td><strong>{{ \App\Helpers\Money::inr($row['amount']) }}</strong></td>
                                <td>{{ $row['date'] ? $row['date']->format('d M Y') : '—' }}<small>{{ $row['date'] ? $row['date']->format('h:i A') : '' }}</small></td>
                                <td><span class="dashboard-status {{ $row['cleared'] ? 'status-success' : 'status-warning' }}">{{ $row['cleared'] ? 'Cleared' : 'Balance Due' }}</span></td>
                                <td><a target="_blank" href="{{ route('payments.receipts', \App\Helpers\Qs::hash($row['pr_id'])) }}" class="l92-row-action" title="View receipt"><i class="icon-printer"></i></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="dashboard-empty">No payments recorded yet.</td></tr>
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
                    <a href="{{ route('payments.create') }}" class="l92-tile l92-tile-blue"><i class="icon-wallet"></i><span>Record Payment</span></a>
                    <a href="{{ route('payments.manage') }}" class="l92-tile l92-tile-violet"><i class="icon-list2"></i><span>Payments List</span></a>
                    <a href="{{ route('payments.defaulters') }}" class="l92-tile l92-tile-rose"><i class="icon-users4"></i><span>Defaulters Report</span></a>
                    <a href="{{ route('payments.collection') }}" class="l92-tile l92-tile-green"><i class="icon-stats-bars"></i><span>Collection Report</span></a>
                    <a href="{{ route('circulars.index') }}" class="l92-tile l92-tile-amber"><i class="icon-megaphone"></i><span>Circulars</span></a>
                    <a href="{{ route('my_account') }}" class="l92-tile l92-tile-slate"><i class="icon-user-tie"></i><span>My Account</span></a>
                </div>
            </section>

            {{-- Collection by Payment Method — buckets are what the
                 receipts ledger itself records: gateway receipts carry
                 method='online_razorpay'; counter receipts store NULL
                 by design. Slices sum to Total Collected. --}}
            @if($method_breakdown)
                <section class="dashboard-panel mb-4">
                    <div class="dashboard-panel-heading">
                        <div><h5 class="mb-1"><i class="icon-pie-chart text-blue mr-1"></i> Collection by Payment Method</h5><p class="dashboard-muted mb-0">Session {{ $current_session ?: '—' }}, from the receipts ledger</p></div>
                    </div>
                    <div class="l92-donut-wrap">
                        @php
                            $circ = 2 * pi() * 42;
                            $offset = 0;
                        @endphp
                        <svg class="l92-donut" viewBox="0 0 120 120" width="132" height="132" role="img" aria-label="Collection by payment method this session: @foreach($method_breakdown as $bucket){{ $bucket['label'] }} {{ $bucket['pct'] }}%@if(!$loop->last), @endif @endforeach">
                            <circle class="l92-donut-track" cx="60" cy="60" r="42" fill="none" stroke-width="15"></circle>
                            @foreach($method_breakdown as $bucket)
                                @php
                                    $seg = $collected_session > 0 ? $bucket['amount'] / $collected_session * $circ : 0;
                                @endphp
                                <circle cx="60" cy="60" r="42" fill="none" stroke="{{ $methodColors[$bucket['label']] ?? '#8494a7' }}" stroke-width="15"
                                        stroke-dasharray="{{ round(max(0, $seg - 1.5), 2) }} {{ round($circ, 2) }}"
                                        stroke-dashoffset="{{ round(-$offset, 2) }}"
                                        transform="rotate(-90 60 60)">
                                    <title>{{ $bucket['label'] }}: {{ \App\Helpers\Money::inr($bucket['amount']) }} ({{ $bucket['pct'] }}%)</title>
                                </circle>
                                @php $offset += $seg; @endphp
                            @endforeach
                            <text x="60" y="58" text-anchor="middle" class="l92-donut-num">{{ $current_session ?: '—' }}</text>
                            <text x="60" y="74" text-anchor="middle" class="l92-donut-cap">Session</text>
                        </svg>
                        <div class="l92-donut-legend">
                            @foreach($method_breakdown as $bucket)
                                <div class="l92-legend-row">
                                    <span class="l92-legend-dot" style="background: {{ $methodColors[$bucket['label']] ?? '#8494a7' }}"></span>
                                    <span class="l92-legend-label">{{ $bucket['label'] }}</span>
                                    <span class="l92-legend-fig"><strong>{{ \App\Helpers\Money::inr($bucket['amount']) }}</strong><small>{{ $bucket['pct'] }}% · {{ $bucket['count'] }} {{ $bucket['count'] === 1 ? 'receipt' : 'receipts' }}</small></span>
                                </div>
                            @endforeach
                            <div class="l92-legend-total">
                                <span>Total Collected</span>
                                <strong>{{ \App\Helpers\Money::inr($collected_session) }}</strong>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Outstanding Fees — summary strip into the full report --}}
            <a href="{{ route('payments.defaulters') }}" class="l92-outstanding mb-4">
                <span class="l92-outstanding-icon"><i class="icon-warning2"></i></span>
                <span class="l92-outstanding-copy">
                    <small>Outstanding Fees Summary</small>
                    <strong>{{ \App\Helpers\Money::inr($outstanding_total) }}</strong>
                    <span>{{ $defaulters_count }} {{ $defaulters_count === 1 ? 'student' : 'students' }} with fees due</span>
                </span>
                <i class="icon-arrow-right7 l92-outstanding-arrow"></i>
            </a>

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
