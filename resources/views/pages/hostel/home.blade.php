@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('hide_page_header', true)
@section('content')
    @include('partials.role_banner', ['hkRole' => 'hostel', 'hkLabel' => 'Hostel Warden'])
    {{-- Hostel Warden home (Beauty Wave 2, 2026-11-06): the role's
         real home — it previously fell through to the school-wide
         dashboard. Every figure is computed in
         HomeController::hostelHomeData from the hostel ledger the
         same way HostelController reads it: beds are summed room
         capacities, allocated counts StudentRecord rows carrying a
         dorm, and the recent list is those rows latest-touched
         first (an allocation save touches updated_at). --}}
    <div class="dashboard-hero mb-4">
        <div>
            <p class="mb-0 dashboard-muted">Here&rsquo;s your hostel overview for today.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                </span>
            </div>
            <a href="{{ route('hostel.allocations.index') }}" class="btn dashboard-primary-action">
                <i class="icon-users4 mr-2"></i> View Allocations
            </a>
        </div>
    </div>

    <div class="row dashboard-stats hk-reveal">
        @foreach([
            ['label' => 'Dorms', 'value' => number_format($hostel_dorms), 'sub' => number_format($hostel_rooms) . ' rooms in total', 'icon' => 'icon-home9', 'tone' => 'blue', 'href' => route('hostel.dorms.index')],
            ['label' => 'Total Beds', 'value' => number_format($hostel_beds), 'sub' => 'Capacity across all rooms', 'icon' => 'icon-stack', 'tone' => 'purple', 'href' => route('hostel.rooms.index')],
            ['label' => 'Students Allocated', 'value' => number_format($hostel_allocated), 'sub' => 'Currently living in the hostel', 'icon' => 'icon-users4', 'tone' => 'green', 'href' => route('hostel.allocations.index')],
            ['label' => 'Occupancy', 'value' => $hostel_occupancy_pct . '%', 'sub' => 'Of beds filled', 'icon' => 'icon-stats-bars2', 'tone' => 'orange', 'href' => route('hostel.allocations.index')],
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
            <h6 class="card-title font-weight-semibold">Recent Allocations</h6>
        </div>
        @if($hostel_recent->count())
            <div class="table-responsive">
                <table class="table hk-std hk-std-560">
                {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                <colgroup><col style="width:30%"><col style="width:22%"><col style="width:20%"><col style="width:12%"><col style="width:16%"></colgroup>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Dorm</th>
                            <th>Room</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($hostel_recent as $record)
                            <tr>
                                <td>{{ optional($record->user)->name ?: '—' }}</td>
                                <td>{{ optional($record->my_class)->name ?: '—' }}@if($record->section) &middot; {{ $record->section->name }}@endif</td>
                                <td>{{ optional($record->dorm)->name ?: '—' }}</td>
                                <td class="hk-nw">{{ $record->dorm_room_no ?: '—' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('hostel.allocations.index') }}" class="btn btn-sm btn-light">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-home9',
                'message' => 'No students are allocated to the hostel yet.',
                'action_url' => route('hostel.allocations.create'),
                'action_label' => 'Allocate a Student',
            ])
        @endif
    </div>
@endsection
