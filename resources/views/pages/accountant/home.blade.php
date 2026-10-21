@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 7 (Workstream A): accountant home — fee collection at a glance. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Here&rsquo;s the school&rsquo;s fee collection at a glance.</p>
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

    {{-- Fee summary — same one-source-of-truth convention as the main dashboard --}}
    <div class="row dashboard-stats">
        @foreach([
            ['label' => 'Collected Today', 'value' => \App\Helpers\Money::inr($collected_today), 'sub' => 'Recorded today', 'icon' => 'icon-wallet', 'tone' => 'blue'],
            ['label' => 'Collected This Month', 'value' => \App\Helpers\Money::inr($collected_month), 'sub' => $today->format('F Y'), 'icon' => 'icon-stats-growth', 'tone' => 'green'],
            ['label' => 'Pending Payments', 'value' => number_format($pending_count), 'sub' => 'Records not fully paid', 'icon' => 'icon-users4', 'tone' => 'orange'],
            ['label' => 'Pending Amount', 'value' => \App\Helpers\Money::inr($pending_amount), 'sub' => 'Still to collect', 'icon' => 'icon-wallet', 'tone' => 'purple'],
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
            {{-- Recent Payments — latest fee records across the school --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-wallet text-blue mr-1"></i> Recent Payments</h5><p class="dashboard-muted mb-0">Latest payment records</p></div>
                    <a href="{{ route('payments.manage') }}" class="dashboard-view-all">Manage <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Student</th><th>Payment</th><th>Amount Paid</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($recent_payments as $record)
                            <tr>
                                <td><strong>{{ optional($record->student)->name ?: '—' }}</strong></td>
                                <td>{{ optional($record->payment)->title ?: '—' }}</td>
                                <td>{{ \App\Helpers\Money::inr($record->amt_paid ?: 0) }}</td>
                                <td>{{ $record->created_at ? $record->created_at->format('Y-m-d') : '—' }}</td>
                                <td><span class="dashboard-status {{ $record->paid ? 'status-success' : 'status-warning' }}">{{ $record->paid ? 'Paid' : 'Pending' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="dashboard-empty">No payment records yet.</td></tr>
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
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Your everyday finance tasks.</p></div>
                    <span class="dashboard-heading-icon"><i class="icon-arrow-right7"></i></span>
                </div>
                <div class="dashboard-action-grid">
                    <a href="{{ route('payments.create') }}"><i class="icon-wallet"></i><span>Record Payment</span></a>
                    <a href="{{ route('payments.manage') }}"><i class="icon-stats-bars"></i><span>Manage Payments</span></a>
                    <a href="{{ route('payments.index') }}"><i class="icon-book"></i><span>Fee Structure</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Account</span></a>
                </div>
            </section>

            {{-- Upcoming Events — fee deadlines and school events --}}
            <section class="dashboard-panel dashboard-upcoming mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Upcoming Events</h5></div>
                </div>
                @forelse($upcoming_events as $event)
                    <div class="dashboard-event">
                        <span class="event-date event-{{ $event['type'] }}"><small>{{ $event['month'] }}</small><strong>{{ $event['day'] }}</strong></span>
                        <span class="event-copy"><strong>{{ $event['title'] }}</strong><small>{{ $event['meta'] }}</small></span>
                        <span class="dashboard-status event-pill pill-{{ $event['type'] }}">{{ $event['type_label'] }}</span>
                    </div>
                @empty
                    <div class="dashboard-empty dashboard-event-empty">No upcoming events. Add events, exams or timetables and they will appear here.</div>
                @endforelse
            </section>
        </div>
    </div>
@endsection
