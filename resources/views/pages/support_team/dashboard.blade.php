@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
@if(Qs::userIsTeamSAT())
    {{-- Header: greeting | date + session | primary action (target screenshot) --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Here&rsquo;s an overview of your school&rsquo;s activities and performance today.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                    <small>Current Session: {{ $current_session ?: '—' }}</small>
                </span>
            </div>
            <a href="{{ route('students.create') }}" class="btn dashboard-primary-action">
                <i class="icon-plus3 mr-2"></i> Add Student
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-9">
            {{-- Stat cards with real 30-day deltas --}}
            <div class="row dashboard-stats">
                @foreach([
                    ['label' => 'Total Students', 'value' => $student_count, 'sub' => 'Enrolled students', 'icon' => 'icon-users4', 'tone' => 'blue', 'delta' => $stat_deltas['students'], 'link' => route('students.list', optional(App\Models\MyClass::first())->id)],
                    ['label' => 'Teaching Staff', 'value' => $teacher_count, 'sub' => 'Active teachers', 'icon' => 'icon-user-tie', 'tone' => 'green', 'delta' => $stat_deltas['teachers'], 'link' => route('users.index')],
                    ['label' => 'Administrators', 'value' => $administrator_count, 'sub' => 'System administrators', 'icon' => 'icon-shield-check', 'tone' => 'orange', 'delta' => $stat_deltas['administrators'], 'link' => route('users.index')],
                    ['label' => 'Parents', 'value' => $parent_count, 'sub' => 'Registered parents', 'icon' => 'icon-users2', 'tone' => 'purple', 'delta' => $stat_deltas['parents'], 'link' => route('users.index')],
                ] as $stat)
                    <div class="col-sm-6 col-xl-3">
                        <a href="{{ $stat['link'] }}" class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                            <span class="dashboard-stat-top">
                                <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                                @if($stat['delta'] !== null)
                                    <span class="dashboard-delta {{ $stat['delta'] > 0 ? 'delta-up' : ($stat['delta'] < 0 ? 'delta-down' : 'delta-flat') }}">
                                        {{ $stat['delta'] > 0 ? '↑' : ($stat['delta'] < 0 ? '↓' : '') }} {{ abs($stat['delta']) }}%
                                    </span>
                                @endif
                            </span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                                <strong>{{ number_format($stat['value']) }}</strong>
                                <small>{{ $stat['sub'] }}</small>
                            </span>
                        </a>
                    </div>
                @endforeach
            </div>

            {{-- Quick Actions --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Perform common administrative tasks quickly.</p></div>
                </div>
                <div class="dashboard-action-grid l17-action-grid">
                    <a href="{{ route('students.create') }}"><i class="icon-user-plus"></i><span>Add Student</span></a>
                    <a href="{{ route('users.create') }}"><i class="icon-user-tie"></i><span>Add Teacher</span></a>
                    <a href="{{ route('classes.index') }}"><i class="icon-windows2"></i><span>Manage Classes</span></a>
                    <a href="{{ route('exams.create') }}"><i class="icon-book"></i><span>Create Exam</span></a>
                    <a href="{{ route('payments.create') }}"><i class="icon-wallet"></i><span>Record Payment</span></a>
                    <a href="{{ route('marks.tabulation') }}"><i class="icon-stats-bars"></i><span>Marks Tabulation</span></a>
                    <a href="{{ route('settings') }}"><i class="icon-cog5"></i><span>School Settings</span></a>
                </div>
            </section>
        </div>

        {{-- School Calendar --}}
        <div class="col-xl-3">
            <section class="dashboard-panel dashboard-calendar mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> School Calendar</h5></div>
                    <span class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></span>
                </div>
                <div class="calendar-body">
                    <strong class="calendar-month">{{ $calendar['label'] }}</strong>
                    <div class="calendar-grid calendar-weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
                    <div class="calendar-grid">
                        @for($blank = 0; $blank < $calendar['first_weekday']; $blank++)<span></span>@endfor
                        @for($day = 1; $day <= $calendar['days_in_month']; $day++)
                            <span class="{{ $day === $calendar['today'] ? 'calendar-today' : '' }} {{ in_array($day, $calendar['event_days']) ? 'calendar-has-event' : '' }}">{{ $day }}</span>
                        @endfor
                    </div>
                    <div class="calendar-legend">
                        <span><i class="dot-blue"></i> Examination</span>
                        <span><i class="dot-orange"></i> Holiday</span>
                        <span><i class="dot-green"></i> Meeting</span>
                        <span><i class="dot-purple"></i> Event</span>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="row">
        {{-- Student Enrollment Trend — real counts, line/area chart like the target --}}
        <div class="col-xl-5">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-stats-growth text-blue mr-1"></i> Student Enrollment Trend</h5><p class="dashboard-muted mb-0">Active students &middot; 6 months to {{ $trend_window_label }}</p></div>
                    <form method="GET" class="dashboard-period-form">
                        <select name="year" class="dashboard-period-select" onchange="this.form.submit()" aria-label="Enrollment trend year">
                            @foreach($year_options as $yr)<option value="{{ $yr }}" {{ (int) $yr === (int) $selected_year ? 'selected' : '' }}>{{ $yr }}</option>@endforeach
                        </select>
                    </form>
                </div>
                @php
                    $trend = $enrollment_trend->values();
                    $maxTrend = max(10, (int) $trend->max('value'));
                    $chartW = 560; $chartH = 170; $padL = 28; $padT = 26; $padB = 24;
                    $stepX = $trend->count() > 1 ? ($chartW - $padL - 10) / ($trend->count() - 1) : 0;
                    $points = [];
                    foreach ($trend as $i => $point) {
                        $x = $padL + ($stepX * $i);
                        $y = $padT + ($chartH - $padT - $padB) * (1 - ($point['value'] / $maxTrend));
                        $points[] = [$x, $y, $point];
                    }
                    $polyline = implode(' ', array_map(function ($p) { return round($p[0], 1) . ',' . round($p[1], 1); }, $points));
                    $lastPoint = end($points);
                    // Loop 17: clamp the value callout inside the plot area.
                    // It was centred on the last point (x = chart edge), so
                    // its right half overflowed the viewBox and rendered
                    // clipped against the card's right edge.
                    $ttX = $lastPoint ? min(max(0, round($lastPoint[0], 1) - 42), $chartW - 86) : 0;
                    $ttY = $lastPoint ? max(0, round($lastPoint[1], 1) - 34) : 0;
                @endphp
                <div class="dashboard-linechart">
                    <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" role="img" aria-label="Student enrollment trend chart" preserveAspectRatio="none">
                        @foreach([0, 0.5, 1] as $fraction)
                            @php($gridY = $padT + ($chartH - $padT - $padB) * (1 - $fraction))
                            <line x1="{{ $padL }}" y1="{{ $gridY }}" x2="{{ $chartW - 6 }}" y2="{{ $gridY }}" class="chart-gridline"/>
                            <text x="2" y="{{ $gridY + 4 }}" class="chart-axis">{{ (int) round($maxTrend * $fraction) }}</text>
                        @endforeach
                        <polygon points="{{ $padL }},{{ $chartH - $padB }} {{ $polyline }} {{ round($lastPoint[0], 1) }},{{ $chartH - $padB }}" class="chart-area"/>
                        <polyline points="{{ $polyline }}" class="chart-line"/>
                        @foreach($points as $p)
                            <circle cx="{{ round($p[0], 1) }}" cy="{{ round($p[1], 1) }}" r="3.5" class="chart-point"/>
                            <text x="{{ round($p[0], 1) }}" y="{{ $chartH - 6 }}" text-anchor="middle" class="chart-axis">{{ $p[2]['label'] }}</text>
                        @endforeach
                        @if($lastPoint)
                            <g class="chart-tooltip">
                                <rect x="{{ $ttX }}" y="{{ $ttY }}" width="84" height="22" rx="5"/>
                                <text x="{{ $ttX + 42 }}" y="{{ $ttY + 15 }}" text-anchor="middle" class="chart-tooltip-text">{{ $lastPoint[2]['value'] }} Students</text>
                            </g>
                        @endif
                    </svg>
                </div>
            </section>
        </div>

        {{-- Fee Collection Overview — one source of truth, ₹ Indian format --}}
        <div class="col-xl-4">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-wallet text-blue mr-1"></i> Fee Collection Overview</h5><p class="dashboard-muted mb-0">Recorded payment activity &middot; {{ $selected_year }}</p></div>
                    <form method="GET" class="dashboard-period-form">
                        <select name="year" class="dashboard-period-select" onchange="this.form.submit()" aria-label="Fee collection year">
                            @foreach($year_options as $yr)<option value="{{ $yr }}" {{ (int) $yr === (int) $selected_year ? 'selected' : '' }}>{{ $yr }}</option>@endforeach
                        </select>
                    </form>
                </div>
                <div class="dashboard-fee-summary">
                    <div class="dashboard-fee-ring" style="--fee-progress: {{ $payment_percent }}%">
                        <strong>{{ $payment_percent }}%</strong>
                        <small>Collected</small>
                    </div>
                    <dl class="dashboard-fee-list mb-0">
                        <div><dt><span class="dashboard-dot dot-blue"></span>Total Fees</dt><dd>{{ \App\Helpers\Money::inr($payment_total) }}</dd></div>
                        <div><dt><span class="dashboard-dot dot-green"></span>Collected</dt><dd>{{ \App\Helpers\Money::inr($payment_collected) }}</dd></div>
                        <div><dt><span class="dashboard-dot dot-orange"></span>Pending</dt><dd>{{ \App\Helpers\Money::inr($payment_pending) }}</dd></div>
                    </dl>
                </div>
            </section>
        </div>

        {{-- Today's Attendance — real records; 0 is honest when none recorded --}}
        <div class="col-xl-3">
            <section class="dashboard-panel dashboard-attendance mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar52 text-blue mr-1"></i> Today&rsquo;s Attendance</h5></div>
                </div>
                <div class="attendance-summary">
                    <div class="attendance-donut" style="--attendance-progress: {{ $attendance_today['percent'] }}%">
                        <strong>{{ $attendance_today['percent'] }}%</strong>
                        <small>Present</small>
                    </div>
                    <ul class="attendance-legend">
                        <li><span><i class="dashboard-dot dot-green"></i> Present</span><strong>{{ $attendance_today['present'] }}</strong></li>
                        <li><span><i class="dashboard-dot dot-red"></i> Absent</span><strong>{{ $attendance_today['absent'] }}</strong></li>
                        <li><span><i class="dashboard-dot dot-orange"></i> Late</span><strong>{{ $attendance_today['late'] }}</strong></li>
                        <li><span><i class="dashboard-dot dot-blue"></i> Total</span><strong>{{ $attendance_today['total'] }}</strong></li>
                    </ul>
                    @unless($attendance_today['recorded'])
                        <p class="dashboard-muted attendance-note">No attendance recorded today.</p>
                    @endunless
                </div>
            </section>
        </div>
    </div>

    <div class="row">
        {{-- Recent Students --}}
        <div class="col-xl-5">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1">Recent Students</h5><p class="dashboard-muted mb-0">Latest admissions and active enrollments</p></div>
                    <a href="{{ route('students.list', optional(App\Models\MyClass::first())->id) }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive l7-table-scroll">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Student</th><th>Class</th><th>Section</th><th>Admission Date</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($recent_students as $student)
                            <tr>
                                <td><strong>{{ optional($student->user)->name }}</strong></td>
                                <td>{{ optional($student->my_class)->name ?: '—' }}</td>
                                <td>{{ optional($student->section)->name ?: '—' }}</td>
                                <td>{{ $student->created_at ? $student->created_at->format('Y-m-d') : '—' }}</td>
                                <td><span class="dashboard-status status-success">Active</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="dashboard-empty">No students have been enrolled yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Recent Payments --}}
        <div class="col-xl-4">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1">Recent Payments</h5><p class="dashboard-muted mb-0">Latest payment records</p></div>
                    @if(Route::has('payments.manage'))<a href="{{ route('payments.manage') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>@endif
                </div>
                <div class="table-responsive l7-table-scroll">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Student</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($recent_payments as $record)
                            <tr>
                                <td><strong>{{ optional($record->student)->name }}</strong></td>
                                <td>{{ \App\Helpers\Money::inr($record->amt_paid ?: 0) }}</td>
                                <td>{{ $record->created_at ? $record->created_at->format('Y-m-d') : '—' }}</td>
                                <td><span class="dashboard-status {{ $record->paid ? 'status-success' : 'status-warning' }}">{{ $record->paid ? 'Paid' : 'Pending' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="dashboard-empty">No payment records yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Upcoming Events — real school_events / exam timetables only --}}
        <div class="col-xl-3">
            <section class="dashboard-panel dashboard-upcoming mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Upcoming Events</h5></div>
                    <span class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></span>
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

    {{-- Smart Insights — generated from the same real data as the panels above --}}
    <section class="dashboard-panel dashboard-insights mb-4">
        <div class="dashboard-panel-heading">
            <div><h5 class="mb-1"><i class="icon-bulb2 text-orange mr-1"></i> Smart Insights</h5><p class="dashboard-muted mb-0">AI-powered insights based on your school data</p></div>
            <span class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></span>
        </div>
        <div class="dashboard-insight-grid dashboard-insight-scroll">
            @foreach($smart_insights as $insight)
                <div>
                    <i class="{{ $insight['icon'] }} text-{{ $insight['tone'] }}"></i>
                    <span><strong>{{ $insight['title'] }}</strong><small>{{ $insight['text'] }}</small></span>
                    <i class="icon-arrow-right7 insight-chevron"></i>
                </div>
            @endforeach
        </div>
    </section>
@else
    <div class="dashboard-panel"><h5>Welcome back, {{ Auth::user()->name }}</h5><p class="dashboard-muted mb-0">Use the navigation to access your school workspace.</p></div>
@endif
@endsection
