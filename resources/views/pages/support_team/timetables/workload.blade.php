@extends('layouts.master')
@section('page_title', 'Workload Analysis')
@section('content')

    {{-- Teacher Workload Analysis (owner ask 2026-10-30): the
         teacher's own load from their real week grid — the same
         enumeration the leave queue uses — against the Settings
         caps, with their confirmed cover duties and the sections
         they lead. No other teacher's data is ever shown here. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Your teaching load from your real timetable — caps: {{ $cap_day }} periods a day, {{ $cap_week }} a week (Settings).
        @endslot
        @slot('action')
            <a href="{{ route('tt.mine') }}" class="btn btn-light btn-sm"><i class="icon-calendar5 mr-1"></i> My Timetable</a>
            <a href="{{ route('substitutions.mine') }}" class="btn btn-light btn-sm"><i class="icon-user-plus mr-1"></i> My Extra Classes</a>
        @endslot

        <div class="marx-stats">
            <div class="marx-stat marx-stat-periods">
                <div class="marx-stat-num">{{ $total }}</div>
                <div class="marx-stat-label">Periods / Week</div>
                <div class="marx-stat-cap">Weekly cap: {{ $cap_week }}</div>
            </div>
            <div class="marx-stat marx-stat-present">
                <div class="marx-stat-num">{{ $busiest_count }}</div>
                <div class="marx-stat-label">Busiest Day{{ $busiest_day ? ' — ' . $busiest_day : '' }}</div>
                <div class="marx-stat-cap">Daily cap: {{ $cap_day }} periods</div>
            </div>
            <div class="marx-stat marx-stat-late">
                <div class="marx-stat-num">{{ $cover_upcoming }}</div>
                <div class="marx-stat-label">Cover Duties Ahead</div>
                <div class="marx-stat-cap">{{ $cover_total }} confirmed in total</div>
            </div>
            <div class="marx-stat marx-stat-absent">
                <div class="marx-stat-num">{{ count($led_sections) }}</div>
                <div class="marx-stat-label">Sections You Lead</div>
                <div class="marx-stat-cap">{{ $led_sections ? implode(', ', $led_sections) : 'No section led' }}</div>
            </div>
        </div>

        @if($total > $cap_week || $over_days)
            <div class="alert alert-warning">
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

        @if($total === 0)
            <div class="alert alert-info">No periods are allotted to you yet — your workload appears here as soon as the timetable assigns you classes.</div>
        @else
            <div class="row">
                <div class="col-lg-5">
                    <h6 class="font-weight-semibold">Day by day</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Day</th><th>Periods</th><th>Status</th></tr></thead>
                            <tbody>
                            @foreach($by_day as $day => $count)
                                <tr>
                                    <td>{{ $day }}</td>
                                    <td>{{ $count }} of {{ $cap_day }}</td>
                                    <td>
                                        @if($count > $cap_day)
                                            <span class="badge badge-danger">Over cap</span>
                                        @elseif($count === $cap_day)
                                            <span class="badge badge-warning">Full day</span>
                                        @else
                                            <span class="badge badge-success">Within cap</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-7">
                    <h6 class="font-weight-semibold">Where your periods go</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Class</th><th>Section</th><th>Subject</th><th>Periods / week</th></tr></thead>
                            <tbody>
                            @foreach($by_subject as $row)
                                <tr>
                                    <td>{{ $row['class'] }}</td>
                                    <td>{{ $row['section'] }}</td>
                                    <td>{{ $row['subject'] }}</td>
                                    <td>{{ $row['periods'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <h6 class="font-weight-semibold mt-3">Your week, period by period</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Day</th><th>Period</th><th>Class</th><th>Section</th><th>Subject</th><th>Room</th></tr></thead>
                    <tbody>
                    @foreach($grid as $row)
                        <tr>
                            <td>{{ $row['day'] }}</td>
                            <td>{{ $row['period'] ?? '—' }}</td>
                            <td>{{ $row['class'] }}</td>
                            <td>{{ $row['section'] }}</td>
                            <td>{{ $row['subject'] }}</td>
                            <td>{{ $row['room'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
@endsection
