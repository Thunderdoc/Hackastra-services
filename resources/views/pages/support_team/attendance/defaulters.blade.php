@extends('layouts.master')
@section('page_title', 'Low Attendance (Below 75%)')
@section('hide_page_header', true)
@section('content')

    {{-- Loop 78 — Low Attendance premium uplift. The screen side
         stays on the shared list_page pattern; the stats slot
         carries four summary cards computed from the CURRENT
         filter set, and the filter row gains Section (limited to
         the chosen class). Print uses the v2 document system
         (Loop 31/71): the print-doc head/foot below + a print-only
         table with the FULL filtered set, so the paper figures
         always match the screen cards even when the screen table
         is paginated.
         Loop 117 — replica pass: reference header (breadcrumb +
         icon tile), one Export dropdown, the reference KPI set,
         and the search moved into the table card. --}}
    @php
        $filterLine = $month->format('F Y')
            . ($selected_class ? ' · Class: ' . $selected_class->name : (Qs::userIsTeacher() ? ' · Assigned Classes' : ' · All Classes'))
            . ($selected_section ? ' · Section: ' . $selected_section->name : '')
            . ($search !== '' ? ' · Search: "' . $search . '"' : '');
        $avgLabel = $summary['average'] !== null
            ? rtrim(rtrim(number_format($summary['average'], 1), '0'), '.') . '%'
            : '—';
        $dbInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $dbWord) {
            if ($dbWord !== '') {
                $dbInitials .= strtoupper(substr($dbWord, 0, 1));
                if (strlen($dbInitials) >= 2) { break; }
            }
        }
        if ($dbInitials === '') { $dbInitials = 'S'; }
    @endphp
    {{-- Print band (owner order 2026-10-29: header on EVERY page).
         With rows present, the band rides the print table's thead
         (see the print table below), which the browser repeats on
         every page; this standalone copy covers only the
         empty-result print, where there is no table to carry it. --}}
    @if($rows->isEmpty())
    <div class="print-doc-head">
        @if(Qs::getSetting('logo'))
            <img src="{{ Qs::getSetting('logo') }}" alt="">
        @else
            <span class="print-doc-mono" aria-hidden="true">{{ $dbInitials }}</span>
        @endif
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Low Attendance (Below {{ $threshold }}%) &mdash; {{ $filterLine }} &middot; {{ $summary['below'] }} student(s) below the line &middot; {{ $summary['critical'] }} critical (below 50%) &middot; Average {{ $avgLabel }}</p>
        </div>
    </div>
    @endif
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    <div class="att-low">
        {{-- Loop 117 (replica pass): the reference header — breadcrumb,
             icon tile, title + subtitle, and ONE Export dropdown beside
             View Full Report (the build had split Export CSV / Print
             into separate buttons). Screen-only; print keeps the
             document head above. --}}
        <div class="l117-page-head print-hide">
            <div class="l117-page-head-main">
                <span class="l117-page-head-icon"><i class="icon-users"></i></span>
                <div>
                    <div class="l117-crumb">Attendance &nbsp;/&nbsp; Low Attendance</div>
                    <h4 class="l117-page-title">Low Attendance (Below {{ $threshold }}%)</h4>
                    <p class="l117-page-sub">Students whose overall attendance is below the selected threshold.</p>
                </div>
            </div>
            <div class="l117-page-head-actions">
                <div class="dropdown d-inline-block">
                    <button type="button" class="btn btn-light btn-sm" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="icon-download mr-1"></i> Export <i class="icon-chevron-down ml-1"></i></button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="{{ route('attendance.defaulters.export', request()->query()) }}" class="dropdown-item"><i class="icon-file-text2"></i> Export CSV</a>
                        <a href="{{ route('attendance.defaulters.pdf', request()->query()) }}" class="dropdown-item"><i class="icon-file-pdf"></i> Download PDF</a>
                        <button type="button" class="dropdown-item" onclick="window.print()"><i class="icon-printer"></i> Print</button>
                    </div>
                </div>

            </div>
        </div>

        @component('partials.patterns.list_page')
            @slot('stats')
                {{-- Loop 117: the reference KPI set, value-led — Students
                     below threshold, Classes carrying them, their average,
                     and total enrolment in scope. Every figure is computed
                     from the same filtered rows in defaulterData(). --}}
                <div class="row dashboard-stats">
                    @foreach([
                        ['value' => number_format($summary['below']), 'label' => 'Students', 'sub' => 'Below ' . $threshold . '% attendance', 'icon' => 'icon-users', 'tone' => 'red'],
                        ['value' => number_format($summary['classes']), 'label' => 'Classes', 'sub' => 'Have students below ' . $threshold . '%', 'icon' => 'icon-graduation2', 'tone' => 'blue'],
                        ['value' => $avgLabel, 'label' => 'Average Attendance', 'sub' => 'Of at-risk students', 'icon' => 'icon-watch', 'tone' => 'amber'],
                        ['value' => number_format($summary['total_students']), 'label' => 'Total Students', 'sub' => ($selected_class || $selected_section) ? 'Enrolled in the selected scope' : (Qs::userIsTeacher() ? 'Across your assigned classes' : 'Across all classes'), 'icon' => 'icon-users4', 'tone' => 'green'],
                    ] as $stat)
                        <div class="col-sm-6 col-xl-3">
                            <div class="l117-kpi l117-kpi-{{ $stat['tone'] }}">
                                <span class="l117-kpi-icon"><i class="{{ $stat['icon'] }}"></i></span>
                                <span class="l117-kpi-copy">
                                    <strong>{{ $stat['value'] }}</strong>
                                    <span class="l117-kpi-label">{{ $stat['label'] }}</span>
                                    <small>{{ $stat['sub'] }}</small>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endslot

            @slot('filters')
                <form method="GET" action="{{ route('attendance.defaulters') }}" class="pat-filter-form print-hide">
                    <div class="pat-filter-field">
                        <label for="ad_class_id">Class</label>
                        <select name="class_id" id="ad_class_id" class="form-control" onchange="this.form.submit()">
                            <option value="">{{ Qs::userIsTeacher() ? 'All assigned classes' : 'All Classes' }}</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-field">
                        <label for="ad_section_id">Section</label>
                        <select name="section_id" id="ad_section_id" class="form-control" onchange="this.form.submit()" {{ $selected_class ? '' : 'disabled' }}>
                            <option value="">All Sections</option>
                            @foreach($sections as $section)
                                <option value="{{ $section->id }}" {{ $selected_section && $selected_section->id === $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-field">
                        <label for="ad_month">Month</label>
                        <input type="month" name="month" id="ad_month" class="form-control" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
                    </div>
                    <div class="pat-filter-field">
                        <label for="ad_threshold">Below (%)</label>
                        <select name="threshold" id="ad_threshold" class="form-control" onchange="this.form.submit()">
                            @foreach($thresholds as $option)
                                <option value="{{ $option }}" {{ $threshold === $option ? 'selected' : '' }}>{{ $option }}%</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-action">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="{{ route('attendance.defaulters') }}" class="btn btn-light ml-2">Reset</a>
                    </div>
                </form>
            @endslot

            {{-- Loop 117: the table card gains its reference header —
                 title + subtext, and the search box lives here now
                 (it sat in the filter card). The search still posts
                 the same GET contract; the active filters ride along
                 as hidden fields so searching never resets them. --}}
            <div class="l117-table-head print-hide">
                <div>
                    <h6 class="l117-table-title">Students with Low Attendance</h6>
                    <p class="l117-table-sub">List of students whose attendance is below the selected threshold.</p>
                </div>
                <form method="GET" action="{{ route('attendance.defaulters') }}" class="l117-table-search" role="search">
                    @if($selected_class)<input type="hidden" name="class_id" value="{{ $selected_class->id }}">@endif
                    @if($selected_section)<input type="hidden" name="section_id" value="{{ $selected_section->id }}">@endif
                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                    <input type="hidden" name="threshold" value="{{ $threshold }}">
                    <i class="icon-search4"></i>
                    <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="Search by name or roll number..." aria-label="Search by name or roll number">
                </form>
            </div>

            @if($rows->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-checkmark-circle2',
                    'message' => 'No students below ' . $threshold . '% attendance for ' . $month->format('F Y') . ($selected_class ? ' in ' . $selected_class->name : '') . ' — good news, nobody needs attendance support right now.',
                ])
            @else
                <table class="table att-low-table">
                    <thead>
                    <tr>
                        <th style="width:38px">#</th>
                        <th>Student</th>
                        <th>Adm No</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th class="ad-num">Present</th>
                        <th class="ad-num">Absent</th>
                        <th class="ad-num">Late</th>
                        <th class="ad-num">Periods</th>
                        <th style="min-width:180px">Attendance %</th>
                        <th>Status</th>
                        <th class="text-center print-hide">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($paginator as $row)
                        @php
                            $studentUser = optional($row['student']->user);
                            $ini = '';
                            foreach (array_slice(preg_split('/\s+/', trim((string) $studentUser->name)), 0, 2) as $w) {
                                if ($w !== '') { $ini .= strtoupper(substr($w, 0, 1)); }
                            }
                        @endphp
                        <tr>
                            <td>{{ $paginator->firstItem() + $loop->index }}</td>
                            <td>
                                <div class="att-low-student">
                                    @if(Qs::hasRealPhoto($studentUser->photo))
                                        <img class="att-low-avatar" src="{{ $studentUser->photo }}" alt="">
                                    @else
                                        <span class="att-low-initials" aria-hidden="true">{{ $ini !== '' ? $ini : '?' }}</span>
                                    @endif
                                    <span class="att-low-student-text">
                                        <strong>{{ $studentUser->name }}</strong>
                                    </span>
                                </div>
                            </td>
                            <td>{{ $row['student']->adm_no ?: '—' }}</td>
                            <td>{{ optional($row['student']->my_class)->name ?: '—' }}</td>
                            <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                            <td class="ad-num">{{ $row['present'] }}</td>
                            <td class="ad-num">{{ $row['absent'] }}</td>
                            <td class="ad-num">{{ $row['late'] }}</td>
                            <td class="ad-num">{{ $row['marked'] }}</td>
                            <td>
                                <div class="ad-meter">
                                    <div class="ad-track"><div class="ad-fill {{ $row['percent'] >= 50 ? 'ad-fill-warning' : 'ad-fill-danger' }}" role="progressbar" style="width: {{ $row['percent'] }}%" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div></div>
                                    <span class="ad-pct {{ $row['percent'] >= 50 ? 'ad-pct-warning' : 'ad-pct-danger' }}">{{ $row['percent'] }}%</span>
                                </div>
                            </td>
                            <td><span class="att-low-badge {{ $row['status'] === 'Critical' ? 'att-low-badge-critical' : 'att-low-badge-risk' }}">{{ $row['status'] }}</span></td>
                            <td class="text-center print-hide">
                                <a href="{{ route('students.show', Qs::hash($row['student']->id)) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                <div class="list-icons d-inline-block">
                                    <div class="dropdown">
                                        <a href="#" class="list-icons-item" data-toggle="dropdown" aria-label="More actions for {{ $studentUser->name }}"><i class="icon-menu9"></i></a>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            <a href="{{ route('students.show', Qs::hash($row['student']->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
                                            <a href="{{ route('attendance.student_report', [Qs::hash($row['student']->id), 'month' => $month->format('Y-m')]) }}" class="dropdown-item"><i class="icon-stats-bars"></i> Attendance Report</a>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 print-hide">
                    <span class="text-muted"><small>Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} students</small></span>
                    {{ $paginator->links() }}
                </div>
                <p class="text-muted mb-0 mt-2"><small>Attendance % = (present + late) / periods marked, worst first. Critical = below 50%; At Risk = 50% up to the chosen threshold. {{ $scanned }} student(s) with marked periods were scanned; students with no period attendance records in the month are excluded — never counted as 0%.</small></p>
            @endif
        @endcomponent

        {{-- Print-only: the FULL filtered set (the screen table is
             paginated). Shown only on paper by Loop 78's print block;
             figures are the same $rows the cards are computed from. --}}
        @if($rows->isNotEmpty())
            <div class="att-low-print-wrap">
                <table class="table att-low-print-table">
                    <thead>
                    {{-- Owner order 2026-10-29: the school band rides
                         the thead, so the browser repeats it (with
                         the column row) at the top of EVERY printed
                         page instead of page 1 only. --}}
                    <tr class="att-low-band-row">
                        <th colspan="11">
                            <div class="print-doc-head">
                                @if(Qs::getSetting('logo'))
                                    <img src="{{ Qs::getSetting('logo') }}" alt="">
                                @else
                                    <span class="print-doc-mono" aria-hidden="true">{{ $dbInitials }}</span>
                                @endif
                                <div>
                                    <h1>{{ Qs::getSystemName() }}</h1>
                                    <p>Low Attendance (Below {{ $threshold }}%) &mdash; {{ $filterLine }} &middot; {{ $summary['below'] }} student(s) below the line &middot; {{ $summary['critical'] }} critical (below 50%) &middot; Average {{ $avgLabel }}</p>
                                </div>
                            </div>
                        </th>
                    </tr>
                    <tr>
                        <th style="width:34px">#</th>
                        <th>Student</th>
                        <th>Adm No</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Periods</th>
                        <th>Attendance %</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td><strong>{{ optional($row['student']->user)->name }}</strong></td>
                            <td>{{ $row['student']->adm_no ?: '—' }}</td>
                            <td>{{ optional($row['student']->my_class)->name ?: '—' }}</td>
                            <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                            <td>{{ $row['present'] }}</td>
                            <td>{{ $row['absent'] }}</td>
                            <td>{{ $row['late'] }}</td>
                            <td>{{ $row['marked'] }}</td>
                            <td>{{ $row['percent'] }}%</td>
                            <td>{{ $row['status'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="att-low-print-note">Attendance % = (present + late) / periods marked. Critical = below 50%; At Risk = 50% up to {{ $threshold }}%. Students with no period attendance records in {{ $month->format('F Y') }} are excluded — never counted as 0%.</p>
            </div>
        @endif
    </div>

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Low Attendance (Below {{ $threshold }}%) &mdash; {{ $filterLine }} &middot; Generated by Hackstra</span>
    </div>
@endsection
