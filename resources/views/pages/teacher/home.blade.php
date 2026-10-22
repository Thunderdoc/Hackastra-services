@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Batch 9 (Workstream AD): teacher home redesigned to the owner's
         reference — greeting, four KPI cards, Today's Timetable beside
         Quick Actions, then Upcoming Tasks + Recent Circulars. Every
         value is real data from HomeController@teacherHomeData; all
         styling is Loop 19 CSS scoped to .portal-teacher / .t9-*. --}}
    <div class="t9-greeting">
        <h2>{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
        <p>Here&rsquo;s your teaching overview for today. Keep up the great work!</p>
    </div>

    {{-- KPI row — exactly four cards, real counts. A card is a link only
         where a real destination exists (reference design). --}}
    <div class="t9-kpi-grid">
        <div class="t9-kpi t9-kpi-blue">
            <span class="t9-kpi-icon"><i class="icon-book"></i></span>
            <span class="t9-kpi-copy">
                <span class="t9-kpi-label">My Subjects</span>
                <strong>{{ number_format($my_subjects->count()) }}</strong>
                <small>Subjects you teach</small>
            </span>
        </div>
        <div class="t9-kpi t9-kpi-green">
            <span class="t9-kpi-icon"><i class="icon-windows2"></i></span>
            <span class="t9-kpi-copy">
                <span class="t9-kpi-label">My Classes</span>
                <strong>{{ number_format($my_class_count) }}</strong>
                <small>Classes you teach in</small>
            </span>
        </div>
        @if($first_class_id)
            <a href="{{ route('students.list', $first_class_id) }}" class="t9-kpi t9-kpi-orange">
                <span class="t9-kpi-icon"><i class="icon-users4"></i></span>
                <span class="t9-kpi-copy">
                    <span class="t9-kpi-label">Total Students</span>
                    <strong>{{ number_format($my_student_count) }}</strong>
                    <small>Across your classes</small>
                </span>
                <i class="icon-arrow-right7 t9-kpi-chev"></i>
            </a>
        @else
            <div class="t9-kpi t9-kpi-orange">
                <span class="t9-kpi-icon"><i class="icon-users4"></i></span>
                <span class="t9-kpi-copy">
                    <span class="t9-kpi-label">Total Students</span>
                    <strong>{{ number_format($my_student_count) }}</strong>
                    <small>Across your classes</small>
                </span>
            </div>
        @endif
        <a href="{{ route('attendance.periods.index') }}" class="t9-kpi t9-kpi-lavender">
            <span class="t9-kpi-icon"><i class="icon-watch"></i></span>
            <span class="t9-kpi-copy">
                <span class="t9-kpi-label">Today&rsquo;s Periods</span>
                <strong>{{ number_format($today_periods->count()) }}</strong>
                <small>Scheduled for today</small>
            </span>
            <i class="icon-arrow-right7 t9-kpi-chev"></i>
        </a>
        {{-- Workstream CZ (LMS): the grading queue as a KPI card —
             ungraded submissions across this teacher's assignments. --}}
        <a href="{{ route('assignments.index') }}" class="t9-kpi t9-kpi-purple">
            <span class="t9-kpi-icon"><i class="icon-file-text2"></i></span>
            <span class="t9-kpi-copy">
                <span class="t9-kpi-label">Assignments to Grade</span>
                <strong>{{ number_format($lms_to_grade ?? 0) }}</strong>
                <small>Ungraded submissions</small>
            </span>
            <i class="icon-arrow-right7 t9-kpi-chev"></i>
        </a>
    </div>

    <div class="t9-main">
        {{-- Today's Timetable — real periods for today's weekday, with
             live status from the slot times (teacherHomeData). --}}
        <section class="t9-panel">
            <div class="t9-panel-head">
                <div class="t9-panel-title">
                    <span class="t9-panel-icon t9-tone-blue"><i class="icon-calendar3"></i></span>
                    <div>
                        <h5>Today&rsquo;s Timetable</h5>
                        <p>Your class timetable for today &middot; {{ $today_name }}</p>
                    </div>
                </div>
                <a href="{{ route('tt.index') }}" class="t9-view-all">View Full Timetable <i class="icon-arrow-right7"></i></a>
            </div>
            <div class="table-responsive">
                <table class="t9-table">
                    <thead><tr><th>Time</th><th>Subject</th><th>Class &amp; Section</th><th>Room</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($today_periods as $row)
                        <tr>
                            <td class="t9-time">{{ $row['time_label'] }}</td>
                            <td><strong>{{ $row['subject_name'] }}</strong></td>
                            <td>{{ $row['class_name'] }}@if(!empty($row['sections'])) &middot; {{ implode(', ', $row['sections']) }}@endif</td>
                            <td>—</td>
                            <td><span class="dashboard-status period-status period-status-{{ $row['status'] }}">{{ $row['status_label'] }}</span></td>
                            <td>
                                @if(!empty($row['session_id']))
                                    <a href="{{ route('attendance.periods.show', $row['session_id']) }}" class="t9-view-link">View</a>
                                @else
                                    <a href="{{ route('attendance.periods.take', $row['tt_id']) }}" class="t9-view-link">View</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="t9-empty"><i class="icon-calendar3"></i> No periods scheduled for today</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Quick Actions — four tiles, each a real teacher workflow --}}
        <section class="t9-panel">
            <div class="t9-panel-head">
                <div class="t9-panel-title">
                    <span class="t9-panel-icon t9-tone-amber"><i class="icon-rocket"></i></span>
                    <div>
                        <h5>Quick Actions</h5>
                        <p>Perform your everyday teaching tasks quickly.</p>
                    </div>
                </div>
            </div>
            <div class="t9-tiles">
                <a href="{{ route('attendance.periods.index') }}" class="t9-tile t9-tile-blue">
                    <span class="t9-tile-top"><span class="t9-tile-icon"><i class="icon-calendar52"></i></span><i class="icon-arrow-right7 t9-tile-chev"></i></span>
                    <strong class="t9-tile-title">Take Attendance</strong>
                    <small class="t9-tile-sub">Mark a period register</small>
                </a>
                <a href="{{ route('marks.index') }}" class="t9-tile t9-tile-green">
                    <span class="t9-tile-top"><span class="t9-tile-icon"><i class="icon-pencil7"></i></span><i class="icon-arrow-right7 t9-tile-chev"></i></span>
                    <strong class="t9-tile-title">Enter Marks</strong>
                    <small class="t9-tile-sub">Record exam marks</small>
                </a>
                <a href="{{ route('tt.index') }}" class="t9-tile t9-tile-lavender">
                    <span class="t9-tile-top"><span class="t9-tile-icon"><i class="icon-calendar3"></i></span><i class="icon-arrow-right7 t9-tile-chev"></i></span>
                    <strong class="t9-tile-title">View Timetable</strong>
                    <small class="t9-tile-sub">Check your schedule</small>
                </a>
                <a href="{{ $first_class_id ? route('students.list', $first_class_id) : route('students.index') }}" class="t9-tile t9-tile-peach">
                    <span class="t9-tile-top"><span class="t9-tile-icon"><i class="icon-users4"></i></span><i class="icon-arrow-right7 t9-tile-chev"></i></span>
                    <strong class="t9-tile-title">Manage Students</strong>
                    <small class="t9-tile-sub">View and manage student profiles</small>
                </a>
            </div>
        </section>
    </div>

    <div class="t9-bottom">
        {{-- Upcoming Tasks / Important Reminders — derived in the
             controller from real records only (unsubmitted registers,
             tomorrow's periods, dated exam sittings). --}}
        <section class="t9-panel">
            <div class="t9-panel-head">
                <div class="t9-panel-title">
                    <span class="t9-panel-icon t9-tone-red"><i class="icon-bell2"></i></span>
                    <div>
                        <h5>Upcoming Tasks / Important Reminders</h5>
                        <p>Derived from your real timetable, registers and exams.</p>
                    </div>
                </div>
            </div>
            @forelse($t9_tasks as $task)
                <a href="{{ $task['link'] }}" class="t9-row">
                    <span class="t9-row-icon t9-tone-{{ $task['tone'] }}"><i class="{{ $task['icon'] }}"></i></span>
                    <span class="t9-row-copy">
                        <strong>{{ $task['title'] }}</strong>
                        <small>{{ $task['meta'] }}</small>
                    </span>
                    <span class="t9-badge t9-badge-{{ $task['badge_tone'] }}">{{ $task['badge'] }}</span>
                    <span class="t9-row-date">{{ $task['date_label'] }}</span>
                </a>
            @empty
                <div class="t9-caught-up"><i class="icon-checkmark3"></i> You&rsquo;re all caught up — no pending tasks right now.</div>
            @endforelse
        </section>

        {{-- Recent Circulars — latest four from the teacher-visible feed --}}
        <section class="t9-panel">
            <div class="t9-panel-head">
                <div class="t9-panel-title">
                    <span class="t9-panel-icon t9-tone-red"><i class="icon-megaphone"></i></span>
                    <div>
                        <h5>Recent Circulars</h5>
                        <p>Latest announcements for you</p>
                    </div>
                </div>
                <a href="{{ route('circulars.index') }}" class="t9-view-all">View All <i class="icon-arrow-right7"></i></a>
            </div>
            @forelse($recent_circulars as $circular)
                <a href="{{ route('circulars.show', $circular->id) }}" class="t9-row">
                    <span class="t9-row-icon t9-tone-{{ $circular->priority === 'urgent' ? 'red' : ($circular->priority === 'important' ? 'amber' : 'blue') }}"><i class="icon-megaphone"></i></span>
                    <span class="t9-row-copy">
                        <strong>{{ $circular->title }}</strong>
                        <small>{{ \App\Models\Circular::audiences()[$circular->audience] ?? ucfirst($circular->audience) }} &middot; by {{ optional($circular->publisher)->name ?: 'School Office' }}</small>
                    </span>
                    <span class="t9-badge t9-badge-{{ $circular->priority === 'urgent' ? 'red' : ($circular->priority === 'important' ? 'amber' : 'blue') }}">{{ \App\Models\Circular::priorities()[$circular->priority] ?? ucfirst($circular->priority) }}</span>
                    <span class="t9-row-date">{{ optional($circular->starts_at)->format('d M Y') ?: $circular->created_at->format('d M Y') }}</span>
                </a>
            @empty
                <div class="t9-caught-up"><i class="icon-megaphone"></i> No circulars for you right now.</div>
            @endforelse
        </section>
    </div>
@endsection
