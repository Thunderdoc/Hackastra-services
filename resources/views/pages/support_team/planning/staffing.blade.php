@extends('layouts.master')
@section('page_title', 'Staffing Dashboard')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Planning &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')

    {{-- Staffing Dashboard (Scheduling Engine Slice B): demand vs
         capacity from REAL configured data only. Demand = the
         weekly-period requirements set on Subjects; capacity =
         eligible teachers x the weekly cap from Settings. A subject
         whose requirement is not set is reported as unconfigured —
         never silently counted as zero demand. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            How much teaching the school needs vs how much its teachers can carry — caps: {{ $totals['cap_day'] }} periods a day, {{ $totals['cap_week'] }} a week per teacher (Settings). Capacity is a gross ceiling per subject; the allocation plan checks true feasibility.
        @endslot
        @slot('action')
            <a href="{{ route('planning.eligibility') }}" class="btn btn-light btn-sm"><i class="icon-user-check mr-1"></i> Teacher Eligibility</a>
            <a href="{{ route('subjects.index') }}" class="btn btn-light btn-sm"><i class="icon-pin mr-1"></i> Subjects</a>
        @endslot

        <div class="marx-stats">
            <div class="marx-stat marx-stat-periods">
                <div class="marx-stat-num">{{ $totals['demand'] }}</div>
                <div class="marx-stat-label">Periods Needed / Week</div>
                <div class="marx-stat-cap">Across all classes &amp; subjects</div>
            </div>
            <div class="marx-stat marx-stat-present">
                <div class="marx-stat-num">{{ $totals['capacity'] }}</div>
                <div class="marx-stat-label">Eligible Capacity</div>
                <div class="marx-stat-cap">Eligible teachers × weekly cap</div>
            </div>
            <div class="marx-stat marx-stat-absent">
                <div class="marx-stat-num">{{ $totals['shortage_subjects'] }}</div>
                <div class="marx-stat-label">Subjects Short</div>
                <div class="marx-stat-cap">Demand above capacity, or no eligible teacher</div>
            </div>
            <div class="marx-stat marx-stat-late">
                <div class="marx-stat-num">{{ $totals['unassigned'] }}</div>
                <div class="marx-stat-label">Periods Without a Teacher</div>
                <div class="marx-stat-cap">{{ $totals['unconfigured'] }} class-subjects have no requirement set · {{ $totals['overloaded'] }} teacher(s) over cap</div>
            </div>
        </div>

        <h5 class="mt-4 mb-2">Demand by Subject</h5>
        <div class="table-responsive">
            <table class="table hk-std hk-std-880">
                {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                <colgroup><col style="width:16%"><col style="width:13%"><col style="width:10%"><col style="width:11%"><col style="width:11%"><col style="width:12%"><col style="width:10%"><col style="width:17%"></colgroup>
                <thead><tr><th>Subject</th><th>Classes</th><th>Needed / Wk</th><th>With a Teacher</th><th>Unassigned</th><th>Eligible Teachers</th><th>Capacity</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($subjects as $name => $r)
                    <tr>
                        <td>{{ $name }}</td>
                        <td>{{ $r['classes'] }}{{ $r['unconfigured'] ? ' (' . $r['unconfigured'] . ' not configured)' : '' }}</td>
                        <td class="hk-nw">{{ $r['demand'] }}</td>
                        <td class="hk-nw">{{ $r['committed'] }}</td>
                        <td class="hk-nw">{{ $r['unassigned'] }}</td>
                        <td class="hk-nw">{{ $r['eligible_count'] }}</td>
                        <td class="hk-nw">{{ $r['capacity'] }}</td>
                        <td>
                            @if($r['uncovered'])<span class="badge badge-danger">No eligible teacher</span>
                            @elseif($r['shortfall'] > 0)<span class="badge badge-danger">Short by {{ $r['shortfall'] }} periods</span>
                            @elseif($r['unassigned'] > 0)<span class="badge badge-warning">Needs allocation</span>
                            @elseif($r['unconfigured'] > 0)<span class="badge badge-warning">Requirement incomplete</span>
                            @else<span class="badge badge-success">Covered</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <h5 class="mt-4 mb-2">Teacher Load</h5>
        <div class="table-responsive">
            <table class="table hk-std hk-std-880">
                {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                <colgroup><col style="width:18%"><col style="width:12%"><col style="width:24%"><col style="width:15%"><col style="width:8%"><col style="width:9%"><col style="width:14%"></colgroup>
                <thead><tr><th>Teacher</th><th>Class-Subjects</th><th>Subjects</th><th>Committed / Wk</th><th>Cap</th><th>Load</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($teachers as $row)
                    <tr>
                        <td>{{ $row['teacher']->name }}</td>
                        <td>{{ $row['classes'] }}</td>
                        <td>{{ implode(', ', $row['subjects']) ?: '—' }}</td>
                        <td>{{ $row['committed'] }}{{ $row['unconfigured'] ? ' (+' . $row['unconfigured'] . ' unconfigured)' : '' }}</td>
                        <td class="hk-nw">{{ $row['cap'] }}</td>
                        <td class="hk-nw">{{ $row['utilization'] }}%</td>
                        <td>
                            @if($row['overloaded'])<span class="badge badge-danger">Overloaded</span>
                            @elseif($row['classes'] === 0)<span class="badge badge-secondary">No classes</span>
                            @else<span class="badge badge-success">Within cap</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endcomponent
@endsection
