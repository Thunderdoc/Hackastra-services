{{-- Low Attendance print table (item 7, 2026-11-01): the FULL
     filtered set for paper; swapped with the screen regions so
     window.print() always matches the current filters. --}}
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
