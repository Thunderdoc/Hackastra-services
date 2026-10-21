@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 13 (Workstream W): parent home rebuilt to the dashboard
         design language — hero, five real stat cards, richer child
         cards, a fees summary, parent-visible circulars, events and a
         reminders strip. Every figure comes from HomeController's
         parent branch; fees use the fees-portal computation exactly. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">
                @if($children->isNotEmpty())
                    {{ $children->count() }} {{ $children->count() === 1 ? 'child' : 'children' }} linked to your account — attendance, fees and school news at a glance.
                @else
                    Welcome to your parent workspace.
                @endif
            </p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                    <small>Current Session: {{ $current_session ?: '—' }}</small>
                </span>
            </div>
            <a href="{{ route('my_fees') }}" class="btn dashboard-primary-action">
                <i class="icon-coins mr-2"></i> View Fees
            </a>
        </div>
    </div>

    {{-- Stat cards — real figures for this parent's children only --}}
    <div class="row dashboard-stats">
        <div class="col-sm-6 col-xl">
            <div class="dashboard-stat-card dashboard-stat-blue">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-users4"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">My Children</span>
                    <strong>{{ number_format($children->count()) }}</strong>
                    <small>Linked to your account</small>
                </span>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="dashboard-stat-card dashboard-stat-orange">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-coins"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Fees Due</span>
                    <strong>{{ \App\Helpers\Money::inr($fees_due) }}</strong>
                    <small>{{ $fees_due > 0 ? $fees_pending_records.' '.($fees_pending_records === 1 ? 'payment' : 'payments').' outstanding' : 'Nothing outstanding' }}</small>
                </span>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="dashboard-stat-card dashboard-stat-green">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-wallet"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Fees Paid</span>
                    <strong>{{ \App\Helpers\Money::inr($fees_paid) }}</strong>
                    <small>This session{{ $current_session ? ' ('.$current_session.')' : '' }}</small>
                </span>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="dashboard-stat-card dashboard-stat-purple">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-calendar52"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Children&rsquo;s Attendance</span>
                    @if($attendance_today['recorded'])
                        <strong>{{ $attendance_today['present'] }} of {{ $attendance_today['children'] }}</strong>
                        <small>Present today</small>
                    @elseif($attendance_today['session_percent'] !== null)
                        <strong>{{ $attendance_today['session_percent'] }}%</strong>
                        <small>Attendance this session</small>
                    @else
                        <strong>—</strong>
                        <small>No attendance recorded yet</small>
                    @endif
                </span>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="dashboard-stat-card dashboard-stat-blue">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-book"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Upcoming Exams</span>
                    <strong>{{ number_format($upcoming_exams_count) }}</strong>
                    <small>Examinations from today</small>
                </span>
            </div>
        </div>
    </div>

    {{-- Reminders — every chip is a real record, nothing decorative --}}
    @if($fees_due > 0 || $next_event || $circulars_week_count > 0 || ($children->isNotEmpty() && $fees_total > 0 && $fees_due === 0))
        <div class="par-reminders mb-4">
            <span class="par-reminders-label"><i class="icon-alarm"></i> Reminders</span>
            @if($fees_due > 0)
                <a href="{{ route('my_fees') }}" class="par-reminder par-reminder-warning">
                    <i class="icon-warning2"></i>
                    <span><strong>Fees due:</strong> {{ \App\Helpers\Money::inr($fees_due) }} outstanding across your {{ $children->count() === 1 ? 'child' : 'children' }}</span>
                </a>
            @elseif($children->isNotEmpty() && $fees_total > 0)
                <span class="par-reminder par-reminder-success">
                    <i class="icon-checkmark3"></i>
                    <span><strong>Fees clear</strong> — nothing outstanding for this session.</span>
                </span>
            @endif
            @if($next_event)
                <span class="par-reminder par-reminder-info">
                    <i class="icon-calendar3"></i>
                    <span><strong>Next {{ strtolower($next_event['type_label']) }}:</strong> {{ $next_event['title'] }} &middot; {{ $next_event['day'] }} {{ $next_event['month'] }}</span>
                </span>
            @endif
            @if($circulars_week_count > 0)
                <a href="{{ route('circulars.index') }}" class="par-reminder par-reminder-info">
                    <i class="icon-megaphone"></i>
                    <span><strong>{{ $circulars_week_count }} new {{ $circulars_week_count === 1 ? 'circular' : 'circulars' }}</strong> in the last 7 days</span>
                </a>
            @endif
        </div>
    @endif

    @if($children->isNotEmpty())
        <div class="row">
            @foreach($children as $child)
                @php($record = $child['record'])
                @php($fees_paid_pct = $child['fees']['total'] > 0 ? (int) round(($child['fees']['paid'] / $child['fees']['total']) * 100) : 0)
                <div class="col-xl-6">
                    <section class="dashboard-panel mb-4">
                        <div class="dashboard-panel-heading">
                            <div class="par-child-head">
                                <img src="{{ optional($record->user)->photo }}" alt="photo" class="par-child-photo">
                                <div>
                                    <h5 class="mb-1">{{ optional($record->user)->name }}</h5>
                                    <p class="dashboard-muted mb-0">
                                        {{ optional($record->my_class)->name ?: 'Class —' }} &middot; {{ optional($record->section)->name ?: 'Section —' }}
                                        @if($record->adm_no) &middot; Adm No: {{ $record->adm_no }} @endif
                                    </p>
                                </div>
                            </div>
                            <span class="dashboard-status {{ $child['fees']['pending_records'] > 0 ? 'status-warning' : 'status-success' }}">
                                {{ $child['fees']['pending_records'] > 0 ? 'Fees Pending' : 'Fees Clear' }}
                            </span>
                        </div>

                        <div class="table-responsive">
                            <table class="table dashboard-table mb-0">
                                <tbody>
                                    <tr>
                                        <td><strong>Attendance</strong></td>
                                        <td>
                                            @if($child['attendance']['percent'] !== null)
                                                {{ $child['attendance']['percent'] }}% present
                                                <small class="dashboard-muted">({{ $child['attendance']['present'] + $child['attendance']['late'] }} of {{ $child['attendance']['total'] }} marked days)</small>
                                            @else
                                                <span class="dashboard-muted">No attendance recorded yet</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Fees Paid</strong></td>
                                        <td>{{ \App\Helpers\Money::inr($child['fees']['paid']) }}
                                            @if($child['fees']['has_records'])
                                                <small class="dashboard-muted">of {{ \App\Helpers\Money::inr($child['fees']['total']) }}</small>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Fees Due</strong></td>
                                        <td>{{ \App\Helpers\Money::inr($child['fees']['due']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        @if($child['fees']['has_records'])
                            <div class="par-fee-progress-wrap">
                                <div class="par-fee-progress">
                                    <span style="width: {{ $fees_paid_pct }}%"></span>
                                </div>
                                <small class="dashboard-muted">{{ $fees_paid_pct }}% of this session&rsquo;s fees paid</small>
                            </div>
                        @endif

                        {{-- Fees-due strip — straight path into the fees portal. --}}
                        @if($child['fees']['due'] > 0)
                            <div class="alert alert-warning border-0 d-flex justify-content-between align-items-center flex-wrap mt-3 mb-0">
                                <span><i class="icon-warning2 mr-1"></i> <strong>Fees due:</strong> {{ \App\Helpers\Money::inr($child['fees']['due']) }} outstanding</span>
                                <a href="{{ route('my_fees.show', $record->user_id) }}" class="btn btn-warning btn-sm"><i class="icon-coins mr-1"></i> View Fees</a>
                            </div>
                        @elseif($child['fees']['has_records'])
                            <div class="alert alert-success border-0 mt-3 mb-0">
                                <i class="icon-checkmark3 mr-1"></i> <strong>Fees clear</strong> — nothing outstanding for {{ optional($record->user)->name }}.
                                <a href="{{ route('my_fees.show', $record->user_id) }}">View fee history</a>
                            </div>
                        @else
                            <div class="alert alert-info border-0 mt-3 mb-0">
                                <i class="icon-info22 mr-1"></i> No fee records yet for {{ optional($record->user)->name }}.
                                <a href="{{ route('my_fees.show', $record->user_id) }}">Open fees</a>
                            </div>
                        @endif

                        <div class="par-action-grid mt-3">
                            <a href="{{ route('students.show', Qs::hash($record->id)) }}"><i class="icon-eye"></i><span>View Profile</span></a>
                            <a href="{{ route('my_fees.show', $record->user_id) }}"><i class="icon-coins"></i><span>Fees</span></a>
                            <a href="{{ route('attendance.my', ['student_id' => $record->id]) }}"><i class="icon-calendar52"></i><span>Attendance</span></a>
                            <a href="{{ route('admit_cards.my') }}"><i class="icon-profile"></i><span>Admit Card</span></a>
                            <a href="{{ route('marks.year_selector', Qs::hash($record->user_id)) }}"><i class="icon-stats-bars"></i><span>Marksheet</span></a>
                        </div>
                    </section>
                </div>
            @endforeach
        </div>
    @else
        <section class="dashboard-panel mb-4">
            <div class="dashboard-empty">
                <h5 class="mb-1">No children are linked to your account yet</h5>
                <p class="dashboard-muted mb-0">Once the school links your child&rsquo;s record to your account, their attendance, marks and fee status will appear here. If your child is already enrolled, please contact the school office and mention your registered email address.</p>
            </div>
        </section>
    @endif

    <div class="row">
        {{-- Fees summary — the same per-child portal figures, totalled --}}
        <div class="col-xl-6">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-wallet text-blue mr-1"></i> Fees Summary</h5><p class="dashboard-muted mb-0">This session, per child</p></div>
                    <a href="{{ route('my_fees') }}" class="dashboard-view-all">Open Fees <i class="icon-arrow-right7"></i></a>
                </div>
                @if($children->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table dashboard-table mb-0">
                            <thead><tr><th>Child</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr></thead>
                            <tbody>
                            @foreach($children as $child)
                                <tr>
                                    <td><a href="{{ route('my_fees.show', $child['record']->user_id) }}">{{ optional($child['record']->user)->name }}</a></td>
                                    <td class="text-right">{{ \App\Helpers\Money::inr($child['fees']['total']) }}</td>
                                    <td class="text-right">{{ \App\Helpers\Money::inr($child['fees']['paid']) }}</td>
                                    <td class="text-right">{{ \App\Helpers\Money::inr($child['fees']['due']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="par-fees-total">
                                <td><strong>Total</strong></td>
                                <td class="text-right"><strong>{{ \App\Helpers\Money::inr($fees_total) }}</strong></td>
                                <td class="text-right"><strong>{{ \App\Helpers\Money::inr($fees_paid) }}</strong></td>
                                <td class="text-right"><strong>{{ \App\Helpers\Money::inr($fees_due) }}</strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="dashboard-empty">Fee summaries appear here once a child is linked to your account.</div>
                @endif
            </section>
        </div>

        {{-- Recent circulars addressed to parents --}}
        <div class="col-xl-6">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-megaphone text-blue mr-1"></i> Recent Circulars</h5><p class="dashboard-muted mb-0">Notices for parents from the school office</p></div>
                    <a href="{{ route('circulars.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($circulars as $circular)
                    <a href="{{ route('circulars.show', $circular->id) }}" class="par-circular">
                        <span class="par-circular-copy">
                            <strong>{{ $circular->title }}</strong>
                            <small>{{ \App\Models\Circular::audiences()[$circular->audience] ?? 'Everyone' }} &middot; {{ ($circular->starts_at ?: $circular->created_at)->format('d M Y') }}</small>
                        </span>
                        <span class="par-pill par-pill-{{ $circular->priority }}">{{ \App\Models\Circular::priorities()[$circular->priority] ?? 'Normal' }}</span>
                    </a>
                @empty
                    <div class="dashboard-empty">No circulars for parents right now. New notices from the school office will appear here.</div>
                @endforelse
            </section>
        </div>
    </div>

    <div class="row">
        {{-- Upcoming Events & Exams strip --}}
        <div class="col-xl-6">
            <section class="dashboard-panel dashboard-upcoming mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Upcoming Events &amp; Exams</h5><p class="dashboard-muted mb-0">School events and examinations</p></div>
                </div>
                @forelse($upcoming_events as $event)
                    <div class="dashboard-event">
                        <span class="event-date event-{{ $event['type'] }}"><small>{{ $event['month'] }}</small><strong>{{ $event['day'] }}</strong></span>
                        <span class="event-copy"><strong>{{ $event['title'] }}</strong><small>{{ $event['meta'] }}</small></span>
                        <span class="dashboard-status event-pill pill-{{ $event['type'] }}">{{ $event['type_label'] }}</span>
                    </div>
                @empty
                    <div class="dashboard-empty dashboard-event-empty">No upcoming events. School events and examination dates will appear here.</div>
                @endforelse
            </section>
        </div>

        {{-- Quick Links --}}
        <div class="col-xl-6">
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-rocket text-blue mr-1"></i> Quick Links</h5><p class="dashboard-muted mb-0">Jump to your pages.</p></div>
                </div>
                <div class="dashboard-action-grid">
                    <a href="{{ route('my_children') }}"><i class="icon-users4"></i><span>My Children</span></a>
                    <a href="{{ route('my_fees') }}"><i class="icon-coins"></i><span>Fees</span></a>
                    <a href="{{ route('attendance.my') }}"><i class="icon-calendar52"></i><span>Attendance</span></a>
                    <a href="{{ route('admit_cards.my') }}"><i class="icon-profile"></i><span>Admit Cards</span></a>
                    <a href="{{ route('circulars.index') }}"><i class="icon-megaphone"></i><span>Circulars</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Account</span></a>
                </div>
            </section>
        </div>
    </div>
@endsection
