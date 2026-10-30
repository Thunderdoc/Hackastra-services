@extends('layouts.master')
@section('page_title', 'Mark Attendance')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Attendance &nbsp;/&nbsp; Mark Attendance
@endsection
@section('page_actions')
    <a href="{{ route('attendance.periods.report') }}" class="btn btn-light"><i class="icon-stats-bars2 mr-1"></i> Subject-wise Report</a>
@endsection
@section('content')

    {{-- Loop 77 (owner redesign, first-priority pass + teacher-side
         addendum): the Mark Attendance page rebuilt to the owner's
         premium references — ONE role-aware design. Oversight (Admin /
         Super Admin): class + date filters, Total / Taken / Pending /
         Students Present cards. Teacher: no class filter (their
         periods are their own), date + Filter + Today, a date summary
         pager, and Submitted / Pending / Subjects / Classes cards.
         Both share the schedule table (submitted pill + timestamp,
         students marked fraction) and the Important Information
         panel. Presentation only: the GET contract (class_id / date,
         auto-submit), row targets and the teacher-vs-oversight
         scoping are exactly what the previous page carried.
         The reference's LOCKED state is not a separate pill: in this
         app submission IS the lock (no edit window exists). Loop 117
         (replica pass) aligns the texts to the references — teacher
         subtitle + summary stats + card texts, admin "Period
         Schedule" + status filter tabs + Not Taken Today card —
         and the legend gains its third (Locked) dot. --}}
    @if(($day_type ?? 'normal') !== 'normal')
        <div class="alert {{ $day_type === 'half_day' ? 'alert-warning' : 'alert-info' }} border-0">
            <i class="icon-calendar3 mr-1"></i>
            <strong>{{ $date->format('l, d M Y') }} — {{ $day_type_label }}</strong>
            @if($day_type === 'half_day')
                Only the first periods of the day run today; registers open for those periods only.
            @elseif($day_type === 'exam_day')
                The exam schedule runs today — no regular period registers. Invigilation duties are on the Exam Duties page.
            @else
                No regular period registers are taken today.
            @endif
        </div>
    @endif

    <p class="ma-intro">
        @if($is_teacher)
            Take attendance for your assigned classes and periods. Once a register is submitted it is locked and cannot be edited.
        @else
            Take attendance for each subject period. Once submitted, it will be locked and cannot be edited.
        @endif
    </p>

    <div class="ma-toprow">
        <div class="card ma-filter-card">
            <div class="card-body">
                <form method="GET" action="{{ route('attendance.periods.index') }}" class="ma-filter-form">
                    @if(!$is_teacher)
                        <div class="ma-filter-field ma-filter-grow">
                            <label for="pa_class_id">Class <span class="text-danger">*</span></label>
                            <select name="class_id" id="pa_class_id" class="form-control" onchange="this.form.submit()">
                                @foreach($classes as $class)
                                    <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="ma-filter-field ma-filter-grow">
                        <label for="pa_date">{!! $is_teacher ? 'Select Date' : 'Date <span class="text-danger">*</span>' !!}</label>
                        <input type="date" name="date" id="pa_date" class="form-control" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()">
                    </div>
                    <div class="ma-filter-action">
                        <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Filter</button>
                        @if($is_teacher)
                            <a href="{{ route('attendance.periods.index', ['date' => now()->format('Y-m-d')]) }}" class="btn btn-light ml-2">Today</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
        <div class="card ma-pager-card">
            <div class="card-body ma-pager">
                <a class="btn btn-light ma-pager-btn" aria-label="Previous day"
                   href="{{ route('attendance.periods.index', array_filter(['date' => $date->copy()->subDay()->format('Y-m-d'), 'class_id' => (!$is_teacher && $selected_class) ? $selected_class->id : null])) }}"><i class="icon-chevron-left"></i></a>
                <div class="ma-pager-main">
                    @if($is_teacher)
                        <div class="ma-pager-date">
                            <i class="icon-calendar3"></i>
                            <span class="ma-pager-date-copy">
                                <small>{{ $date->format('l') }}</small>
                                <strong>{{ $date->format('d F Y') }}</strong>
                            </span>
                        </div>
                        {{-- Loop 117: the reference's three labelled stats
                             (Total Periods / Completed / Pending) as separate
                             blocks — the build compressed them into one line. --}}
                        <div class="ma-pager-stats">
                            <span class="ma-pager-stat"><small>Total Periods</small><strong>{{ $stats['total'] }}</strong></span>
                            <span class="ma-pager-stat ma-pager-stat-done"><small>Completed</small><strong>{{ $stats['taken'] }}</strong></span>
                            <span class="ma-pager-stat ma-pager-stat-pending"><small>Pending</small><strong>{{ $stats['pending'] }}</strong></span>
                        </div>
                    @else
                        <div class="ma-pager-date">
                            <i class="icon-calendar3"></i>
                            <span>{{ $date->format('l, d F Y') }}</span>
                        </div>
                    @endif
                </div>
                <a class="btn btn-light ma-pager-btn" aria-label="Next day"
                   href="{{ route('attendance.periods.index', array_filter(['date' => $date->copy()->addDay()->format('Y-m-d'), 'class_id' => (!$is_teacher && $selected_class) ? $selected_class->id : null])) }}"><i class="icon-chevron-right"></i></a>
            </div>
        </div>
    </div>

    <div class="ma-stats">
        @if($is_teacher)
            <div class="ma-stat ma-stat-green">
                <span class="ma-stat-icon"><i class="icon-checkmark-circle2"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['taken'] }}</span>
                    <span class="ma-stat-label">Submitted</span>
                    <span class="ma-stat-caption">Attendance taken and locked</span>
                </div>
            </div>
            <div class="ma-stat ma-stat-amber">
                <span class="ma-stat-icon"><i class="icon-alarm"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['pending'] }}</span>
                    <span class="ma-stat-label">Pending</span>
                    <span class="ma-stat-caption">Attendance not taken yet</span>
                </div>
            </div>
            <div class="ma-stat ma-stat-blue">
                <span class="ma-stat-icon"><i class="icon-book"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['subjects_today'] }}</span>
                    <span class="ma-stat-label">Subject</span>
                    <span class="ma-stat-caption">Today's subjects</span>
                </div>
            </div>
            <div class="ma-stat ma-stat-violet">
                <span class="ma-stat-icon"><i class="icon-users"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['classes_today'] }}</span>
                    <span class="ma-stat-label">Classes</span>
                    <span class="ma-stat-caption">Assigned today</span>
                </div>
            </div>
        @else
            <div class="ma-stat ma-stat-blue">
                <span class="ma-stat-icon"><i class="icon-users"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['total'] }}</span>
                    <span class="ma-stat-label">Total Periods</span>
                    <span class="ma-stat-caption">Scheduled for this class</span>
                </div>
            </div>
            <div class="ma-stat ma-stat-green">
                <span class="ma-stat-icon"><i class="icon-checkmark-circle2"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['taken'] }}</span>
                    <span class="ma-stat-label">Attendance Taken</span>
                    <span class="ma-stat-caption">{{ $stats['taken_pct'] }}% completed</span>
                </div>
            </div>
            <div class="ma-stat ma-stat-amber">
                <span class="ma-stat-icon"><i class="icon-alarm"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['pending'] }}</span>
                    <span class="ma-stat-label">Pending Periods</span>
                    <span class="ma-stat-caption">{{ $stats['pending_pct'] }}% remaining</span>
                </div>
            </div>
            {{-- Loop 117: the reference's fourth oversight card is the
                 Not Taken Today count (the pending rows for the date in
                 view) — Students Present moved off this row; the figure
                 still lives in the subject-wise report. --}}
            <div class="ma-stat ma-stat-red">
                <span class="ma-stat-icon"><i class="icon-alarm"></i></span>
                <div class="ma-stat-copy">
                    <span class="ma-stat-value">{{ $stats['pending'] }}</span>
                    <span class="ma-stat-label"><span class="text-danger">Not</span> Taken Today</span>
                    <span class="ma-stat-caption">All pending can still be marked</span>
                </div>
            </div>
        @endif
    </div>

    <div class="card ma-schedule-card">
        <div class="card-body">
            <div class="ma-schedule-head">
                <div>
                    <h6 class="ma-schedule-title"><i class="icon-book mr-2"></i> {{ $is_teacher ? 'Periods for ' . $date->format('d F Y') : 'Period Schedule' }}</h6>
                    @if($is_teacher)
                        <p class="ma-schedule-sub">Take attendance for each of your scheduled periods</p>
                    @endif
                </div>
                <div class="ma-schedule-side">
                    @if(!$is_teacher)
                        {{-- Loop 117: the reference's status filter tabs —
                             they filter the real rows in place (each row
                             carries its taken/pending state); counts are
                             the same stats the cards show. --}}
                        <div class="ma-tabs" role="group" aria-label="Filter periods by status">
                            <button type="button" class="ma-tab is-active" data-ma-filter="all">All Periods <span class="ma-tab-count">{{ $stats['total'] }}</span></button>
                            <button type="button" class="ma-tab" data-ma-filter="taken">Taken <span class="ma-tab-count">{{ $stats['taken'] }}</span></button>
                            <button type="button" class="ma-tab" data-ma-filter="pending">Pending <span class="ma-tab-count">{{ $stats['pending'] }}</span></button>
                        </div>
                    @endif
                    <span class="ma-legend"><span class="ma-dot ma-dot-done"></span> Submitted</span>
                    <span class="ma-legend"><span class="ma-dot ma-dot-pending"></span> Not Taken</span>
                    <span class="ma-legend"><span class="ma-dot ma-dot-locked"></span> Locked</span>
                    <a class="btn btn-light btn-sm ma-refresh" href="{{ route('attendance.periods.index', array_filter(['date' => $date->format('Y-m-d'), 'class_id' => (!$is_teacher && $selected_class) ? $selected_class->id : null])) }}"><i class="icon-sync mr-1"></i> Refresh</a>
                </div>
            </div>

            @if($periods->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-calendar3',
                    'message' => $is_teacher
                        ? 'No periods are scheduled for you on ' . $date->format('l') . '.'
                        : 'No periods are scheduled for this class on ' . $date->format('l') . '.',
                ])
            @else
                <div class="table-responsive">
                    <table class="table ma-table">
                        <thead>
                        <tr>
                            <th>{{ $is_teacher ? '#' : 'Period' }}</th>
                            <th>Time</th>
                            <th>Subject</th>
                            @if($is_teacher)
                                <th>Class &amp; Section</th>
                                <th>Status</th>
                                <th>Topic</th>
                                <th>Students</th>
                            @else
                                <th>Teacher</th>
                                <th>Topic</th>
                                <th>Status</th>
                            @endif
                            <th class="text-right">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($periods as $row)
                            <tr data-ma-status="{{ $row['session'] ? 'taken' : 'pending' }}">
                                <td><span class="ma-period-num {{ $row['session'] ? 'ma-period-done' : 'ma-period-pending' }}">{{ $loop->iteration }}</span></td>
                                <td class="ma-time">{{ $row['time_label'] }}</td>
                                <td>
                                    <div class="ma-subject">{{ optional($row['subject'])->name }}</div>
                                    @if(optional($row['subject'])->slug)<span class="ma-code-chip">{{ $row['subject']->slug }}</span>@endif
                                    @if(!empty($row['is_substitution']))<div class="ma-sub">Covering for {{ optional($row['teacher'])->name }}</div>@elseif(!empty($row['substitution']) && optional($row['substitution']->substitute)->name)<div class="ma-sub">Sub: {{ $row['substitution']->substitute->name }}</div>@endif
                                </td>
                                @if($is_teacher)
                                    <td>@if(!empty($row['is_substitution']))<span class="badge badge-warning mr-1">(Substitute)</span>@endif{{ optional($row['class'])->name }}@if(optional($row['section'])->name) — {{ $row['section']->name }}@endif</td>
                                    <td>
                                        @if($row['session'])
                                            <span class="ma-pill ma-pill-done"><i class="icon-checkmark3"></i> Submitted</span>
                                            @if($row['session']->submitted_at)<div class="ma-status-sub">by {{ optional($row['session']->teacher)->name ?: optional($row['teacher'])->name }} at {{ $row['session']->submitted_at->format('h:i A') }}</div>@endif
                                        @else
                                            <span class="ma-pill ma-pill-pending"><i class="icon-alarm"></i> Not taken</span>
                                        @endif
                                    </td>
                                    <td class="ma-topic">{{ $row['session'] ? $row['session']->topic : '—' }}</td>
                                    <td class="ma-students">
                                        @if($row['session'])
                                            {{ $row['students_marked'] }}/{{ $row['students_total'] }}
                                        @else
                                            {{ $row['students_total'] }}
                                        @endif
                                    </td>
                                @else
                                    <td>
                                        @if($row['teacher'])
                                            <div class="ma-teacher">
                                                <span class="ma-avatar" aria-hidden="true">{{ strtoupper(substr($row['teacher']->name, 0, 1)) }}@if(\App\Helpers\Qs::hasRealPhoto($row['teacher']->photo))<img src="{{ $row['teacher']->photo }}" alt="" onerror="this.remove()">@endif</span>
                                                <span class="ma-teacher-copy">
                                                    <span class="ma-teacher-name">{{ $row['teacher']->name }}</span>
                                                    <span class="ma-teacher-role">Teacher</span>
                                                </span>
                                            </div>
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                    <td class="ma-topic">{{ $row['session'] ? $row['session']->topic : '—' }}</td>
                                    <td>
                                        @if($row['session'])
                                            <span class="ma-pill ma-pill-done"><i class="icon-checkmark3"></i> Submitted</span>
                                            @if($row['session']->submitted_at)<div class="ma-status-sub">{{ $row['session']->submitted_at->format('d M Y, h:i A') }}</div>@endif
                                        @else
                                            <span class="ma-pill ma-pill-pending"><i class="icon-alarm"></i> Not taken</span>
                                            <div class="ma-status-sub">Pending</div>
                                        @endif
                                    </td>
                                @endif
                                <td class="text-right ma-action">
                                    @if($row['session'])
                                        @if($is_teacher)
                                            <span class="badge badge-success">Submitted</span>
                                        @else
                                            <a href="{{ route('attendance.periods.show', $row['session']->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                        @endif
                                    @elseif($is_teacher && !empty($row['on_leave']))
                                        <span class="badge badge-warning">On Leave</span>
                                        @if(!empty($row['substitution']) && optional($row['substitution']->substitute)->name)<div class="ma-status-sub">Covered by {{ $row['substitution']->substitute->name }}</div>@endif
                                    @elseif($is_teacher)
                                        <a href="{{ route('attendance.periods.take', [$row['tt']->id, 'date' => $date->format('Y-m-d')]) }}" class="btn btn-primary btn-sm"><i class="icon-pencil7 mr-1"></i> Take Attendance</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @if(!$is_teacher)
                            <tr class="ma-tab-empty" hidden><td colspan="7" class="text-center text-muted">No periods match this filter.</td></tr>
                        @endif
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="ma-info">
        <div class="ma-info-head">
            <span class="ma-info-icon"><i class="icon-info22"></i></span>
            <h6 class="ma-info-title">Important Information</h6>
        </div>
        <ul class="ma-info-list">
            <li>Attendance must be marked for each subject period by the respective teacher.</li>
            <li>Once a period's attendance is submitted, it will be locked and cannot be edited.</li>
            @if($is_teacher)
                <li>You only see your own periods here — a period can be submitted only by its assigned teacher.</li>
            @else
                <li>Teachers can only mark attendance for their own periods.</li>
                <li>You are currently viewing in oversight mode (read-only for submitted periods).</li>
            @endif
        </ul>
    </div>

    @if(!$is_teacher)
        <script>
            (function () {
                var tabs = document.querySelectorAll('[data-ma-filter]');
                if (!tabs.length) { return; }
                var rows = document.querySelectorAll('.ma-table tbody tr[data-ma-status]');
                var emptyRow = document.querySelector('.ma-tab-empty');
                Array.prototype.forEach.call(tabs, function (tab) {
                    tab.addEventListener('click', function () {
                        var f = tab.getAttribute('data-ma-filter');
                        Array.prototype.forEach.call(tabs, function (t) { t.classList.toggle('is-active', t === tab); });
                        var visible = 0;
                        Array.prototype.forEach.call(rows, function (r) {
                            var show = f === 'all' || r.getAttribute('data-ma-status') === f;
                            r.style.display = show ? '' : 'none';
                            if (show) { visible++; }
                        });
                        if (emptyRow) { emptyRow.hidden = visible !== 0; }
                    });
                });
            })();
        </script>
    @endif
@endsection
