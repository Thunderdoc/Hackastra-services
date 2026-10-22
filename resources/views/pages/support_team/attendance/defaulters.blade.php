@extends('layouts.master')
@section('page_title', 'Low Attendance (Below 75%)')
@section('content')

    {{-- Workstream CD: rebuilt on the shared list_page pattern (Loop 28),
         the same chrome as Students / Payments. Class / Month / Below
         are the pattern filter row (same GET params, same auto-submit);
         the scanned-count line rides at the top of the card; the
         "nobody is below the line" case is the designed empty state
         with the same sentence the old green alert carried. Rows,
         % bars (worst first) and the footnote are unchanged. --}}
    @component('partials.patterns.list_page')
        @slot('filters')
            <form method="GET" action="{{ route('attendance.defaulters') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="ad_class_id">Class:</label>
                    <select name="class_id" id="ad_class_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Classes</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="ad_month">Month:</label>
                    <input type="month" name="month" id="ad_month" class="form-control" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
                </div>
                <div class="pat-filter-field">
                    <label for="ad_threshold">Below:</label>
                    <select name="threshold" id="ad_threshold" class="form-control" onchange="this.form.submit()">
                        @foreach($thresholds as $option)
                            <option value="{{ $option }}" {{ $threshold === $option ? 'selected' : '' }}>{{ $option }}%</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        @endslot

        {{-- Workstream DE (Loop 73): the scanned-count line becomes a
             designed summary strip — same four facts (defaulters,
             students scanned, month, threshold), presented as stats.
             Rows, ordering and the footnote formula are unchanged. --}}
        <div class="de-defaulters">
        <div class="ad-summary">
            <div class="ad-stat"><span class="ad-stat-label">Below the line</span><span class="ad-stat-value">{{ $rows->count() }}</span></div>
            <div class="ad-stat"><span class="ad-stat-label">Students scanned</span><span class="ad-stat-value">{{ $scanned }}</span></div>
            <div class="ad-stat"><span class="ad-stat-label">Month</span><span class="ad-stat-value">{{ $month->format('F Y') }}</span></div>
            <div class="ad-stat"><span class="ad-stat-label">Threshold</span><span class="ad-stat-value">Below {{ $threshold }}%</span></div>
        </div>

        @if($rows->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-checkmark-circle2',
                'message' => 'No students below ' . $threshold . '% attendance for ' . $month->format('F Y') . ($selected_class ? ' in ' . $selected_class->name : '') . '.',
            ])
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Section</th>
                    <th class="ad-num">Present</th>
                    <th class="ad-num">Absent</th>
                    <th class="ad-num">Late</th>
                    <th class="ad-num">Periods</th>
                    <th style="min-width:190px">Attendance %</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td><strong>{{ optional($row['student']->user)->name }}</strong></td>
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
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="text-muted mb-0"><small>Attendance % = (present + late) / periods marked, worst first. Students with no period attendance records in the month are excluded — never counted as 0%.</small></p>
        @endif
        </div>
    @endcomponent
@endsection
