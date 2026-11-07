@extends('layouts.master')
@section('page_title', 'Progress')
@section('hide_page_header', true)
@section('content')
    @include('partials.role_banner', ['hkRole' => 'parent', 'hkLabel' => 'Parent'])
    {{-- F2 — Parent Progress (design §4.4): per-child marks trend
         across assessment periods (published results only) and a
         per-month attendance trend. Every figure comes from
         App\Support\StudentProgress via MyParent\ProgressController;
         this view computes nothing. No JS — the subject tables use
         native <details>, the switcher is plain links. --}}

    @if($children->isEmpty())
        <section class="p96-panel mb-4">
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users4',
                'message' => 'No children are linked to your account yet. Once the school links your child’s record to your account, their attendance, marks and fee status will appear here. If your child is already enrolled, please contact the school office and mention your registered email address.',
            ])
        </section>
    @else
        @php($sp = $selected_progress)
        @php($record = $selected)
        @php($words = preg_split('/\s+/', trim(optional($record->user)->name ?? '')))
        @php($ini = strtoupper(substr($words[0] ?? '', 0, 1).(isset($words[1]) ? substr($words[1], 0, 1) : '')))

        @if($children->count() > 1)
            <div class="f2-switcher" role="navigation" aria-label="Choose a child">
                @foreach($children as $child)
                    <a href="{{ route('my_progress', ['child' => $child->id]) }}" class="f2-chip{{ $child->id === $record->id ? ' f2-chip-active' : '' }}">
                        {{ optional($child->user)->name }} &middot; {{ optional($child->my_class)->name ?: 'Class —' }}
                    </a>
                @endforeach
            </div>
        @endif

        <section class="p96-panel f2-child-head">
            <span class="p96-avatar" aria-hidden="true">{{ $ini }}@if(Qs::hasRealPhoto(optional($record->user)->photo))<img src="{{ $record->user->photo }}" alt="" onerror="this.remove()">@endif</span>
            <div class="f2-child-id">
                <h5>{{ optional($record->user)->name }}</h5>
                <p>{{ optional($record->my_class)->name ?: 'Class —' }} &middot; {{ optional($record->section)->name ?: 'Section —' }}@if($record->adm_no) &middot; Adm No: {{ $record->adm_no }} @endif</p>
            </div>
            <div class="f2-head-actions">
                <a href="{{ route('marks.year_selector', Qs::hash($record->user_id)) }}" class="btn btn-primary btn-sm"><i class="icon-stats-bars"></i> Marksheet</a>
                <a href="{{ route('attendance.my', ['student_id' => $record->id]) }}" class="btn btn-light btn-sm"><i class="icon-calendar52"></i> Attendance</a>
            </div>
        </section>

        {{-- SECTION 1 — Results progress --}}
        <section class="p96-panel f2-section">
            <div class="p96-panel-head">
                <div class="p96-panel-title">
                    <span class="p96-panel-icon p96-tone-blue"><i class="icon-stats-bars"></i></span>
                    <div>
                        <h5>Results Progress</h5>
                        <p class="p96-panel-sub">Published results only &middot; {{ $session }} &middot; Overall % is the average across subjects</p>
                    </div>
                </div>
            </div>

            @if($sp['fees_blocked'])
                <div class="f2-fee-notice">
                    <i class="icon-lock"></i>
                    <div>
                        <h5>Progress Report Cannot Be Shown Yet</h5>
                        <p>The Progress Report for <strong>{{ optional($record->user)->name }}</strong>@if($record->adm_no) ({{ $record->adm_no }})@endif is on hold because a fee balance of <strong>{{ \App\Helpers\Money::inr($sp['fees_due']) }}</strong> is still outstanding for the <strong>{{ $session }}</strong> session. Results are held until fees are cleared — please pay the fee and the results will appear here automatically.</p>
                        <a href="{{ route('my_fees.show', $record->user_id) }}" class="btn btn-primary btn-sm mt-2"><i class="icon-coins"></i> Pay the Fee</a>
                    </div>
                </div>
            @elseif(empty($sp['periods']))
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-eye-blocked',
                    'message' => 'No results have been published for ' . $session . ' yet. Results appear here as soon as the school publishes them after each assessment period.',
                    'compact' => true,
                ])
            @else
                @php($points = $sp['periods'])
                @if(count($points) >= 2)
                    <div class="f2-chart">
                        @include('partials.charts.bar', [
                            'labels' => array_map(function ($p) { return $p['period_name']; }, $points),
                            'values' => array_map(function ($p) { return $p['pct']; }, $points),
                            'chartUnit' => '%',
                            'chartTitle' => 'Average percentage by assessment period for ' . optional($record->user)->name,
                        ])
                    </div>
                @else
                    <p class="f2-muted-line">The trend chart appears once two or more periods are published.</p>
                @endif

                @foreach(array_reverse($points) as $idx => $point)
                    <div class="f2-period">
                        <div class="f2-period-head">
                            <strong>{{ $point['period_name'] }}</strong>
                            @if($point['period_kind'] === 'standalone')
                                <span class="f2-badge">Standalone</span>
                            @endif
                            <span class="f2-period-pct">{{ number_format($point['pct'], 1) }}%</span>
                            @if($point['grade'])
                                <span class="f2-grade-chip">Grade {{ $point['grade'] }}</span>
                            @endif
                            @if($point['delta'] !== null)
                                <span class="f2-delta">{{ sprintf('%+.1f pts', $point['delta']) }} vs previous</span>
                            @endif
                        </div>
                        <div class="pr-bar-row f2-bar-row">
                            <span class="pr-bar-track"><span class="pr-bar-fill" style="width: {{ $point['pct'] }}%"></span></span>
                            <span class="pr-bar-val">{{ number_format($point['pct'], 1) }}%</span>
                        </div>
                        @if($point['fed_by_unit'])
                            <p class="f2-unit-note">Unit-test marks are included within this period&rsquo;s Internal Assessment.</p>
                        @endif
                        <details class="f2-subjects"{{ $idx === 0 ? ' open' : '' }}>
                            <summary>Subject-wise marks</summary>
                            <table class="table l108-stack f2-table">
                                <thead>
                                <tr><th>Subject</th><th>Total</th><th>Max</th><th>%</th><th>Grade</th><th>Remark</th></tr>
                                </thead>
                                <tbody>
                                @foreach($point['subjects'] as $sub)
                                    <tr>
                                        <td data-label="Subject">{{ $sub['name'] }}</td>
                                        <td data-label="Total">{{ $sub['total'] === null ? '—' : $sub['total'] }}</td>
                                        <td data-label="Max">{{ $sub['max'] }}</td>
                                        <td data-label="%">{{ $sub['pct'] === null ? '—' : number_format($sub['pct'], 1) . '%' }}</td>
                                        <td data-label="Grade">{{ $sub['grade'] ?: '—' }}</td>
                                        <td data-label="Remark">{{ $sub['total'] === null ? 'Not entered' : ($sub['remark'] ?: '—') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </details>
                        <p class="f2-foot-link"><a href="{{ route('marks.show', [Qs::hash($record->user_id), $session]) }}">Full marksheet for this period</a></p>
                    </div>
                @endforeach
            @endif
        </section>

        {{-- SECTION 2 — Attendance --}}
        <section class="p96-panel f2-section">
            <div class="p96-panel-head">
                <div class="p96-panel-title">
                    <span class="p96-panel-icon p96-tone-green"><i class="icon-calendar52"></i></span>
                    <div>
                        <h5>Attendance</h5>
                        <p class="p96-panel-sub">
                            @if($sp['attendance']['session']['percent'] !== null)
                                Attendance this session: {{ $sp['attendance']['session']['percent'] }}% ({{ $sp['attendance']['session']['present'] + $sp['attendance']['session']['late'] }} of {{ $sp['attendance']['session']['marked'] }} periods present)
                            @else
                                Attendance this session: —
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            @if($sp['attendance']['session']['percent'] !== null && $sp['attendance']['session']['percent'] < 75)
                <div class="p96-note">
                    <i class="icon-warning2"></i>
                    <span>Attendance is <strong>{{ $sp['attendance']['session']['percent'] }}%</strong> this session — below the 75% mark.
                        <a href="{{ route('attendance.my', ['student_id' => $record->id]) }}">See the day-by-day record</a></span>
                </div>
            @endif

            @if(empty($sp['attendance']['months']))
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-calendar52',
                    'message' => 'No attendance registers have been marked yet this session.',
                    'compact' => true,
                ])
            @else
                <table class="table l108-stack f2-table">
                    <thead>
                    <tr><th>Month</th><th>Present</th><th>Late</th><th>Absent</th><th>Marked</th><th>Attendance</th><th>%</th></tr>
                    </thead>
                    <tbody>
                    @foreach($sp['attendance']['months'] as $month)
                        <tr>
                            <td data-label="Month">{{ $month['label'] }}</td>
                            <td data-label="Present">{{ $month['present'] }}</td>
                            <td data-label="Late">{{ $month['late'] }}</td>
                            <td data-label="Absent">{{ $month['absent'] }}</td>
                            <td data-label="Marked">{{ $month['marked'] }}</td>
                            <td data-label="Attendance">
                                <div class="pr-bar-row f2-bar-row">
                                    <span class="pr-bar-track"><span class="pr-bar-fill" style="width: {{ $month['percent'] }}%"></span></span>
                                </div>
                            </td>
                            <td data-label="%">{{ $month['percent'] }}%</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="f2-foot-link"><a href="{{ route('attendance.my', ['student_id' => $record->id]) }}">Day-by-day record</a></p>
            @endif
        </section>

        <p class="f2-scope-line">Showing {{ $session }}. For earlier sessions, open the <a href="{{ route('marks.year_selector', Qs::hash($record->user_id)) }}">Marksheet</a>.</p>
    @endif
@endsection
