@extends('layouts.master')
@section('page_title', 'Workload Analysis')
@section('content')

    {{-- Teacher Workload Analysis (owner ask 2026-10-30; redesign
         owner-approved 2026-11-01, workload-redesign sample): the
         teacher's own load from their real week grid against the
         Settings caps — shared module-banner header, four stat
         cards, day-by-day segment bars and where-your-periods-go
         rows. Presentation only: every number is the controller's
         own (LeaveCover::weekGrid enumeration, caps, cover counts,
         led sections). Panels stretch to equal height and their
         rows spread to fill (full-screen rule). No other teacher's
         data is ever shown here.
         Visual-coherence round (2026-11-06): the private l16-head
         slab (centred title, dead space, buttons stranded
         bottom-right) becomes the shared slim module banner, and
         the four l16 tiles become shared dashboard-stat-cards —
         same labels, values, sub-lines and bars, one card system
         with the rest of the site. --}}
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-stats-bars2',
        'hkModTone' => 'blue',
        'hkModTitle' => 'Workload Analysis',
        'hkModSub' => 'Your teaching load from your real timetable — caps: ' . $cap_day . ' periods a day, ' . $cap_week . ' a week ' . (($caps_personal ?? false) ? '(your personal caps)' : '(Settings)'),
        'hkModActions' => '<span style="display:inline-flex;gap:9px;flex-wrap:wrap;"><a href="' . route('tt.mine') . '" class="btn btn-light btn-sm"><i class="icon-calendar5 mr-1"></i> My Timetable</a><a href="' . route('substitutions.mine') . '" class="btn btn-light btn-sm"><i class="icon-user-plus mr-1"></i> My Extra Classes</a></span>',
    ])

    @if($total > $cap_week || $over_days)
        <div class="alert alert-warning mt-3">
            <strong>Over the cap:</strong>
            @if($total > $cap_week)
                your week carries {{ $total }} periods against a weekly cap of {{ $cap_week }}.
            @endif
            @if($over_days)
                {{ implode(', ', $over_days) }} run{{ count($over_days) > 1 ? '' : 's' }} above the daily cap of {{ $cap_day }}.
            @endif
            Ask the admin to rebalance the timetable if this was not planned.
        </div>
    @endif

    <div class="row dashboard-stats" style="margin-bottom:0;">
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-blue">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-stats-bars2"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Periods / Week</span>
                    <strong>{{ $total }} <small>/ {{ $cap_week }} cap</small></strong>
                    <small>{{ $cap_week > 0 ? (int) round($total / $cap_week * 100) : 0 }}% of your weekly limit</small>
                </span>
                <div class="l16-bar"><i style="width:{{ $cap_week > 0 ? min(100, (int) round($total / $cap_week * 100)) : 0 }}%"></i></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-orange">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-fire"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Busiest Day</span>
                    <strong>@if($busiest_day){{ $busiest_day }} <small>· {{ $busiest_count }}</small>@else — @endif</strong>
                    <small>@if(!$busiest_day) No periods allotted yet @elseif($busiest_count > $cap_day) Over the daily cap of {{ $cap_day }} @elseif($busiest_count === $cap_day) Full day — at the daily cap of {{ $cap_day }} @else {{ $busiest_count }} of {{ $cap_day }} periods @endif</small>
                </span>
                <div class="l16-bar"><i style="width:{{ $cap_day > 0 ? min(100, (int) round($busiest_count / $cap_day * 100)) : 0 }}%"></i></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-green">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-user-plus"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Cover Duties Ahead</span>
                    <strong>{{ $cover_upcoming }}</strong>
                    <small>{{ $cover_upcoming === 0 ? 'No confirmed cover duties' : $cover_total . ' confirmed in total' }}</small>
                </span>
                <div class="l16-bar"><i style="width:{{ $cover_total > 0 ? (int) round($cover_upcoming / $cover_total * 100) : 0 }}%"></i></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-stat-card dashboard-stat-red">
                <span class="dashboard-stat-top">
                    <span class="dashboard-stat-icon"><i class="icon-users"></i></span>
                </span>
                <span class="dashboard-stat-copy">
                    <span class="dashboard-stat-label">Sections You Lead</span>
                    <strong>{{ count($led_sections) }}</strong>
                    <small>{{ $led_sections ? implode(', ', $led_sections) : 'No section led' }}</small>
                </span>
                <div class="l16-bar"><i style="width:{{ ($sections_total ?? 0) > 0 ? (int) round(count($led_sections) / $sections_total * 100) : 0 }}%"></i></div>
            </div>
        </div>
    </div>

    @if($total === 0)
        <div class="alert alert-info mt-3">No periods are allotted to you yet — your workload appears here as soon as the timetable assigns you classes.</div>
    @else
        <div class="l16-cols">
            <div class="card l16-panel">
                <h3>Day by day</h3>
                <div class="l16-rows">
                @foreach($by_day as $day => $count)
                    <div class="l16-drow">
                        <span class="l16-dname">{{ $day }}</span>
                        <span class="l16-segs">@for($i = 0; $i < $cap_day; $i++)<span class="l16-seg{{ $i < $count ? ($count >= $cap_day ? ' warm' : ' blue') : '' }}"></span>@endfor</span>
                        <span class="l16-dcount">{{ $count }} of {{ $cap_day }}</span>
                        @if($count > $cap_day)
                            <span class="l16-badge over">Over cap</span>
                        @elseif($count === $cap_day)
                            <span class="l16-badge full">Full day</span>
                        @else
                            <span class="l16-badge ok">Within cap</span>
                        @endif
                    </div>
                @endforeach
                </div>
            </div>
            <div class="card l16-panel">
                <h3>Where your periods go</h3>
                <div class="l16-rows">
                @foreach($by_subject as $row)
                    @php
                        $l16Digits = preg_replace('/[^0-9]/', '', (string) $row['class']);
                        $l16Chip = $l16Digits !== '' ? $l16Digits : strtoupper(substr((string) $row['class'], 0, 1));
                        $l16Section = trim((string) ($row['section'] ?? ''));
                        $l16IsAll = $l16Section === 'All sections';
                        if ($l16Section !== '' && !$l16IsAll) { $l16Chip .= strtoupper(substr($l16Section, 0, 1)); }
                    @endphp
                    <div class="l16-prow">
                        <span class="l16-cchip">{{ $l16Chip }}</span>
                        <span class="l16-pc">{{ $row['class'] }} · {{ $l16IsAll ? 'All sections' : ($l16Section !== '' ? 'Section ' . $l16Section : 'All sections') }}</span>
                        <span class="l16-subj">{{ $row['subject'] }}</span>
                        <span class="l16-pwk">{{ $row['periods'] }}<small>PERIODS / WK</small></span>
                    </div>
                @endforeach
                </div>
                <div class="l16-total"><span>Total teaching load</span><span>{{ $total }} periods / week</span></div>
            </div>
        </div>
    @endif
@endsection
