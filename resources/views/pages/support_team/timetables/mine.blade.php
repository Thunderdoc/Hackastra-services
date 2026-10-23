@extends('layouts.master')
@section('page_title', 'My Timetable')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; My Timetable
@endsection
@section('page_actions')
    @if($mode === 'teacher')
        <a target="_blank" href="{{ route('tt.mine_print') }}" class="btn btn-light"><i class="icon-printer mr-1"></i> Print</a>
        <a href="{{ route('tt.mine_pdf') }}" class="btn btn-light"><i class="icon-file-pdf mr-1"></i> Download PDF</a>
    @elseif($ttr)
        <a target="_blank" href="{{ route('ttr.print', $ttr->id) }}" class="btn btn-primary"><i class="icon-printer mr-1"></i> Print</a>
    @endif
@endsection
@section('content')

    {{-- Loop 81 — My Timetable redesign (teacher mode): summary cards
         from the teacher's own records, a Week/List toggle over the
         SAME dataset, subject tints with a legend (the subject name
         is printed in every cell — colour is never the only cue),
         "Free" for an empty slot inside a day, and the admin-contact
         note. Student/parent modes keep their existing grid partial
         and behaviour inside the same shell. No session selector —
         the topbar owns session display; this is the current
         session's data, and a weekly timetable has no weeks to page. --}}

    @if($mode === 'teacher')
        @php
            $l81Tints = [];
            $l81Subjects = collect($entries ?? [])->pluck('subject')->unique()->values();
            foreach ($l81Subjects as $i => $subj) {
                $l81Tints[$subj] = $i % 6;
            }
            $l81SlotIndex = [];
            foreach ($slots as $i => $slot) {
                $l81SlotIndex[$slot->full] = $i + 1;
            }
        @endphp

        <p class="ma-intro">View your weekly teaching schedule for the current academic session.</p>

        <div class="row dashboard-stats">
            @foreach([
                ['label' => 'Teaching Periods', 'value' => $stats['periods'], 'sub' => 'per week', 'icon' => 'icon-stack', 'tone' => 'blue'],
                ['label' => 'Working Days', 'value' => $stats['work_days'], 'sub' => $stats['days_range'] !== '' ? $stats['days_range'] : 'No days scheduled', 'icon' => 'icon-calendar52', 'tone' => 'green'],
                ['label' => 'Periods per Day', 'value' => $stats['per_day'] !== null ? $stats['per_day'] : 'Varies', 'sub' => $stats['duration'] !== null ? $stats['duration'] . ' minutes each' : 'Durations vary', 'icon' => 'icon-alarm', 'tone' => 'orange'],
                ['label' => 'Classes', 'value' => $stats['classes']->count(), 'sub' => $stats['classes']->isNotEmpty() ? $stats['classes']->implode(', ') . ' · Assigned Classes' : 'None assigned', 'icon' => 'icon-users', 'tone' => 'purple'],
            ] as $stat)
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                        <span class="dashboard-stat-top">
                            <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                        </span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                            <strong>{{ $stat['value'] }}</strong>
                            <small>{{ $stat['sub'] }}</small>
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-body">
                @if($slots->isEmpty())
                    <div class="alert alert-info text-center" style="margin:10px 0 4px">
                        You have no teaching periods scheduled yet.
                    </div>
                @else
                    <div class="l81-mine-toolbar">
                        <p class="ttx-lead" style="margin:0">Weekly Timetable</p>
                        <div class="btn-group l81-view-toggle" role="group" aria-label="Timetable view">
                            <button type="button" class="btn btn-primary btn-sm" id="l81-btn-week" aria-pressed="true"><i class="icon-grid6 mr-1"></i> Week View</button>
                            <button type="button" class="btn btn-light btn-sm" id="l81-btn-list" aria-pressed="false"><i class="icon-list mr-1"></i> List View</button>
                        </div>
                    </div>

                    <div id="l81-week-view">
                        <div class="l7-table-scroll">
                            <table class="table ttx-grid l81-grid">
                                <thead>
                                <tr>
                                    <th class="ttx-day-col">Day</th>
                                    @foreach($slots as $slot)
                                        <th>
                                            <span class="l81-slot-time">{{ $slot->full }}</span>
                                            <span class="l81-slot-period">Period {{ $loop->iteration }}</span>
                                        </th>
                                    @endforeach
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($days as $day)
                                    <tr>
                                        <td class="ttx-day-col"><strong>{{ $day }}</strong></td>
                                        @foreach($slots as $slot)
                                            @php $cell = $map[$day][$slot->full] ?? null; @endphp
                                            @if($cell && !empty($cell['title']))
                                                <td class="l81-cell l81-tint-{{ $l81Tints[$cell['title']] ?? 0 }}">
                                                    <span class="ttx-cell-title">{{ $cell['title'] }}</span>
                                                    @if(!empty($cell['sub']))<span class="ttx-cell-sub">{{ $cell['sub'] }}</span>@endif
                                                </td>
                                            @else
                                                <td class="l81-cell l81-free"><span class="l81-free-label">Free</span></td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if($l81Subjects->count())
                            <div class="l81-legend" aria-label="Subject legend">
                                @foreach($l81Subjects as $subj)
                                    <span class="l81-legend-item"><span class="l81-swatch l81-tint-{{ $l81Tints[$subj] }}"></span> {{ $subj }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div id="l81-list-view" hidden>
                        <div class="l7-table-scroll">
                            <table class="table l81-list-table">
                                <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Time</th>
                                    <th>Period</th>
                                    <th>Subject</th>
                                    <th>Class</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($entries as $e)
                                    <tr>
                                        <td><strong>{{ $e['day'] }}</strong></td>
                                        <td>{{ $e['slot'] }}</td>
                                        <td>Period {{ $l81SlotIndex[$e['slot']] ?? '—' }}</td>
                                        <td><span class="l81-subj-chip l81-tint-{{ $l81Tints[$e['subject']] ?? 0 }}">{{ $e['subject'] }}</span></td>
                                        <td>{{ $e['class'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="l81-note">
                        <i class="icon-info22" aria-hidden="true"></i>
                        <span><strong>Note:</strong> This is your personal teaching timetable for the current academic session. For any changes, please contact the school administrator.</span>
                    </div>
                @endif
            </div>
        </div>

    @else
        <div class="card">
            <div class="card-body">
                @if($mode === 'parent')
                    @if($children->count() > 1)
                        <div class="ttx-switcher">
                            <span class="ttx-switcher-label">Child:</span>
                            @foreach($children as $c)
                                <a href="{{ route('tt.mine', ['child' => $c->id]) }}" class="btn btn-sm {{ $child && $child->id === $c->id ? 'btn-primary' : 'btn-light' }}">{{ $c->user->name }}</a>
                            @endforeach
                        </div>
                    @endif
                    @if($child)
                        <p class="ttx-lead">{{ $child->user->name }} &middot; {{ $child->my_class->name }}@if($child->section) &middot; {{ $child->section->name }}@endif</p>
                    @endif
                @elseif($mode === 'student' && $child)
                    <p class="ttx-lead">{{ $child->my_class->name }}@if($child->section) &middot; Section {{ $child->section->name }}@endif</p>
                @endif

                @if($slots->isEmpty())
                    <div class="alert alert-info text-center" style="margin:10px 0 4px">
                        @if($mode === 'parent' && !$child)
                            No children are linked to your account yet.
                        @else
                            No timetable has been published for this class yet.
                        @endif
                    </div>
                @else
                    @include('pages.support_team.timetables.partials.grid')
                @endif
            </div>
        </div>
    @endif

@endsection

@if($mode === 'teacher')
@section('scripts')
<script>
    (function () {
        var weekBtn = document.getElementById('l81-btn-week');
        var listBtn = document.getElementById('l81-btn-list');
        var week = document.getElementById('l81-week-view');
        var list = document.getElementById('l81-list-view');
        if (!weekBtn || !listBtn || !week || !list) { return; }
        function show(which) {
            var isWeek = which === 'week';
            week.hidden = !isWeek;
            list.hidden = isWeek;
            weekBtn.className = 'btn btn-sm ' + (isWeek ? 'btn-primary' : 'btn-light');
            listBtn.className = 'btn btn-sm ' + (isWeek ? 'btn-light' : 'btn-primary');
            weekBtn.setAttribute('aria-pressed', isWeek ? 'true' : 'false');
            listBtn.setAttribute('aria-pressed', isWeek ? 'false' : 'true');
        }
        weekBtn.addEventListener('click', function () { show('week'); });
        listBtn.addEventListener('click', function () { show('list'); });
    })();
</script>
@endsection
@endif
