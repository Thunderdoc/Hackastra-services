@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('hide_page_header', true)
@section('content')
    @include('partials.role_banner', ['hkRole' => 'nurse', 'hkLabel' => 'Nurse'])
    {{-- Nurse home (owner order 2026-11-06): the clinic board. Every
         figure is computed in HomeController::nurseHomeData from the
         health_records ledger; the recent list carries metadata
         only (date, student, referred badge, recorded-by) — the
         same privacy rule as the health index. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <p class="mb-0 dashboard-muted">Here&rsquo;s your clinic overview for today.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                </span>
            </div>
            <a href="{{ route('health_records.create') }}" class="btn dashboard-primary-action">
                <i class="icon-plus2 mr-2"></i> Record a Visit
            </a>
        </div>
    </div>

    <div class="row dashboard-stats hk-reveal">
        @foreach([
            ['label' => 'Health Records', 'value' => number_format($health_total), 'sub' => 'All clinic visits recorded', 'icon' => 'icon-heart6', 'tone' => 'blue', 'href' => route('health_records.index')],
            ['label' => 'Visits Today', 'value' => number_format($health_today), 'sub' => 'Recorded for today', 'icon' => 'icon-calendar3', 'tone' => 'purple', 'href' => route('health_records.index')],
            ['label' => 'Visits This Month', 'value' => number_format($health_month), 'sub' => 'Calendar month so far', 'icon' => 'icon-list', 'tone' => 'orange', 'href' => route('health_records.index')],
            ['label' => 'Referred', 'value' => number_format($health_referred), 'sub' => 'Sent for outside care', 'icon' => 'icon-alarm', 'tone' => 'red', 'href' => route('health_records.index')],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                <a href="{{ $stat['href'] }}" class="l100-stat-link" aria-label="{{ $stat['label'] }}: {{ $stat['value'] }} — view list">
                    <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                        <span class="dashboard-stat-top">
                            <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                            <span class="l100-stat-arrow"><i class="icon-arrow-right7"></i></span>
                        </span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                            <strong>{{ $stat['value'] }}</strong>
                            <small>{{ $stat['sub'] }}</small>
                        </span>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header">
            <h6 class="card-title font-weight-semibold">Recent Visits</h6>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Referred</th>
                        <th>Recorded By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($health_recent as $record)
                        <tr>
                            <td>{{ $record->visit_date->format('d M Y') }}</td>
                            <td>{{ optional($record->student)->name ?: '—' }}</td>
                            <td>
                                @if($record->is_referred)
                                    <span class="badge badge-warning">Referred</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ optional($record->recorder)->name ?: '—' }}</td>
                            <td class="text-right">
                                <a href="{{ route('health_records.show', $record->id) }}" class="btn btn-sm btn-light">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">No clinic visits recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Beauty Wave 2 second row: the students sent for outside
         care, and the current month at a glance — both computed in
         HomeController::nurseHomeData from the same health_records
         ledger as everything above. The referral rows carry the
         same metadata the clinic works with (date, student, where
         the student was referred to); a month with no visits and a
         referral-free clinic both render honest empty states. --}}
    <div class="row">
        <div class="col-lg-6 d-flex">
            <div class="card w-100">
                <div class="card-header">
                    <h6 class="card-title font-weight-semibold">Referred for Outside Care</h6>
                </div>
                @if($health_referred_list->count())
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student</th>
                                    <th>Referred To</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($health_referred_list as $record)
                                    <tr>
                                        <td>{{ $record->visit_date->format('d M Y') }}</td>
                                        <td>{{ optional($record->student)->name ?: '—' }}</td>
                                        <td>{{ $record->referred_to ?: '—' }}</td>
                                        <td class="text-right">
                                            <a href="{{ route('health_records.show', $record->id) }}" class="btn btn-sm btn-light">View</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-alarm',
                        'message' => 'No students have been referred for outside care yet.',
                    ])
                @endif
            </div>
        </div>
        <div class="col-lg-6 d-flex">
            <div class="card w-100">
                <div class="card-header">
                    <h6 class="card-title font-weight-semibold">This Month at a Glance</h6>
                </div>
                @if($health_month > 0)
                    <div class="card-body">
                        @php $glanceMax = max(1, collect($health_month_weeks)->max('count')); @endphp
                        @foreach($health_month_weeks as $week)
                            <div class="d-flex align-items-center mb-2">
                                <span class="font-weight-semibold" style="flex:0 0 64px">{{ $week['label'] }}</span>
                                <div class="progress flex-fill mx-2" style="height:8px">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width:{{ (int) round($week['count'] / $glanceMax * 100) }}%" aria-valuenow="{{ $week['count'] }}" aria-valuemin="0"></div>
                                </div>
                                <span class="font-weight-semibold text-right" style="flex:0 0 28px">{{ $week['count'] }}</span>
                            </div>
                        @endforeach
                        @if($health_busiest_day)
                            <p class="text-muted mb-0 mt-3">Most visits fall on <strong>{{ $health_busiest_day }}s</strong> — {{ $health_busiest_day_count }} this month.</p>
                        @endif
                    </div>
                @else
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-calendar3',
                        'message' => 'No clinic visits recorded this month yet.',
                    ])
                @endif
            </div>
        </div>
    </div>
@endsection
