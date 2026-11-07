{{-- Low Attendance results region (item 7, 2026-11-01): the
     table card head (with its search) + the paginated table, or
     the empty states. Screen mode starts EMPTY: the list loads
     once a class AND a section are chosen. --}}
@if(!empty($needs_selection))
    @include('partials.patterns.empty_state', [
        'icon' => 'icon-users',
        'message' => 'Select a class and section to see the low attendance list.',
    ])
@else
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
                <div class="table-responsive">
                <table class="table att-low-table hk-std hk-std-attrep hk-std-attrep-wide">
                    <colgroup><col style="width:3%"><col style="width:20%"><col style="width:9%"><col style="width:8%"><col style="width:7%"><col style="width:6%"><col style="width:6%"><col style="width:5%"><col style="width:6%"><col style="width:16%"><col style="width:7%"><col style="width:7%"></colgroup>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Adm No</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th class="ad-num">Present</th>
                        <th class="ad-num">Absent</th>
                        <th class="ad-num">Late</th>
                        <th class="ad-num">Periods</th>
                        <th class="hk-nw">Attendance %</th>
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
<td class="hk-nw">{{ $row['student']->adm_no ?: '—' }}</td>
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
                            <td class="hk-nw"><span class="att-low-badge {{ $row['status'] === 'Critical' ? 'att-low-badge-critical' : 'att-low-badge-risk' }}">{{ $row['status'] }}</span></td>
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
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 print-hide">
                    <span class="text-muted"><small>Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} students</small></span>
                    {{ $paginator->links() }}
                </div>
                <p class="text-muted mb-0 mt-2"><small>Attendance % = (present + late) / periods marked, worst first. Critical = below 50%; At Risk = 50% up to the chosen threshold. {{ $scanned }} student(s) with marked periods were scanned; students with no period attendance records in the month are excluded — never counted as 0%.</small></p>
            @endif
@endif
