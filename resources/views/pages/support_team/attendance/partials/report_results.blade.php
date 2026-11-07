{{-- Monthly report results region (item 7, 2026-11-01): the
     stat cards + student table — the page swaps ONLY this region
     via AJAX when a filter changes. Screen mode starts EMPTY:
     the list loads once a class AND a section are chosen. --}}
@php $marxPalette = ['#e8effc', '#e6f6ec', '#fdf3e0', '#efe9fd', '#fdecec', '#e0f2f1']; @endphp
@if(!empty($needs_selection))
    @include('partials.patterns.empty_state', [
        'icon' => 'icon-users',
        'message' => 'Select a class and section to see the monthly attendance report.',
    ])
@else
        @if($selected_class)
            {{-- Summary chips — entries vs registers kept distinct,
                 exactly as the document labels them. --}}
            <div class="marx-stats">
                <div class="marx-stat marx-stat-present">
                    <div class="marx-stat-num">{{ $totals['present'] }}</div>
                    <div class="marx-stat-label">Total Present</div>
                    <div class="marx-stat-cap">{{ number_format($shares['present'], 1) }}% of all periods</div>
                </div>
                <div class="marx-stat marx-stat-absent">
                    <div class="marx-stat-num">{{ $totals['absent'] }}</div>
                    <div class="marx-stat-label">Total Absent</div>
                    <div class="marx-stat-cap">{{ number_format($shares['absent'], 1) }}% of all periods</div>
                </div>
                <div class="marx-stat marx-stat-late">
                    <div class="marx-stat-num">{{ $totals['late'] }}</div>
                    <div class="marx-stat-label">Total Late</div>
                    <div class="marx-stat-cap">{{ number_format($shares['late'], 1) }}% of all periods</div>
                </div>
                <div class="marx-stat marx-stat-periods">
                    <div class="marx-stat-num">{{ $totals['marked'] }}</div>
                    <div class="marx-stat-label">Total Periods</div>
                    <div class="marx-stat-cap">Marked for this month</div>
                </div>
                <div class="marx-stat marx-stat-registers">
                    <div class="marx-stat-num">{{ $sessions_held }}</div>
                    <div class="marx-stat-label">Registers Submitted</div>
                    <div class="marx-stat-cap">Out of total periods</div>
                </div>
            </div>
        @endif

        @if($rows->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users',
                'message' => 'No active students in this class yet.',
            ])
        @else
            <div class="table-responsive">
            <table class="table hk-std hk-std-attrep">
                @if(!empty($all_classes))
                    <colgroup><col style="width:4%"><col style="width:21%"><col style="width:8%"><col style="width:8%"><col style="width:6%"><col style="width:6%"><col style="width:6%"><col style="width:7%"><col style="width:20%"><col style="width:14%"></colgroup>
                @else
                    <colgroup><col style="width:4%"><col style="width:24%"><col style="width:9%"><col style="width:7%"><col style="width:7%"><col style="width:7%"><col style="width:8%"><col style="width:20%"><col style="width:14%"></colgroup>
                @endif
                <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    @if(!empty($all_classes))<th>Class</th>@endif
                    <th>Section</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Late</th>
                    <th>Periods</th>
                    <th class="hk-nw">Attendance %</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    @php
                        $marxName = optional($row['student']->user)->name;
                        $marxInitials = '';
                        foreach (preg_split('/\s+/', trim((string) $marxName)) as $w) {
                            if ($w !== '') {
                                $marxInitials .= strtoupper(substr($w, 0, 1));
                                if (strlen($marxInitials) >= 2) { break; }
                            }
                        }
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><span class="marx-ava" style="background:{{ $marxPalette[$loop->index % count($marxPalette)] }}" aria-hidden="true">{{ $marxInitials ?: '?' }}</span>{{ $marxName }}</td>
                        @if(!empty($all_classes))<td>{{ $row['class_name'] }}</td>@endif
                        <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                        <td class="hk-nw">{{ $row['present'] }}</td>
                        <td class="hk-nw">@if($row['absent'] > 0)<span class="marx-num-bad">{{ $row['absent'] }}</span>@else{{ $row['absent'] }}@endif</td>
                        <td class="hk-nw">@if($row['late'] > 0)<span class="marx-num-late">{{ $row['late'] }}</span>@else{{ $row['late'] }}@endif</td>
                        <td class="hk-nw">{{ $row['marked'] }}</td>
                        <td>
                            @if($row['percent'] === null)
                                <span class="text-muted">—</span>
                            @else
                                <div class="marx-bar">
                                    <div class="marx-bar-fill marx-fill-{{ $row['status_band'] }}" role="progressbar" style="width: {{ $row['percent'] }}%" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <small>{{ $row['percent'] }}%</small>
                            @endif
                        </td>
                        <td class="hk-nw"><span class="marx-pill marx-pill-{{ $row['status_band'] }}">{{ $row['status_label'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>

            <div class="marx-classline">
                <span><strong>{{ $class_summary['students'] }}</strong> Total Students</span>
                <span><strong>{{ $class_summary['with_100'] }}</strong> With 100%</span>
                <span><strong>{{ $class_summary['below_75'] }}</strong> Below 75%</span>
                <span><strong>{{ $class_summary['registers'] }}</strong> Registers Submitted</span>
            </div>

            <p class="text-muted mb-0 mt-2"><small>Counts are period registers: Attendance % = (present + late) / periods marked. Periods with no submitted register are excluded — never counted as absent.<br>
            Status policy: <strong>Excellent</strong> 90% and above &middot; <strong>Good</strong> 75&ndash;89% &middot; <strong>Needs Attention</strong> 50&ndash;74% &middot; <strong>Critical</strong> below 50%.</small></p>
        @endif
@endif
