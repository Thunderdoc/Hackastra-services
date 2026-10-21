@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 7 (Workstream A): teacher home — own subjects, classes and today. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Here&rsquo;s your teaching day at a glance.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                    <small>Current Session: {{ $current_session ?: '—' }}</small>
                </span>
            </div>
            <a href="{{ route('attendance.index') }}" class="btn dashboard-primary-action">
                <i class="icon-calendar52 mr-2"></i> Take Attendance
            </a>
        </div>
    </div>

    {{-- Own teaching summary — real assignments only --}}
    <div class="row dashboard-stats">
        @foreach([
            ['label' => 'My Subjects', 'value' => $my_subjects->count(), 'sub' => 'Subjects you teach', 'icon' => 'icon-book', 'tone' => 'blue'],
            ['label' => 'My Classes', 'value' => $my_class_count, 'sub' => 'Classes you teach in', 'icon' => 'icon-windows2', 'tone' => 'green'],
            ['label' => 'Students I Teach', 'value' => $my_student_count, 'sub' => 'Across your classes', 'icon' => 'icon-users4', 'tone' => 'orange'],
            ['label' => 'Sections I Lead', 'value' => $sections_led->count(), 'sub' => 'As class teacher', 'icon' => 'icon-user-tie', 'tone' => 'purple'],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                    <span class="dashboard-stat-top">
                        <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                    </span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                        <strong>{{ number_format($stat['value']) }}</strong>
                        <small>{{ $stat['sub'] }}</small>
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Today's periods from the real class timetables --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Today&rsquo;s Periods</h5><p class="dashboard-muted mb-0">{{ $today_name }} &middot; from your class timetables</p></div>
                    <a href="{{ route('tt.index') }}" class="dashboard-view-all">Timetables <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Time</th><th>Subject</th><th>Class</th></tr></thead>
                        <tbody>
                        @forelse($today_periods as $period)
                            <tr>
                                <td>{{ optional($period->time_slot)->full ?: '—' }}</td>
                                <td><strong>{{ optional($period->subject)->name ?: '—' }}</strong></td>
                                <td>{{ optional(optional($period->tt_record)->my_class)->name ?: optional(optional($period->subject)->my_class)->name ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="dashboard-empty">No periods are scheduled for you today.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Subjects & classes assigned to this teacher --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-book text-blue mr-1"></i> My Subjects</h5><p class="dashboard-muted mb-0">Subjects and classes assigned to you</p></div>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Subject</th><th>Class</th></tr></thead>
                        <tbody>
                        @forelse($my_subjects as $subject)
                            <tr>
                                <td><strong>{{ $subject->name }}</strong></td>
                                <td>{{ optional($subject->my_class)->name ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="dashboard-empty">No subjects have been assigned to you yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($sections_led->isNotEmpty())
                    <p class="dashboard-muted mb-0 mt-2">Class teacher for:
                        @foreach($sections_led as $section)
                            <strong>{{ optional($section->my_class)->name }} {{ $section->name }}</strong>@unless($loop->last), @endunless
                        @endforeach
                    </p>
                @endif
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Quick Actions --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Your everyday teaching tasks.</p></div>
                    <span class="dashboard-heading-icon"><i class="icon-arrow-right7"></i></span>
                </div>
                <div class="dashboard-action-grid">
                    <a href="{{ route('attendance.index') }}"><i class="icon-calendar52"></i><span>Take Attendance</span></a>
                    <a href="{{ route('marks.index') }}"><i class="icon-stats-bars"></i><span>Enter Marks</span></a>
                    <a href="{{ route('tt.index') }}"><i class="icon-calendar3"></i><span>My Timetable</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Account</span></a>
                </div>
            </section>

            {{-- Upcoming Events — same real events as the main dashboard --}}
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
