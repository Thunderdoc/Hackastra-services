@extends('layouts.master')
@section('page_title', 'School Events')
@section('content')

    {{-- School Events — owner redesign (2026-10-23): summary chips
         over the whole set, then event CARDS grouped by month with
         a designed date block, status pill (derived from the real
         dates), type chip and the same Edit/Delete actions + gating
         as before. Data, routes and pagination unchanged. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Everything happening in the school — exams, meetings, fee dates and celebrations.
        @endslot
        @slot('action')
            @if(Qs::userIsTeamSAWrite())
            <a href="{{ route('events.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New Event</a>
            @endif
        @endslot

        @if($events->count() === 0)
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-calendar3',
                'message' => 'No events yet. Create the first one — it will appear on the dashboard calendar and Upcoming Events.',
                'action_url' => Qs::userIsTeamSAWrite() ? route('events.create') : null,
                'action_label' => 'New Event',
            ])
        @else
            <div class="marx-stats">
                <div class="marx-stat marx-stat-periods">
                    <div class="marx-stat-num">{{ $ev_stats['total'] }}</div>
                    <div class="marx-stat-label">Total Events</div>
                    <div class="marx-stat-cap">On the school calendar</div>
                </div>
                <div class="marx-stat marx-stat-present">
                    <div class="marx-stat-num">{{ $ev_stats['upcoming'] }}</div>
                    <div class="marx-stat-label">Upcoming</div>
                    <div class="marx-stat-cap">Still to come</div>
                </div>
                <div class="marx-stat marx-stat-late">
                    <div class="marx-stat-num">{{ $ev_stats['this_month'] }}</div>
                    <div class="marx-stat-label">This Month</div>
                    <div class="marx-stat-cap">{{ now()->format('F Y') }}</div>
                </div>
                <div class="marx-stat marx-stat-registers">
                    <div class="marx-stat-num" style="font-size:17px;line-height:1.25">{{ $ev_stats['next'] ? $ev_stats['next']->starts_at->format('d M') : '—' }}</div>
                    <div class="marx-stat-label">Next Event</div>
                    <div class="marx-stat-cap">{{ $ev_stats['next'] ? \Illuminate\Support\Str::limit($ev_stats['next']->title, 34) : 'Nothing scheduled' }}</div>
                </div>
            </div>

            @php $evxMonth = null; $evxToday = now()->startOfDay(); @endphp
            <div class="evx-list">
            @foreach($events as $event)
                @php
                    $evStart = $event->starts_at;
                    $evEnd = ($event->ends_at && !$event->ends_at->equalTo($event->starts_at)) ? $event->ends_at : null;
                    $evEffectiveEnd = $evEnd ?: $evStart;
                    $evStatus = $evEffectiveEnd->lt($evxToday) ? ['Past', 'evx-st-past']
                        : ($evStart->lte($evxToday) ? ['Happening now', 'evx-st-now'] : ['Upcoming', 'evx-st-up']);
                    $evMonthKey = $evStart->format('F Y');
                @endphp
                @if($evMonthKey !== $evxMonth)
                    @php $evxMonth = $evMonthKey; @endphp
                    <div class="evx-month">{{ $evMonthKey }}</div>
                @endif
                <div class="card evx-card">
                    <div class="ev-date">
                        <span class="ev-day">{{ $evStart->format('d') }}@if($evEnd && $evEnd->format('Y-m') === $evStart->format('Y-m'))–{{ $evEnd->format('d') }}@endif</span>
                        <span class="ev-mon">{{ $evStart->format('M Y') }}</span>
                        @if($evEnd && $evEnd->format('Y-m') !== $evStart->format('Y-m'))<span class="ev-range">to {{ $evEnd->format('d M Y') }}</span>@endif
                    </div>
                    <div class="evx-body">
                        <div class="evx-title-row">
                            <span class="ev-title">{{ $event->title }}</span>
                            <span class="evx-status {{ $evStatus[1] }}">{{ $evStatus[0] }}</span>
                            <span class="ev-chip ev-chip-{{ $event->type }}">{{ $types[$event->type] ?? $event->type }}</span>
                            @if($event->all_day)<span class="ev-allday">All Day</span>@endif
                        </div>
                        @if($event->description)<div class="ev-desc">{{ \Illuminate\Support\Str::limit($event->description, 160) }}</div>@endif
                        <div class="evx-meta">
                            <i class="icon-calendar52 mr-1"></i>{{ $evStart->format('l, d M Y') }}@if($evEnd) – {{ $evEnd->format('l, d M Y') }}@endif
                        </div>
                    </div>
                    @if(Qs::userIsTeamSAWrite())
                    <div class="evx-actions">
                        <a href="{{ route('events.edit', $event->id) }}" class="btn btn-light btn-sm">Edit</a>
                        <form method="post" action="{{ route('events.destroy', $event->id) }}" style="display:inline" data-confirm="Delete this event? This cannot be undone." data-confirm-title="Delete Event" data-confirm-action="Delete" data-confirm-danger>@csrf @method('delete')<button class="btn btn-light btn-sm text-danger">Delete</button></form>
                    </div>
                    @endif
                </div>
            @endforeach
            </div>
            {{ $events->links() }}
        @endif
    @endcomponent
@endsection
