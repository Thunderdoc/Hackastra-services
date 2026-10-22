@extends('layouts.master')
@section('page_title', 'School Events')
@section('content')

    {{-- Workstream CD: rebuilt on the shared list_page pattern (Loop 28),
         the same chrome as Students / Payments. New Event is the toolbar
         action; rows are byte-identical (date range rule, type pill,
         Edit link, and the teamSA-only Delete form still flowing through
         the styled-confirm helper via its data-confirm attributes); the
         "no events" case is the designed empty state carrying the same
         sentence. Pagination links unchanged. --}}
    @component('partials.patterns.list_page')
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
            {{-- Workstream DE (Loop 73): designed date block, per-type
                 token chips, and the Action column renders ONLY for
                 write roles (it was a dead empty column for everyone
                 else). Same data, same routes, same gating. --}}
            <div class="de-events">
            <table class="table">
                <thead><tr><th>Date</th><th>Title</th><th>Type</th><th>All Day</th>@if(Qs::userIsTeamSAWrite())<th>Action</th>@endif</tr></thead>
                <tbody>
                @foreach($events as $event)
                    @php
                        $evStart = $event->starts_at;
                        $evEnd = ($event->ends_at && !$event->ends_at->equalTo($event->starts_at)) ? $event->ends_at : null;
                        $evSameMonth = $evEnd && $evEnd->format('Y-m') === $evStart->format('Y-m');
                    @endphp
                    <tr>
                        <td>
                            <div class="ev-date">
                                <span class="ev-day">{{ $evStart->format('d') }}@if($evSameMonth)–{{ $evEnd->format('d') }}@endif</span>
                                <span class="ev-mon">{{ $evStart->format('M Y') }}</span>
                                @if($evEnd && !$evSameMonth)<span class="ev-range">to {{ $evEnd->format('d M Y') }}</span>@endif
                            </div>
                        </td>
                        <td><span class="ev-title">{{ $event->title }}</span>@if($event->description)<div class="ev-desc">{{ \Illuminate\Support\Str::limit($event->description, 80) }}</div>@endif</td>
                        <td><span class="ev-chip ev-chip-{{ $event->type }}">{{ $types[$event->type] ?? $event->type }}</span></td>
                        <td>@if($event->all_day)<span class="ev-allday">All Day</span>@else<span class="ev-no">No</span>@endif</td>
                        @if(Qs::userIsTeamSAWrite())
                        <td>
                            <a href="{{ route('events.edit', $event->id) }}" class="btn btn-light btn-sm">Edit</a>
                                <form method="post" action="{{ route('events.destroy', $event->id) }}" style="display:inline" data-confirm="Delete this event? This cannot be undone." data-confirm-title="Delete Event" data-confirm-action="Delete" data-confirm-danger>@csrf @method('delete')<button class="btn btn-light btn-sm text-danger">Delete</button></form>
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            {{ $events->links() }}
        @endif
    @endcomponent
@endsection
