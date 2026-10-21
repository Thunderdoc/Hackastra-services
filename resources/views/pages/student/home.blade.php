@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 7 (Workstream A): student home — own attendance, marks and fees. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">
                @if($student_record)
                    {{ optional($student_record->my_class)->name ?: 'Class —' }} &middot; {{ optional($student_record->section)->name ?: 'Section —' }} &middot; Session {{ $current_session ?: '—' }}
                @else
                    Here&rsquo;s your school day at a glance.
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
            <a href="{{ route('marks.year_selector', Qs::hash(Auth::id())) }}" class="btn dashboard-primary-action">
                <i class="icon-stats-bars mr-2"></i> View My Marks
            </a>
        </div>
    </div>

    {{-- Own summary — every number comes from this student's records --}}
    <div class="row dashboard-stats">
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-blue">
                <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar52"></i></span></span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">My Attendance</span>
                    <strong>{{ $attendance['percent'] !== null ? $attendance['percent'] . '%' : '—' }}</strong>
                    <small>{{ $attendance['total'] > 0 ? 'Present or late, of ' . $attendance['total'] . ' marked days' : 'No attendance recorded yet' }}</small>
                </span>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-green">
                <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-check"></i></span></span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Days Present</span>
                    <strong>{{ number_format($attendance['present'] + $attendance['late']) }}</strong>
                    <small>{{ number_format($attendance['absent']) }} absent &middot; {{ number_format($attendance['late']) }} late</small>
                </span>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-orange">
                <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-wallet"></i></span></span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Fees Paid</span>
                    <strong>{{ \App\Helpers\Money::inr($fees['paid']) }}</strong>
                    <small>{{ $fees['has_records'] ? $fees['percent'] . '% of ' . \App\Helpers\Money::inr($fees['owed']) . ' total fees' : 'No fee records yet' }}</small>
                </span>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-purple">
                <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-wallet"></i></span></span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Fees Pending</span>
                    <strong>{{ \App\Helpers\Money::inr($fees['pending']) }}</strong>
                    <small>{{ $fees['pending_records'] > 0 ? $fees['pending_records'] . ' payment(s) outstanding' : 'Nothing outstanding' }}</small>
                </span>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Latest marks — the most recent exam that has marks for this student --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-book text-blue mr-1"></i> Latest Marks</h5>
                        <p class="dashboard-muted mb-0">{{ $latest_exam ? $latest_exam->name : 'No exam results yet' }}</p>
                    </div>
                    <a href="{{ route('marks.year_selector', Qs::hash(Auth::id())) }}" class="dashboard-view-all">Full Marksheet <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Subject</th><th>Tests</th><th>Exam</th><th>Total</th><th>Grade</th></tr></thead>
                        <tbody>
                        @forelse($latest_marks as $mark)
                            <tr>
                                <td><strong>{{ optional($mark->subject)->name ?: '—' }}</strong></td>
                                <td>{{ $mark->tca ?? '—' }}</td>
                                <td>{{ $mark->exm ?? '—' }}</td>
                                <td><strong>{{ ($mark->tca ?? 0) + ($mark->exm ?? 0) }}</strong></td>
                                <td>{{ optional($mark->grade)->name ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="dashboard-empty">No marks have been published for you yet. They will appear here after your next exam.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Quick Links --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Links</h5><p class="dashboard-muted mb-0">Jump to your pages.</p></div>
                    <span class="dashboard-heading-icon"><i class="icon-arrow-right7"></i></span>
                </div>
                <div class="dashboard-action-grid">
                    <a href="{{ route('marks.year_selector', Qs::hash(Auth::id())) }}"><i class="icon-stats-bars"></i><span>My Marks</span></a>
                    <a href="{{ route('tt.index') }}"><i class="icon-calendar3"></i><span>My Timetable</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Account</span></a>
                </div>
            </section>

            {{-- Upcoming Events --}}
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
