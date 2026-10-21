@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 7 (Workstream A): parent home — one card per linked child. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">
                @if($children->isNotEmpty())
                    {{ $children->count() }} {{ $children->count() === 1 ? 'child' : 'children' }} linked to your account — attendance and fees at a glance.
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
            <a href="{{ route('my_children') }}" class="btn dashboard-primary-action">
                <i class="icon-users4 mr-2"></i> My Children
            </a>
        </div>
    </div>

    @if($children->isNotEmpty())
        <div class="row">
            @foreach($children as $child)
                @php($record = $child['record'])
                <div class="col-xl-6">
                    <section class="dashboard-panel mb-4">
                        <div class="dashboard-panel-heading">
                            <div>
                                <h5 class="mb-1"><i class="icon-user text-blue mr-1"></i> {{ optional($record->user)->name }}</h5>
                                <p class="dashboard-muted mb-0">
                                    {{ optional($record->my_class)->name ?: 'Class —' }} &middot; {{ optional($record->section)->name ?: 'Section —' }}
                                    @if($record->adm_no) &middot; Adm No: {{ $record->adm_no }} @endif
                                </p>
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
                                                <small class="dashboard-muted">of {{ \App\Helpers\Money::inr($child['fees']['owed']) }}</small>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Fees Pending</strong></td>
                                        <td>{{ \App\Helpers\Money::inr($child['fees']['pending']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {{-- Loop 9 (Workstream O): fees-due strip — same figures as the Fees rows above, with a straight path into the fees portal. --}}
                        @if($child['fees']['pending'] > 0)
                            <div class="alert alert-warning border-0 d-flex justify-content-between align-items-center flex-wrap mt-3 mb-0">
                                <span><i class="icon-warning2 mr-1"></i> <strong>Fees due:</strong> {{ \App\Helpers\Money::inr($child['fees']['pending']) }} outstanding</span>
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

                        <div class="dashboard-action-grid mt-3">
                            <a href="{{ route('students.show', Qs::hash($record->id)) }}"><i class="icon-eye"></i><span>View Profile</span></a>
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
        {{-- Upcoming Events strip --}}
        <div class="col-xl-6">
            <section class="dashboard-panel dashboard-upcoming mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Upcoming Events</h5><p class="dashboard-muted mb-0">School events and examinations</p></div>
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

        {{-- Quick Links --}}
        <div class="col-xl-6">
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Links</h5><p class="dashboard-muted mb-0">Jump to your pages.</p></div>
                    <span class="dashboard-heading-icon"><i class="icon-arrow-right7"></i></span>
                </div>
                <div class="dashboard-action-grid">
                    <a href="{{ route('my_children') }}"><i class="icon-users4"></i><span>My Children</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Account</span></a>
                </div>
            </section>
        </div>
    </div>
@endsection
