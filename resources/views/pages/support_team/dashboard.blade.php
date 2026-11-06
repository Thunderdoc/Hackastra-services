@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('hide_page_header', true)
@section('content')
    @include('partials.role_banner', ['hkRole' => 'admin', 'hkLabel' => 'School Admin'])
@if(Qs::userIsTeamSAT())
    {{-- Header: greeting | date + session | primary action --}}
    <div class="dashboard-hero mb-4">
        <div>
            <p class="mb-0 dashboard-muted">Here&rsquo;s an overview of your school&rsquo;s activities and performance today.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                </span>
            </div>
            @if(Qs::userIsTeamSAWrite())
            <a href="{{ route('students.create') }}" class="btn dashboard-primary-action">
                <i class="icon-plus3 mr-2"></i> Add Student
            </a>
            @endif
        </div>
    </div>

    {{-- Item 17 (2026-11-01, admin-dash reference — the site's own
         style): KPI cards full width with a thin accent strip (no
         sparkline filler), Quick Actions as tiles, Today's
         Attendance + Fee Collection side by side at equal height
         (the fee card fills: bar, rate + defaulter rows, pending
         alert at its foot), Enrollment Trend as bars, Recent
         Students/Payments as rows, and a right rail — Calendar,
         Needs Your Attention (real actionable counts), Upcoming
         Events — stretched level with the main column
         (full-screen rule). ALL data and links are the ones this
         page has always computed; presentation only. --}}
    <div class="l17-kpis">
        @foreach([
            ['label' => 'Total Students', 'value' => number_format($student_count), 'delta' => $stat_deltas['students'] ?? null, 'icon' => 'icon-users4', 'tone' => 'blue', 'acc' => '#17507f', 'link' => route('students.list')],
            ['label' => 'Teaching Staff', 'value' => number_format($teacher_count), 'delta' => $stat_deltas['teachers'] ?? null, 'icon' => 'icon-user-tie', 'tone' => 'green', 'acc' => '#0b7a47', 'link' => route('users.index')],
            ['label' => 'Administrators', 'value' => number_format($administrator_count), 'delta' => $stat_deltas['administrators'] ?? null, 'icon' => 'icon-lock2', 'tone' => 'orange', 'acc' => '#b26a00', 'link' => route('users.index')],
            ['label' => 'Parents', 'value' => number_format($parent_count), 'delta' => $stat_deltas['parents'] ?? null, 'icon' => 'icon-users2', 'tone' => 'purple', 'acc' => '#5b2ea6', 'link' => route('users.index')],
        ] as $stat)
            <a href="{{ $stat['link'] }}" class="l17-kpi dashboard-stat-{{ $stat['tone'] }}" style="--acc:{{ $stat['acc'] }}">
                <span class="l17-krow">
                    <span class="dashboard-stat-icon l17-kicon"><i class="{{ $stat['icon'] }}"></i></span>
                    <span>
                        <span class="l17-kl">{{ $stat['label'] }}</span>
                        <strong class="l17-kv">{{ $stat['value'] }}</strong>
                    </span>
                </span>
                <small class="l17-kd">@if($stat['delta'] === null)Current total @elseif($stat['delta'] >= 0)Up {{ $stat['delta'] }}% vs previous 30 days @else Down {{ abs($stat['delta']) }}% vs previous 30 days @endif</small>
            </a>
        @endforeach
    </div>

    <div class="l17-main">
        <div class="l17-left">
            <section class="dashboard-panel">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-rocket text-blue mr-1"></i> Quick Actions</h5></div>
                </div>
                <div class="l17-qa">
                    <a href="{{ route('students.create') }}" class="l17-qatile"><span class="l17-qi l17-tone-blue"><i class="icon-plus3"></i></span><b>Add Student</b><span>Admit a new student</span></a>
                    <a href="{{ route('payments.create') }}" class="l17-qatile"><span class="l17-qi l17-tone-green"><i class="icon-coin-dollar"></i></span><b>Record Payment</b><span>Fees at the counter</span></a>
                    <a href="{{ route('tt.index') }}" class="l17-qatile"><span class="l17-qi l17-tone-amber"><i class="icon-calendar3"></i></span><b>Timetables</b><span>Class schedules</span></a>
                    <a href="{{ route('admissions.index') }}" class="l17-qatile"><span class="l17-qi l17-tone-purple"><i class="icon-phone2"></i></span><b>Admission Enquiries @if($new_enquiry_count) <span class="badge badge-primary">{{ $new_enquiry_count }} new</span> @endif</b><span>New leads to review</span></a>
                </div>
            </section>

            <div class="l17-duo">
                <section class="dashboard-panel dashboard-attendance l17-fill">
                    <div class="dashboard-panel-heading">
                        <div><h5 class="mb-1"><i class="icon-calendar52 text-blue mr-1"></i> Today&rsquo;s Attendance</h5></div>
                    </div>
                    <div class="attendance-summary">
                        <div class="attendance-donut" style="--attendance-progress: {{ $attendance_today['percent'] }}%">
                            <strong>{{ $attendance_today['percent'] }}%</strong>
                            <small>Present + Late</small>
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

                <section class="dashboard-panel l17-fill">
                    <div class="dashboard-panel-heading">
                        <div><h5 class="mb-1"><i class="icon-wallet text-blue mr-1"></i> Fee Collection Overview</h5><p class="dashboard-muted mb-0">Recorded payment activity &middot; {{ $selected_year }}</p></div>
                        <form method="GET" class="dashboard-period-form">
                            <select name="year" class="dashboard-period-select" onchange="this.form.submit()" aria-label="Fee collection year">
                                @foreach($year_options as $yr)<option value="{{ $yr }}" {{ (int) $yr === (int) $selected_year ? 'selected' : '' }}>{{ $yr }}</option>@endforeach
                            </select>
                        </form>
                    </div>
                    <div class="l17-feebody">
                        <div class="l17-feebar"><i class="l17-feebar-got" style="width:{{ $payment_percent }}%"></i><i class="l17-feebar-rest" style="width:{{ 100 - $payment_percent }}%"></i></div>
                        <div class="l17-feerow"><span>Collected <b>{{ \App\Helpers\Money::inr($payment_collected) }}</b></span><span>Pending <b>{{ \App\Helpers\Money::inr($payment_pending) }}</b></span></div>
                        <div class="l17-feerow l17-feerow-sep"><span>Collection rate</span><b>{{ $payment_percent }}% of {{ $selected_year }} fees</b></div>
                        <div class="l17-feerow"><span>Students with fees pending</span><b>{{ number_format($fee_defaulter_count) }} student{{ $fee_defaulter_count === 1 ? '' : 's' }}</b></div>
                        @if($fee_defaulter_count > 0)
                            <div class="l17-alert">{{ number_format($fee_defaulter_count) }} students have fees pending — reminders can be sent from Payments.</div>
                        @endif
                    </div>
                </section>
            </div>

            <section class="dashboard-panel">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-stats-growth text-blue mr-1"></i> Student Enrollment Trend</h5><p class="dashboard-muted mb-0">Active students &middot; 6 months to {{ $trend_window_label }}</p></div>
                    <form method="GET" class="dashboard-period-form">
                        <select name="year" class="dashboard-period-select" onchange="this.form.submit()" aria-label="Enrollment trend year">
                            @foreach($year_options as $yr)<option value="{{ $yr }}" {{ (int) $yr === (int) $selected_year ? 'selected' : '' }}>{{ $yr }}</option>@endforeach
                        </select>
                    </form>
                </div>
                @php $l17MaxTrend = max(10, (int) $enrollment_trend->max('value')); @endphp
                <div class="l17-trend">
                    @foreach($enrollment_trend as $point)
                        <div class="l17-tcol"><b>{{ number_format($point['value']) }}</b><i style="height:{{ max(4, (int) round($point['value'] / $l17MaxTrend * 92)) }}px"></i><span>{{ $point['label'] }}</span></div>
                    @endforeach
                </div>
            </section>

            <div class="l17-duo">
                <section class="dashboard-panel l17-fill">
                    <div class="dashboard-panel-heading">
                        <div><h5 class="mb-1">Recent Students</h5><p class="dashboard-muted mb-0">Latest admissions and active enrollments</p></div>
                        <a href="{{ route('students.list', optional(App\Models\MyClass::first())->id) }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                    </div>
                    <div class="l17-rows">
                    @forelse($recent_students as $student)
                        @php
                            $l17Name = optional($student->user)->name ?: '—';
                            $l17Parts = preg_split('/\s+/', trim($l17Name));
                            $l17Initials = strtoupper(substr($l17Parts[0] ?? '', 0, 1) . substr(end($l17Parts) ?: '', 0, 1));
                            $l17Tones = ['#17507f', '#0b7a47', '#b26a00', '#5b2ea6'];
                        @endphp
                        <div class="l17-rrow">
                            <span class="l17-ava" style="background:{{ $l17Tones[$loop->index % 4] }}">{{ $l17Initials }}</span>
                            <span><span class="l17-rt">{{ $l17Name }}</span><div class="l17-rm">{{ optional($student->my_class)->name ?: '—' }} · Section {{ optional($student->section)->name ?: '—' }}</div></span>
                            <span class="l17-rv"><small>{{ $student->created_at ? $student->created_at->format('d M Y') : '—' }}</small><span class="dashboard-status status-success">Active</span></span>
                        </div>
                    @empty
                        <div class="dashboard-empty">No students have been enrolled yet.</div>
                    @endforelse
                    </div>
                </section>

                <section class="dashboard-panel l17-fill">
                    <div class="dashboard-panel-heading">
                        <div><h5 class="mb-1">Recent Payments</h5><p class="dashboard-muted mb-0">Latest payment records</p></div>
                        @if(Route::has('payments.manage'))<a href="{{ route('payments.manage') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>@endif
                    </div>
                    <div class="l17-rows">
                    @forelse($recent_payments as $record)
                        <div class="l17-rrow">
                            <span><span class="l17-rt">{{ optional($record->student)->name }}</span><div class="l17-rm">{{ $record->ref_no ? 'Receipt ' . $record->ref_no : (optional($record->payment)->title ?: 'Fee payment') }}</div></span>
                            <span class="l17-rv">{{ \App\Helpers\Money::inr($record->amt_paid ?: 0) }}<small class="{{ $record->paid ? 'l17-ok' : 'l17-warn' }}">{{ $record->paid ? 'Paid' : 'Pending' }}</small></span>
                        </div>
                    @empty
                        <div class="dashboard-empty">No payment records yet.</div>
                    @endforelse
                    </div>
                </section>
            </div>
        </div>

        <div class="l17-rail">
            <section class="dashboard-panel dashboard-calendar">
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

            {{-- Needs Your Attention (item 17): real actionable
                 counts only — a row renders only when its count is
                 positive; all zero shows one calm line. --}}
            <section class="dashboard-panel">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flag7 text-orange mr-1"></i> Needs Your Attention</h5></div>
                </div>
                <div class="l17-rows">
                    @if(($pending_leave_count ?? 0) > 0)
                        <a href="{{ route('leave.index') }}" class="l17-rrow l17-arow">
                            <span class="l17-ava" style="background:#b26a00">{{ $pending_leave_count }}</span>
                            <span><span class="l17-rt">Leave requests pending</span><div class="l17-rm">Waiting for a decision</div></span>
                            <span class="l17-rv"><small>Decide →</small></span>
                        </a>
                    @endif
                    @if(($new_enquiry_count ?? 0) > 0)
                        <a href="{{ route('admissions.index') }}" class="l17-rrow l17-arow">
                            <span class="l17-ava" style="background:#5b2ea6">{{ $new_enquiry_count }}</span>
                            <span><span class="l17-rt">Admission enquiries</span><div class="l17-rm">New leads not yet reviewed</div></span>
                            <span class="l17-rv"><small>Review →</small></span>
                        </a>
                    @endif
                    @if(($fee_defaulter_count ?? 0) > 0)
                        <a href="{{ route('payments.manage') }}" class="l17-rrow l17-arow">
                            <span class="l17-ava" style="background:#d34a24">!</span>
                            <span><span class="l17-rt">Fee defaulters</span><div class="l17-rm">{{ number_format($fee_defaulter_count) }} students with fees pending</div></span>
                            <span class="l17-rv"><small>Remind →</small></span>
                        </a>
                    @endif
                    @if(($pending_leave_count ?? 0) === 0 && ($new_enquiry_count ?? 0) === 0 && ($fee_defaulter_count ?? 0) === 0)
                        <div class="l17-caughtup"><i class="icon-checkmark-circle2"></i> All caught up — nothing is waiting for you.</div>
                    @endif
                </div>
            </section>

            <section class="dashboard-panel dashboard-upcoming l17-grow">
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
    <section class="dashboard-panel dashboard-insights mt-4">
        <div class="dashboard-panel-heading">
            <div><h5 class="mb-1"><i class="icon-eye text-orange mr-1"></i> Smart Insights</h5><p class="dashboard-muted mb-0">AI-powered insights based on your school data</p></div>
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
