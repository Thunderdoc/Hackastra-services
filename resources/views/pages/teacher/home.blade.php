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
            {{-- Take Attendance renders ONCE on this page — the top-right
                 button in the subheader strip (partials/header). The hero
                 copy was removed 2026-10-29 night: the owner flagged the
                 same button appearing twice on the teacher dashboard. --}}
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
            </span>
        </div>
        <a href="{{ route('tt.mine') }}" class="t10-kpi t10-kpi-green">
            <span class="t10-kpi-icon"><i class="icon-windows2"></i></span>
            <span class="t10-kpi-copy">
                <span class="t10-kpi-label">My Assignments</span>
                <strong>{{ number_format($my_assignment_count) }}</strong>
            </span>
            <i class="icon-arrow-right7 t10-kpi-chev"></i>
        </a>
        @if($first_class_id)
            <a href="{{ route('students.list', $first_class_id) }}" class="t10-kpi t10-kpi-orange">
                <span class="t10-kpi-icon"><i class="icon-users4"></i></span>
                <span class="t10-kpi-copy">
                    <span class="t10-kpi-label">Total Students</span>
                    <strong>{{ number_format($my_student_count) }}</strong>
                </span>
                <i class="icon-arrow-right7 t10-kpi-chev"></i>
            </a>
        @else
            <div class="t10-kpi t10-kpi-orange">
                <span class="t10-kpi-icon"><i class="icon-users4"></i></span>
                <span class="t10-kpi-copy">
                    <span class="t10-kpi-label">Total Students</span>
                    <strong>{{ number_format($my_student_count) }}</strong>
                </span>
            </div>
        @endif
        <a href="{{ route('attendance.periods.index') }}" class="t10-kpi t10-kpi-lavender">
            <span class="t10-kpi-icon"><i class="icon-watch"></i></span>
            <span class="t10-kpi-copy">
                <span class="t10-kpi-label">Today&rsquo;s Periods</span>
                <strong>{{ number_format($today_periods->count()) }}</strong>
            </span>
            <i class="icon-arrow-right7 t10-kpi-chev"></i>
        </a>
    </div>

    @if($untaken_periods_today->count() > 0 || $ungraded_assignments->count() > 0)
        <section class="t10-panel" style="margin-bottom: 16px; border-left: 4px solid #d64545;">
            <div class="t10-panel-head">
                <div class="t10-panel-title">
                    <span class="t10-panel-icon" style="background: #fdecec; color: #d64545;"><i class="icon-alarm"></i></span>
                    <div>
                        <h5>Needs attention</h5>
                        <p class="t10-panel-sub">Work still open for you today — nothing here is estimated.</p>
                    </div>
                </div>
            </div>
            <div style="padding: 0 16px 16px;">
                @if($untaken_periods_today->count() > 0)
                    <p style="margin: 0 0 8px;"><strong>{{ number_format($untaken_periods_today->count()) }}</strong> {{ $untaken_periods_today->count() === 1 ? 'period' : 'periods' }} today still {{ $untaken_periods_today->count() === 1 ? 'needs' : 'need' }} attendance
                    — untaken periods: {{ $untaken_periods_today->map(function ($row) { return $row['subject_name'] . ' (' . $row['class_name'] . ')'; })->implode(', ') }}.
                    <a href="{{ route('attendance.periods.index') }}">Take attendance</a></p>
                @endif
                @if($ungraded_assignments->count() > 0)
                    <p style="margin: 0;">{{ number_format($ungraded_assignments->count()) }} {{ $ungraded_assignments->count() === 1 ? 'assignment has' : 'assignments have' }} ungraded submissions
                    ({{ number_format($ungraded_assignments->sum('ungraded_count')) }} {{ $ungraded_assignments->sum('ungraded_count') === 1 ? 'submission' : 'submissions' }} awaiting marks):
                    {{ $ungraded_assignments->take(5)->map(function ($assignment) { return $assignment->title . ' (' . $assignment->ungraded_count . ')'; })->implode(', ') }}.
                    <a href="{{ route('assignments.index') }}">Grade submissions</a></p>
                @endif
            </div>
        </section>
    @endif

    <div class="t10-main">
        <div class="t10-leftcol">
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
                            <td>{{ $row['class_name'] }}@if(!empty($row['section_name'])) &middot; {{ $row['section_name'] }}@elseif(!empty($row['sections'])) &middot; {{ implode(', ', $row['sections']) }}@endif</td>
                            <td>—</td>
                            <td><span class="t10-status t10-status-{{ $row['status'] }}">{{ $row['status_label'] }}</span></td>
                            <td>
                                @if(!empty($row['session_id']))
                                    <span class="text-muted">Submitted</span>
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

        {{-- Wave 13 (HR-lite): the teacher's own leave requests —
             status only, no invented balance. --}}
        <section class="t10-panel">
            <div class="t10-panel-head">
                <div class="t10-panel-title">
                    <span class="t10-panel-icon t10-tone-blue"><i class="icon-calendar2"></i></span>
                    <div>
                        <h5>My Leave Requests</h5>
                        <p class="t10-panel-sub">Your recent requests and their status</p>
                    </div>
                </div>
                <a href="{{ route('leave.my') }}" class="t10-view-all">Apply / View All <i class="icon-arrow-right7"></i></a>
            </div>
            <div class="t10-table-wrap">
                <table class="t10-table">
                    <tbody>
                    @forelse($my_leave_requests as $lr)
                        <tr>
                            <td>{{ $lr->start_date }} → {{ $lr->end_date }} ({{ $lr->days }} day{{ $lr->days === 1 ? '' : 's' }})</td>
                            <td>{{ \Illuminate\Support\Str::limit($lr->reason, 50) }}</td>
                            <td><span class="t10-status t10-status-{{ $lr->status === 'approved' ? 'completed' : ($lr->status === 'rejected' ? 'rejected' : 'pending') }}">{{ ucfirst($lr->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="t10-empty"><i class="icon-calendar2"></i> No leave requests yet. <a href="{{ route('leave.my') }}">Apply for leave</a></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Batch fix 2026-10-25: duties were invisible to teachers.
             Upcoming duties panel, same pattern as My Leave above. --}}
        <section class="t10-panel">
            <div class="t10-panel-head">
                <div class="t10-panel-title">
                    <span class="t10-panel-icon t10-tone-blue"><i class="icon-clipboard3"></i></span>
                    <div>
                        <h5>My Invigilation Duties</h5>
                        <p class="t10-panel-sub">Your upcoming exam duties</p>
                    </div>
                </div>
                <a href="{{ route('exam_duties.my') }}" class="t10-view-all">View All <i class="icon-arrow-right7"></i></a>
            </div>
            <div class="t10-table-wrap">
                <table class="t10-table">
                    <tbody>
                    @forelse($my_duties as $duty)
                        <tr>
                            <td>{{ $duty->exam_date->exam_date ?? '—' }} · {{ substr((string) ($duty->exam_date->start_time ?? ''), 0, 5) ?: '—' }}</td>
                            <td>{{ optional($duty->exam_date->my_class)->name ?? '—' }} — {{ optional($duty->exam_date->subject)->name ?? '—' }}</td>
                            <td>{{ optional($duty->exam_date->exam)->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="t10-empty"><i class="icon-clipboard3"></i> No upcoming duties assigned.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Attendance charts — in the left column under the
             timetable (owner ask 2026-10-25): the old charts row
             below the grid left a hole under a short left
             column. Same period rule for both panels. --}}
            <section class="t10-panel">
                <div class="t10-panel-head">
                    <div class="t10-panel-title">
                        <span class="t10-panel-icon t10-tone-green"><i class="icon-stats-bars"></i></span>
                        <div>
                            <h5>Attendance Overview <span class="t10-head-note">(This Month)</span></h5>
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
            </section>

            <section class="t10-panel">
                <div class="t10-panel-head">
                    <div class="t10-panel-title">
                        <span class="t10-panel-icon t10-tone-lavender"><i class="icon-pie-chart"></i></span>
                        <div>
                            <h5>Student Attendance Status</h5>
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
                @else
                    <div class="t10-caught-up"><i class="icon-pie-chart"></i> No attendance records for your classes in {{ $month_label }} yet.</div>
                @endif
            </section>
        </div>

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
                            {{-- Loop 117: the reference subtext leads; the
                                 grading-queue count folded here by Audit
                                 Fix Wave 1 stays on the page behind it. --}}
                            <p class="t10-panel-sub">Quick view of pending activities &middot; <a href="{{ route('assignments.index') }}">{{ number_format($lms_to_grade ?? 0) }} {{ ($lms_to_grade ?? 0) === 1 ? 'assignment' : 'assignments' }} to grade</a></p>
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
                        </div>
                    </div>
                    <a href="{{ route('circulars.index') }}" class="t10-view-all">View All <i class="icon-arrow-right7"></i></a>
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

@endsection
