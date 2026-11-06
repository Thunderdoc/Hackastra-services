@extends('layouts.master')
@section('page_title', 'Class Overview')
@section('page_breadcrumb', 'Class Overview')
@section('page_actions')
    <a href="{{ route('dashboard') }}" class="btn btn-default">Back to Dashboard</a>
@endsection
@section('content')
    {{-- Item 12 (Light Luxe): slim header + summary tiles + one card
         per class replace the panel + table. The long methodology
         paragraph is gone (one subtitle line instead). Every number
         is the same figure the table showed — the tiles are sums and
         means of the per-class rows below them, computed here from
         the controller's own data. The detail (section comparison +
         students needing attention) renders INSIDE the expanded
         card, reached by the same class_id mechanism as before. --}}
    <div class="l10-head">
        <span class="l10-head-tile"><i class="icon-home4"></i></span>
        <div class="l10-head-text">
            <h5>Class Overview</h5>
            <p>@if($is_admin) Every class at a glance — attendance, marks and toppers across the school. @else Your classes at a glance — attendance, marks and toppers for your own subjects and periods. @endif</p>
        </div>
        <span class="l10-chip"><i class="icon-calendar3"></i>Session {{ Qs::getCurrentSession() }}</span>
    </div>

    @if($overviews->isEmpty())
        <div class="card pat-form-card">
            <div class="card-body">
                <p class="text-muted mb-0">No classes found for this account yet.</p>
            </div>
        </div>
    @else
        @php
            $tileStudents = $overviews->sum('strength');
            $tileMarks = $overviews->pluck('marks_avg')->filter(function ($v) { return $v !== null; });
            $tileMarksAvg = $tileMarks->count() ? $tileMarks->avg() : null;
            $tileExamNames = $overviews->pluck('exam')->filter()->pluck('name')->unique()->values();
            $tileAtRisk = $overviews->sum(function ($r) { return count($r['at_risk']); });
        @endphp
        <div class="l12-tiles">
            <div class="l10-tile">
                <span class="l10-tile-ic l10-tone-blue"><i class="icon-home4"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n">{{ number_format($overviews->count()) }}</span><span class="l10-tile-l">{{ $is_admin ? 'Classes' : 'My Classes' }}</span></span>
            </div>
            <div class="l10-tile">
                <span class="l10-tile-ic l10-tone-violet"><i class="icon-users"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n">{{ number_format($tileStudents) }}</span><span class="l10-tile-l">Students</span></span>
            </div>
            <div class="l10-tile">
                <span class="l10-tile-ic l10-tone-green"><i class="icon-stats-bars2"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n">@if($tileMarksAvg === null) &mdash; @else {{ number_format($tileMarksAvg, 1) }}% @endif</span><span class="l10-tile-l">Marks Average{{ $tileExamNames->count() === 1 ? ' · ' . $tileExamNames->first() : '' }}</span></span>
            </div>
            <div class="l10-tile">
                <span class="l10-tile-ic l12-tone-red"><i class="icon-warning2"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n">{{ number_format($tileAtRisk) }}</span><span class="l10-tile-l">At-Risk Students</span></span>
            </div>
        </div>

        @foreach($overviews as $row)
            @php $isDetail = $detail && (int) $detail['class']->id === (int) $row['class']->id; @endphp
            <div class="l12-card">
                <div class="l12-row">
                    <div class="l12-name">
                        <div class="l12-cn">{{ $row['class']->name }}</div>
                        <div class="l12-subjects">@foreach($row['subjects'] as $sub)<span class="l12-subj">{{ $sub->name }}</span>@endforeach</div>
                    </div>
                    <div class="l12-stat">
                        <span class="l12-cl">Strength</span>
                        <span class="l12-cv">{{ number_format($row['strength']) }} <small>students</small></span>
                    </div>
                    <div class="l12-stat">
                        <span class="l12-cl">Attendance</span>
                        @if($row['attendance_pct'] === null)
                            <span class="l12-cv l12-muted">&mdash; <small>no periods yet</small></span>
                        @else
                            <span class="l12-cv">{{ number_format($row['attendance_pct'], 1) }}%</span>
                            <span class="l12-bar"><i style="width:{{ min(100, $row['attendance_pct']) }}%"></i></span>
                        @endif
                    </div>
                    <div class="l12-stat">
                        <span class="l12-cl">Marks Average</span>
                        @if($row['marks_avg'] === null)
                            <span class="l12-cv l12-muted">&mdash;</span>
                        @else
                            <span class="l12-cv">{{ number_format($row['marks_avg'], 1) }}%@if($row['exam']) <small>({{ $row['exam']->name }})</small>@endif</span>
                            <span class="l12-bar l12-bar-marks"><i style="width:{{ min(100, $row['marks_avg']) }}%"></i></span>
                        @endif
                    </div>
                    <div class="l12-stat">
                        <span class="l12-cl">Topper</span>
                        @if($row['topper'])
                            @php
                                $tp = preg_split('/\s+/', trim($row['topper']['name']));
                                $tIni = strtoupper(substr($tp[0], 0, 1) . (count($tp) > 1 ? substr(end($tp), 0, 1) : ''));
                            @endphp
                            <span class="l12-topper"><span class="l12-tav">{{ $tIni }}</span><span class="l12-tbody"><span class="l12-tn">{{ $row['topper']['name'] }}</span><span class="l12-tp">{{ number_format($row['topper']['percent'], 1) }}%</span></span></span>
                        @else
                            <span class="l12-cv l12-muted">&mdash;</span>
                        @endif
                    </div>
                    <div class="l12-stat">
                        <span class="l12-cl">At-Risk</span>
                        <span class="l12-risk {{ count($row['at_risk']) ? 'l12-risk-on' : 'l12-risk-zero' }}">{{ count($row['at_risk']) }} students</span>
                    </div>
                    @if($isDetail)
                        <a href="{{ route('class_overview.index') }}" class="btn btn-light btn-sm l12-vbtn">Hide detail</a>
                    @else
                        <a href="{{ route('class_overview.index', ['class_id' => $row['class']->id]) }}" class="btn btn-light btn-sm l12-vbtn">View detail</a>
                    @endif
                </div>

                @if($isDetail)
                    {{-- Parity (overview-redesign sample, 2026-11-01):
                         the detail is the compact comparison only —
                         one row per section (Section / Strength /
                         Attendance / Marks Avg / At-Risk) plus the
                         Class total row in the same five cells. The
                         summary-sentences paragraph and the
                         per-section Topper cells are gone (the class
                         topper stays on the card above), and the
                         Students needing attention block renders only
                         when the class actually has at-risk students. --}}
                    <div class="l12-detail">
                        <h6 class="l12-dh">{{ $detail['class']->name }} — Detail</h6>

                        @if(!empty($detail['sections']))
                            <h6 class="l12-dh">Section comparison</h6>
                            <div class="l12-srows">
                                @foreach($detail['sections'] as $sec)
                                    <div class="l12-srow">
                                        <span class="l12-sn">Section {{ $sec['name'] }}</span>
                                        <span class="l12-sv">Strength <b>{{ number_format($sec['strength']) }}</b></span>
                                        <span class="l12-sv">Attendance <b>@if($sec['attendance_pct'] === null) &mdash; @else {{ number_format($sec['attendance_pct'], 1) }}% @endif</b></span>
                                        <span class="l12-sv">Marks Avg <b>@if($sec['marks_avg'] === null) &mdash; @else {{ number_format($sec['marks_avg'], 1) }}% @endif</b></span>
                                        <span class="l12-sv">At-Risk <b>{{ $sec['at_risk_count'] }}</b></span>
                                    </div>
                                @endforeach
                                <div class="l12-srow l12-srow-total">
                                    <span class="l12-sn">Class total</span>
                                    <span class="l12-sv">Strength <b>{{ number_format($detail['strength']) }}</b></span>
                                    <span class="l12-sv">Attendance <b>@if($detail['attendance_pct'] === null) &mdash; @else {{ number_format($detail['attendance_pct'], 1) }}% @endif</b></span>
                                    <span class="l12-sv">Marks Avg <b>@if($detail['marks_avg'] === null) &mdash; @else {{ number_format($detail['marks_avg'], 1) }}% @endif</b></span>
                                    <span class="l12-sv">At-Risk <b>{{ count($detail['at_risk']) }}</b></span>
                                </div>
                            </div>
                        @endif

                        @if(!empty($detail['at_risk']))
                            <h6 class="l12-dh">Students needing attention ({{ count($detail['at_risk']) }})</h6>
                            <div class="l12-srows">
                                @foreach($detail['at_risk'] as $student)
                                    <div class="l12-srow">
                                        <span class="l12-sn">{{ $student['name'] }}</span>
                                        <span class="l12-sv">Attendance <b>@if($student['attendance_pct'] === null) &mdash; @else {{ number_format($student['attendance_pct'], 1) }}% @endif</b></span>
                                        <span class="l12-sv">Marks <b>@if($student['marks_pct'] === null) &mdash; @else {{ number_format($student['marks_pct'], 1) }}% @endif</b></span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    @endif
@endsection
