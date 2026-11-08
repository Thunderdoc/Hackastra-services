@extends('layouts.master')
@section('page_title', 'Analytics Dashboard')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Analytics &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Analytics Dashboard</h5>
        <p class="mb-0">Live trends from existing school data only. No chart library is used: trends render as CSS bars plus data tables.</p>
    </div>
    <div class="card-body">
        <form method="get" action="{{ route('analytics.index') }}" class="form-inline mb-4">
            <label class="mr-2" for="analytics-class">Class</label>
            <select name="class_id" id="analytics-class" class="form-control mr-3">
                <option value="">All classes</option>
                @foreach($classes as $class)
                    <option value="{{ $class->id }}" {{ $selectedClassId == $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                @endforeach
            </select>
            <label class="mr-2" for="analytics-from">From month</label>
            <input type="month" name="from_month" id="analytics-from" class="form-control mr-3" value="{{ $fromMonth }}">
            <label class="mr-2" for="analytics-to">To month</label>
            <input type="month" name="to_month" id="analytics-to" class="form-control mr-3" value="{{ $toMonth }}">
            <label class="mr-2" for="analytics-exam">Exam</label>
            <select name="exam_id" id="analytics-exam" class="form-control mr-3">
                <option value="">All exams</option>
                @foreach($exams as $exam)
                    <option value="{{ $exam->id }}" {{ $selectedExamId == $exam->id ? 'selected' : '' }}>{{ $exam->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary">Apply filters</button>
        </form>
        <p class="text-muted">Month filters apply to attendance and fee collection. Marks averages carry no month, so they follow the exam filter instead.</p>

        <div class="row mb-2">
            <div class="col-md-3 col-6 mb-3">
                <div class="border rounded p-3 text-center">
                    <div class="text-muted text-uppercase" style="font-size: 11px; letter-spacing: 1px;">Overall Attendance</div>
                    <div style="font-size: 26px; font-weight: 600;">{{ $attendanceOverall !== null ? $attendanceOverall . '%' : '—' }}</div>
                </div>
            </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="border rounded p-3 text-center">
                        <div class="text-muted text-uppercase" style="font-size: 11px; letter-spacing: 1px;">Collected (in range)</div>
                        <div style="font-size: 26px; font-weight: 600;">₹{{ number_format($feeTotal) }}</div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="border rounded p-3 text-center">
                        <div class="text-muted text-uppercase" style="font-size: 11px; letter-spacing: 1px;">Outstanding Fees</div>
                        <div style="font-size: 26px; font-weight: 600;">₹{{ number_format($feeOutstandingTotal) }}</div>
                    </div>
                </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="border rounded p-3 text-center">
                    <div class="text-muted text-uppercase" style="font-size: 11px; letter-spacing: 1px;">Classes in View</div>
                    <div style="font-size: 26px; font-weight: 600;">{{ $classCount }}</div>
                </div>
            </div>
        </div>

        <h5>Attendance by class and month</h5>
        <p class="text-muted">Attendance % = (present + late) / marked entries, the app's standard rule, over period-attendance registers.</p>
        @if(count($attendance))
            <div class="table-responsive">
                <table class="table hk-std hk-std-880">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:14%"><col style="width:18%"><col style="width:10%"><col style="width:9%"><col style="width:10%"><col style="width:10%"><col style="width:12%"><col style="width:17%"></colgroup>
                    <thead>
                        <tr><th>Month</th><th>Class</th><th>Present</th><th>Late</th><th>Absent</th><th>Marked</th><th>Attendance %</th><th>Trend</th></tr>
                    </thead>
                    <tbody>
                        @foreach($attendance as $row)
                            <tr>
                                <td class="hk-nw">{{ $row['month_label'] }}</td>
                                <td>{{ $row['class_name'] }}</td>
                                <td class="hk-nw">{{ $row['present'] }}</td>
                                <td class="hk-nw">{{ $row['late'] }}</td>
                                <td class="hk-nw">{{ $row['absent'] }}</td>
                                <td class="hk-nw">{{ $row['marked'] }}</td>
                                <td class="hk-nw">{{ $row['pct'] !== null ? $row['pct'] . '%' : '—' }}</td>
                                <td>
                                    @if($row['pct'] !== null)
                                        <div style="background:rgba(127,140,160,.35);height:12px;min-width:60px;"><div style="background:#22c55e;height:12px;width:{{ $row['pct'] }}%;"></div></div>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', ['icon' => 'icon-calendar2', 'message' => 'No attendance data for the selected filters.', 'compact' => true])
        @endif

        <h5 class="mt-4">Fee collection by month</h5>
        <p class="text-muted">Collected = sum of amounts paid on paid payment records. Total outstanding across the selected scope: {{ number_format($feeOutstandingTotal) }}.</p>
        @if(count($fees))
            <div class="table-responsive">
                <table class="table hk-std hk-std-560">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:22%"><col style="width:14%"><col style="width:20%"><col style="width:44%"></colgroup>
                    <thead>
                        <tr><th>Month</th><th>Receipts</th><th>Collected</th><th>Trend</th></tr>
                    </thead>
                    <tbody>
                        @foreach($fees as $row)
                            <tr>
                                <td>{{ $row['month_label'] }}</td>
                                <td class="hk-nw">{{ $row['receipts'] }}</td>
                                <td class="hk-nw">{{ number_format($row['total']) }}</td>
                                <td>
                                    <div style="background:rgba(127,140,160,.35);height:12px;min-width:60px;"><div style="background:#3b82f6;height:12px;width:{{ $feeMax > 0 ? round($row['total'] / $feeMax * 100) : 0 }}%;"></div></div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><th colspan="2">Total collected</th><th>{{ number_format($feeTotal) }}</th><th></th></tr>
                    </tfoot>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', ['icon' => 'icon-cash2', 'message' => 'No fee collection for the selected filters.', 'compact' => true])
        @endif

        <h5 class="mt-4">Outstanding fees by class</h5>
        @if(count($feeOutstanding))
            <div class="table-responsive">
                <table class="table hk-std hk-std-560">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:55%"><col style="width:45%"></colgroup>
                    <thead>
                        <tr><th>Class</th><th>Outstanding balance</th></tr>
                    </thead>
                    <tbody>
                        @foreach($feeOutstanding as $row)
                            <tr><td>{{ $row['class_name'] }}</td><td class="hk-nw">{{ number_format($row['outstanding']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', ['icon' => 'icon-cash2', 'message' => 'No outstanding fee data for the selected filters.', 'compact' => true])
        @endif

        <h5 class="mt-4">Marks averages by class and exam</h5>
        <p class="text-muted">Average of the per-student exam average already computed by the app (exam_records.ave), per class and exam.</p>
        @if(count($marks))
            <div class="table-responsive">
                <table class="table hk-std hk-std-640">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:26%"><col style="width:18%"><col style="width:12%"><col style="width:12%"><col style="width:32%"></colgroup>
                    <thead>
                        <tr><th>Exam</th><th>Class</th><th>Students</th><th>Average</th><th>Trend</th></tr>
                    </thead>
                    <tbody>
                        @foreach($marks as $row)
                            <tr>
                                <td>{{ $row['exam_name'] }}</td>
                                <td>{{ $row['class_name'] }}</td>
                                <td class="hk-nw">{{ $row['students'] }}</td>
                                <td class="hk-nw">{{ $row['avg'] !== null ? number_format($row['avg'], 2) : '—' }}</td>
                                <td>
                                    @if($row['avg'] !== null)
                                        <div style="background:rgba(127,140,160,.35);height:12px;min-width:60px;"><div style="background:#f59e0b;height:12px;width:{{ min(100, round($row['avg'])) }}%;"></div></div>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', ['icon' => 'icon-profile', 'message' => 'No marks data for the selected filters.', 'compact' => true])
        @endif
    </div>
</div>
@endsection
