@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 79 (Teacher dashboard redesign): welcome strip, real
         KPI cards, Today's Timetable + right rail (Quick Actions,
         Today's Tasks, Notifications), then this month's attendance
         charts. Every value comes from HomeController@teacherHomeData
         (Loop 79 block) — period status is read from submitted
         registers, never inferred from the clock. Styling is Loop 79
         CSS scoped to .t10-*.
         Audit Fix Wave 1: the KPI row is back to the reference's FOUR
         cards — the fifth card ("Assignments to Grade") duplicated the
         grading queue that Today's Tasks already lists; its count now
         rides in the Today's Tasks panel sub-line so the figure stays
         on the page in every state, including zero. --}}
    <div class="t10-welcome">
        <div class="t10-welcome-main">
            <h2>{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p>Here&rsquo;s your teaching overview for {{ $today->format('l, d F Y') }}. Keep up the great work!</p>
            <a href="{{ route('attendance.periods.index') }}" class="t10-take-btn"><i class="icon-calendar52"></i> Take Attendance</a>
        </div>
        <aside class="t10-quote">
            <i class="icon-quotes-left" aria-hidden="true"></i>
            <blockquote>&ldquo;Teaching is not just a profession, but a chance to make a difference every day.&rdquo;</blockquote>
        </aside>
    </div>

    {{-- KPI row — the reference's four cards, all real counts for this teacher. --}}
    <div class="t10-kpi-grid">
        <div class="t10-kpi t10-kpi-blue">
            <span class="t10-kpi-icon"><i class="icon-book"></i></span>
            <span class="t10-kpi-copy">
                <span class="t10-kpi-label">My Subjects</span>
                <strong>{{ number_format($my_subjects->count()) }}</strong>
                <small>Subjects you teach</small>
            </span>
        </div>
        <a href="{{ route('tt.mine') }}" class="t10-kpi t10-kpi-green">
            <span class="t10-kpi-icon"><i class="icon-windows2"></i></span>
            <span class="t10-kpi-copy">
                <span class="t10-kpi-label">My Classes</span>
                <strong>{{ number_format($my_assignment_count) }}</strong>
                <small>Class &amp; section assignments &middot; {{ $scope_class_count }} {{ $scope_class_count === 1 ? 'class' : 'classes' }}</small>
            </span>
            <i class="icon-arrow-right7 t10-kpi-chev"></i>
        </a>
        @if($first_class_id)
            <a href="{{ route('students.list', $first_class_id) }}" class="t10-kpi t10-kpi-orange">
                <span class="t10-kpi-icon"><i class="icon-users4"></i></span>
                <span class="t10-kpi-copy">
                    <span class="t10-kpi-label">Total Students</span>
                    <strong>{{ number_format($my_student_count) }}</strong>
                    <small>Across your assigned classes</small>
                </span>
                <i class="icon-arrow-right7 t10-kpi-chev"></i>
            </a>
        @else
            <div class="t10-kpi t10-kpi-orange">
                <span class="t10-kpi-icon"><i class="icon-users4"></i></span>
                <span class="t10-kpi-copy">
                    <span class="t10-kpi-label">Total Students</span>
                    <strong>{{ number_format($my_student_count) }}</strong>
                    <small>Across your assigned classes</small>
                </span>
            </div>
        @endif
        <a href="{{ route('attendance.periods.index') }}" class="t10-kpi t10-kpi-lavender">
            <span class="t10-kpi-icon"><i class="icon-watch"></i></span>
            <span class="t10-kpi-copy">
                <span class="t10-kpi-label">Today&rsquo;s Periods</span>
                <strong>{{ number_format($today_periods->count()) }}</strong>
                <small>{{ $today_submitted }} submitted &middot; {{ $today_periods->count() - $today_submitted }} to take</small>
            </span>
            <i class="icon-arrow-right7 t10-kpi-chev"></i>
        </a>
    </div>

    <div class="t10-main">
        {{-- Today's Timetable — this teacher's real periods for today's
             weekday; status from submitted registers (Loop 79). --}}
        <section class="t10-panel">
            <div class="t10-panel-head">
                <div class="t10-panel-title">
                    <span class="t10-panel-icon t10-tone-blue"><i class="icon-calendar3"></i></span>
                    <div>
                        <h5>Today&rsquo;s Timetable</h5>
                        <p class="t10-panel-sub">Your class timetable for today &middot; {{ $today_name }}, {{ $today->format('d F Y') }}</p>
                    </div>
                </div>
                <a href="{{ route('tt.index') }}" class="t10-view-all">View Full Timetable <i class="icon-arrow-right7"></i></a>
            </div>
            <div class="t10-table-wrap">
                <table class="t10-table">
                    <thead><tr><th>Time</th><th>Subject</th><th>Class &amp; Section</th><th>Room</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($today_periods as $row)
                        <tr>
                            <td class="t10-time">{{ $row['time_label'] }}</td>
                            <td><span class="t10-subject">{{ $row['subject_name'] }}</span></td>
                            <td>{{ $row['class_name'] }}@if(!empty($row['sections'])) &middot; {{ implode(', ', $row['sections']) }}@endif</td>
                            <td>—</td>
                            <td><span class="t10-status t10-status-{{ $row['status'] }}">{{ $row['status_label'] }}</span></td>
                            <td>
                                @if(!empty($row['session_id']))
                                    <a href="{{ route('attendance.periods.show', $row['session_id']) }}" class="t10-btn t10-btn-view">View</a>
                                @else
                                    <a href="{{ route('attendance.periods.take', $row['tt_id']) }}" class="t10-btn t10-btn-take">Take</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="t10-empty"><i class="icon-calendar3"></i> No classes scheduled for today. <a href="{{ route('tt.index') }}">View full timetable</a></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Right rail: Quick Actions, Today's Tasks, Notifications --}}
        <div class="t10-rail">
            <section class="t10-panel">
                <div class="t10-panel-head">
                    <div class="t10-panel-title">
                        <span class="t10-panel-icon t10-tone-amber"><i class="icon-rocket"></i></span>
                        <div>
                            <h5>Quick Actions</h5>
                            <p class="t10-panel-sub">Perform your everyday teaching tasks quickly.</p>
                        </div>
                    </div>
                </div>
                <div class="t10-tiles">
                    <a href="{{ route('attendance.periods.index') }}" class="t10-tile t10-tile-blue">
                        <span class="t10-tile-top"><span class="t10-tile-icon"><i class="icon-calendar52"></i></span><i class="icon-arrow-right7 t10-tile-chev"></i></span>
                        <strong class="t10-tile-title">Take Attendance</strong>
                        <small class="t10-tile-sub">Mark a period register</small>
                    </a>
                    <a href="{{ route('marks.index') }}" class="t10-tile t10-tile-green">
                        <span class="t10-tile-top"><span class="t10-tile-icon"><i class="icon-pencil7"></i></span><i class="icon-arrow-right7 t10-tile-chev"></i></span>
                        <strong class="t10-tile-title">Enter Marks</strong>
                        <small class="t10-tile-sub">Record exam marks</small>
                    </a>
                    <a href="{{ route('tt.index') }}" class="t10-tile t10-tile-lavender">
                        <span class="t10-tile-top"><span class="t10-tile-icon"><i class="icon-calendar3"></i></span><i class="icon-arrow-right7 t10-tile-chev"></i></span>
                        <strong class="t10-tile-title">View Timetable</strong>
                        <small class="t10-tile-sub">Check your class schedule</small>
                    </a>
                    <a href="{{ $first_class_id ? route('students.list', $first_class_id) : route('students.index') }}" class="t10-tile t10-tile-peach">
                        <span class="t10-tile-top"><span class="t10-tile-icon"><i class="icon-users4"></i></span><i class="icon-arrow-right7 t10-tile-chev"></i></span>
                        <strong class="t10-tile-title">Manage Students</strong>
                        <small class="t10-tile-sub">View and manage student profiles</small>
                    </a>
                </div>
            </section>

            {{-- Today's Tasks — derived pending work only; no stored
                 state, no checkboxes. A row disappears when the work
                 is actually done. --}}
            <section class="t10-panel">
                <div class="t10-panel-head">
                    <div class="t10-panel-title">
                        <span class="t10-panel-icon t10-tone-red"><i class="icon-alarm"></i></span>
                        <div>
                            <h5>Today&rsquo;s Tasks</h5>
                            {{-- Audit Fix Wave 1: the grading-queue count
                                 folded here from the removed fifth KPI
                                 card — always on the page, even at zero
                                 (the task row below also carries it
                                 whenever it is above zero). --}}
                            <p class="t10-panel-sub">Pending work, from your live records &middot; <a href="{{ route('assignments.index') }}">{{ number_format($lms_to_grade ?? 0) }} {{ ($lms_to_grade ?? 0) === 1 ? 'assignment' : 'assignments' }} to grade</a></p>
                        </div>
                    </div>
                </div>
                @forelse($today_tasks as $task)
                    <a href="{{ $task['link'] }}" class="t10-row">
                        <span class="t10-row-icon t10-tone-{{ $task['tone'] }}"><i class="{{ $task['icon'] }}"></i></span>
                        <span class="t10-row-copy">
                            <strong>{{ $task['title'] }}</strong>
                            <small>{{ $task['meta'] }}</small>
                        </span>
                        <span class="t10-badge t10-badge-{{ $task['badge_tone'] }}">{{ $task['badge'] }}</span>
                    </a>
                @empty
                    <div class="t10-caught-up"><i class="icon-checkmark3"></i> You&rsquo;re all caught up — no pending tasks right now.</div>
                @endforelse
            </section>

            {{-- Notifications — composed from real sources (Loop 79):
                 low-attendance position, grading queue, circulars. --}}
            <section class="t10-panel">
                <div class="t10-panel-head">
                    <div class="t10-panel-title">
                        <span class="t10-panel-icon t10-tone-blue"><i class="icon-bell2"></i></span>
                        <div>
                            <h5>Notifications</h5>
                            <p class="t10-panel-sub">Latest updates for you</p>
                        </div>
                    </div>
                    <a href="{{ route('circulars.index') }}" class="t10-view-all">All Circulars <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($teacher_notifications as $note)
                    <a href="{{ $note['link'] }}" class="t10-row">
                        <span class="t10-row-icon t10-tone-{{ $note['tone'] }}"><i class="{{ $note['icon'] }}"></i></span>
                        <span class="t10-row-copy">
                            <strong>{{ $note['title'] }}</strong>
                            <small>{{ $note['meta'] }}</small>
                        </span>
                        @if($note['time_label'])
                            <span class="t10-row-date">{{ $note['time_label'] }}</span>
                        @endif
                    </a>
                @empty
                    <div class="t10-caught-up"><i class="icon-bell2"></i> No notifications right now.</div>
                @endforelse
            </section>
        </div>
    </div>

    {{-- Charts row — this month's attendance, same period and the
         same (present + late) / marked rule for both panels. --}}
    <div class="t10-charts">
        <section class="t10-panel">
            <div class="t10-panel-head">
                <div class="t10-panel-title">
                    <span class="t10-panel-icon t10-tone-green"><i class="icon-stats-bars"></i></span>
                    <div>
                        <h5>Attendance Overview <span class="t10-head-note">(This Month)</span></h5>
                        <p class="t10-panel-sub">Attendance % by class &middot; {{ $month_label }}</p>
                    </div>
                </div>
                <a href="{{ route('attendance.periods.report') }}" class="t10-view-all">View Report <i class="icon-arrow-right7"></i></a>
            </div>
            @php $chart_rows = $attendance_overview->filter(function ($r) { return $r['pct'] !== null; }); @endphp
            @include('partials.charts.bar', [
                'labels' => $chart_rows->pluck('class_name')->all(),
                'values' => $chart_rows->pluck('pct')->all(),
                'chartUnit' => '%',
                'chartTitle' => 'Attendance percentage by class for ' . $month_label,
            ])
            @foreach($attendance_overview as $row)
                @if($row['pct'] === null)
                    <p class="t10-note">{{ $row['class_name'] }} — no marked periods yet in {{ $month_label }}.</p>
                @endif
            @endforeach
            <p class="t10-note">Attendance % = (present + late) &divide; marked periods, from submitted registers.</p>
        </section>

        <section class="t10-panel">
            <div class="t10-panel-head">
                <div class="t10-panel-title">
                    <span class="t10-panel-icon t10-tone-lavender"><i class="icon-pie-chart"></i></span>
                    <div>
                        <h5>Student Attendance Status</h5>
                        <p class="t10-panel-sub">Distribution across your students &middot; {{ $month_label }}</p>
                    </div>
                </div>
                <a href="{{ route('attendance.defaulters') }}" class="t10-view-all">View Details <i class="icon-arrow-right7"></i></a>
            </div>
            @php
                $dist_total = (int) $attendance_distribution['with_records'];
                $circ = 2 * pi() * 42;
                $acc = 0.0;
            @endphp
            @if($dist_total > 0)
                <div class="t10-donut-wrap">
                    <svg class="t10-donut" viewBox="0 0 120 120" width="132" height="132" role="img" aria-label="Student attendance distribution for {{ $month_label }}">
                        <circle class="t10-donut-track" cx="60" cy="60" r="42" fill="none" stroke-width="15"></circle>
                        @foreach($attendance_distribution['buckets'] as $bucket)
                            @if($bucket['count'] > 0)
                                @php
                                    $seg = ($bucket['count'] / $dist_total) * $circ;
                                    $seg_offset = -$acc;
                                    $acc += $seg;
                                @endphp
                                <circle cx="60" cy="60" r="42" fill="none" stroke="{{ $bucket['color'] }}" stroke-width="15"
                                        stroke-dasharray="{{ round($seg, 2) }} {{ round($circ, 2) }}"
                                        stroke-dashoffset="{{ round($seg_offset, 2) }}"
                                        transform="rotate(-90 60 60)">
                                    <title>{{ $bucket['label'] }}: {{ $bucket['count'] }}</title>
                                </circle>
                            @endif
                        @endforeach
                        <text x="60" y="58" text-anchor="middle" class="t10-donut-num">{{ $dist_total }}</text>
                        <text x="60" y="74" text-anchor="middle" class="t10-donut-cap">Students</text>
                    </svg>
                    <ul class="t10-legend">
                        @foreach($attendance_distribution['buckets'] as $bucket)
                            <li>
                                <span class="t10-legend-dot" style="background:{{ $bucket['color'] }}"></span>
                                <span class="t10-legend-label">{{ $bucket['label'] }}</span>
                                <strong>{{ $bucket['count'] }}</strong>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <p class="t10-note">Based on {{ $dist_total }} of {{ $attendance_distribution['total_students'] }} students with marked periods in {{ $month_label }}; each student counted once, by their overall percentage.</p>
            @else
                <div class="t10-caught-up"><i class="icon-pie-chart"></i> No attendance records for your classes in {{ $month_label }} yet.</div>
            @endif
        </section>
    </div>
@endsection
