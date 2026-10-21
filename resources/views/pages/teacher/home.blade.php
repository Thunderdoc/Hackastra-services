@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 11: teacher home rebuilt to the reference layout — five
         summary cards, today's periods with live status, quick actions,
         my classes, student overview donut, recent circulars and real
         reminders. Every number comes from HomeController@teacherHomeData. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Here&rsquo;s your teaching overview for today.</p>
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

    {{-- Five summary cards — the teacher's own real assignments --}}
    <div class="teacher-stat-grid mb-4">
        @foreach([
            ['label' => 'My Subjects', 'value' => $my_subjects->count(), 'sub' => 'Subjects you teach', 'icon' => 'icon-book', 'tone' => 'blue'],
            ['label' => 'My Classes', 'value' => $my_class_count, 'sub' => 'Classes you teach in', 'icon' => 'icon-windows2', 'tone' => 'green'],
            ['label' => 'Total Students', 'value' => $my_student_count, 'sub' => 'Across your classes', 'icon' => 'icon-users4', 'tone' => 'orange'],
            ['label' => 'Today\'s Periods', 'value' => $today_periods->count(), 'sub' => 'Scheduled for today', 'icon' => 'icon-clock3', 'tone' => 'red'],
            ['label' => 'Sections I Lead', 'value' => $sections_led->count(), 'sub' => 'As class teacher', 'icon' => 'icon-user-tie', 'tone' => 'purple'],
        ] as $stat)
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
        @endforeach
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Today's Periods — real timetable rows for today's weekday --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Today&rsquo;s Periods</h5><p class="dashboard-muted mb-0">Your class timetable for today &middot; {{ $today_name }}</p></div>
                    <a href="{{ route('tt.index') }}" class="dashboard-view-all">View Full Timetable <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Time</th><th>Subject</th><th>Class &amp; Section</th><th>Room</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        @forelse($today_periods as $row)
                            <tr>
                                <td>{{ $row['time_label'] }}</td>
                                <td><strong>{{ $row['subject_name'] }}</strong></td>
                                <td>{{ $row['class_name'] }}@if(!empty($row['sections'])) &middot; {{ implode(', ', $row['sections']) }}@endif</td>
                                <td>—</td>
                                <td><span class="dashboard-status period-status period-status-{{ $row['status'] }}">{{ $row['status_label'] }}</span></td>
                                <td><a href="{{ route('ttr.show', $row['ttr_id']) }}" class="dashboard-view-all">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="dashboard-empty">No periods are scheduled for you today.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Quick Actions — only workflows a teacher is authorized for --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Perform your everyday teaching tasks quickly.</p></div>
                    <span class="dashboard-heading-icon"><i class="icon-arrow-right7"></i></span>
                </div>
                <div class="dashboard-action-grid teacher-action-grid">
                    <a href="{{ route('attendance.index') }}"><i class="icon-calendar52"></i><span>Take Attendance</span></a>
                    <a href="{{ route('marks.index') }}"><i class="icon-pencil7"></i><span>Enter Marks</span></a>
                    <a href="{{ route('tt.index') }}"><i class="icon-calendar3"></i><span>View Timetable</span></a>
                    <a href="{{ route('students.index') }}"><i class="icon-users4"></i><span>Manage Students</span></a>
                    <a href="{{ route('circulars.index') }}"><i class="icon-megaphone"></i><span>View Circulars</span></a>
                    <a href="{{ route('attendance.report') }}"><i class="icon-stats-bars"></i><span>Attendance Report</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Profile</span></a>
                </div>
            </section>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-4">
            {{-- My Classes — grouped from the teacher's subject assignments --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-windows2 text-blue mr-1"></i> My Classes</h5><p class="dashboard-muted mb-0">Classes you teach this session</p></div>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Class &amp; Section</th><th>Students</th><th>Subject</th><th>Actions</th></tr></thead>
                        <tbody>
                        @forelse($my_classes as $row)
                            <tr>
                                <td><strong>{{ optional($row['class'])->name ?: '—' }}</strong>
                                    @if(!empty($row['sections_led']))<small>Section {{ implode(', ', $row['sections_led']) }}</small>@endif
                                </td>
                                <td>{{ number_format($row['student_count']) }}</td>
                                <td>{{ implode(', ', $row['subjects']) }}</td>
                                <td>@if($row['class'])<a href="{{ route('students.list', $row['class']->id) }}" class="dashboard-view-all">View</a>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="dashboard-empty">No classes have been assigned to you yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Student Overview — donut of real per-class student counts --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-stats-bars text-blue mr-1"></i> Student Overview</h5><p class="dashboard-muted mb-0">Total students across your classes</p></div>
                </div>
                @if($my_student_count > 0 && $student_overview->isNotEmpty())
                    @php
                        $donutTotal = max(1, (int) $student_overview->sum('count'));
                        $donutR = 52; $donutC = 2 * M_PI * $donutR; $donutOffset = 0;
                    @endphp
                    <div class="teacher-donut-wrap">
                        <div class="teacher-donut">
                            <svg viewBox="0 0 140 140" role="img" aria-label="Student distribution across your classes. Total {{ number_format($my_student_count) }} students. Per-class counts are listed beside the chart.">
                                <circle cx="70" cy="70" r="{{ $donutR }}" fill="none" stroke="#e8edf3" stroke-width="16"/>
                                @foreach($student_overview as $slice)
                                    @if($slice['count'] > 0)
                                        @php
                                            $donutLen = $donutC * ($slice['count'] / $donutTotal);
                                            $donutDash = number_format($donutLen, 2) . ' ' . number_format($donutC - $donutLen, 2);
                                            $donutOff = -$donutOffset;
                                            $donutOffset += $donutLen;
                                        @endphp
                                        <circle cx="70" cy="70" r="{{ $donutR }}" fill="none" stroke="{{ $slice['color'] }}" stroke-width="16"
                                                stroke-dasharray="{{ $donutDash }}" stroke-dashoffset="{{ number_format($donutOff, 2) }}"
                                                transform="rotate(-90 70 70)"/>
                                    @endif
                                @endforeach
                            </svg>
                            <div class="teacher-donut-center">
                                <strong>{{ number_format($my_student_count) }}</strong>
                                <small>Total Students</small>
                            </div>
                        </div>
                        <ul class="teacher-donut-legend">
                            @foreach($student_overview as $slice)
                                <li><span class="dashboard-dot" style="background:{{ $slice['color'] }}"></span>{{ $slice['label'] }}<strong>{{ number_format($slice['count']) }}</strong></li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="dashboard-empty dashboard-event-empty">No students are enrolled in your classes yet.</div>
                @endif
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Recent Circulars — the teacher-visible feed, newest first --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-megaphone text-blue mr-1"></i> Recent Circulars</h5><p class="dashboard-muted mb-0">Latest announcements for you</p></div>
                    <a href="{{ route('circulars.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($recent_circulars as $circular)
                    <a href="{{ route('circulars.show', $circular->id) }}" class="teacher-circular">
                        <span class="teacher-circular-copy">
                            <strong>{{ $circular->title }}</strong>
                            <small>{{ optional($circular->starts_at)->format('d M Y') ?: $circular->created_at->format('d M Y') }} &middot; by {{ optional($circular->publisher)->name ?: 'School Office' }}</small>
                        </span>
                        <span class="teacher-circular-chips">
                            <span class="dashboard-status circ-chip-audience">{{ \App\Models\Circular::audiences()[$circular->audience] ?? ucfirst($circular->audience) }}</span>
                            <span class="dashboard-status circ-chip-priority-{{ $circular->priority }}">{{ \App\Models\Circular::priorities()[$circular->priority] ?? ucfirst($circular->priority) }}</span>
                        </span>
                    </a>
                @empty
                    <div class="dashboard-empty dashboard-event-empty">No circulars for you right now.</div>
                @endforelse
            </section>
        </div>
    </div>

    {{-- Important Reminders — counts of real records only --}}
    @if(!empty($reminders))
        <section class="dashboard-panel teacher-reminders mb-4">
            <div class="dashboard-panel-heading">
                <div><h5 class="mb-1"><i class="icon-bell2 text-blue mr-1"></i> Important Reminders</h5><p class="dashboard-muted mb-0">Stay on top of your important tasks.</p></div>
            </div>
            <div class="teacher-reminder-grid">
                @foreach($reminders as $reminder)
                    <a href="{{ $reminder['link'] }}" class="teacher-reminder dashboard-stat-{{ $reminder['tone'] }}">
                        <span class="dashboard-stat-icon"><i class="{{ $reminder['icon'] }}"></i></span>
                        <span class="teacher-reminder-copy">
                            <strong>{{ $reminder['title'] }}</strong>
                            <small>{{ $reminder['text'] }}</small>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
