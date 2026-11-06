{{-- Low Attendance KPI strip (item 7, 2026-11-01): swapped with
     the table region on every filter change. Renders nothing
     until a class AND a section are chosen (empty first). --}}
@php
    $avgLabel = $summary['average'] !== null
        ? rtrim(rtrim(number_format($summary['average'], 1), '0'), '.') . '%'
        : '—';
@endphp
@if(empty($needs_selection))
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
@endif
