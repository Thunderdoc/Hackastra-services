@extends('layouts.master')
@section('page_title', 'School Events')
@section('content')

    {{-- Student events portal (2026-10-23): school events, read-only,
         upcoming first. Events are school-wide (the SchoolEvent
         model carries no per-role audience), so every student sees
         the same list the staff calendar holds. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            What is happening at school — upcoming events first.
        @endslot

        <h6 class="font-weight-bold">Upcoming Events</h6>
        @if($upcoming->count())
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($upcoming as $event)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="font-weight-bold">{{ $event->title }}</td>
                            <td><span class="badge badge-light">{{ $event->type ?: 'Event' }}</span></td>
                            <td style="white-space:nowrap">
                                {{ $event->starts_at ? $event->starts_at->format('d M Y') : '—' }}
                                @if($event->ends_at && $event->starts_at && $event->ends_at->ne($event->starts_at))
                                    &ndash; {{ $event->ends_at->format('d M Y') }}
                                @endif
                                @if($event->all_day)<span class="badge badge-info ml-1">All day</span>@endif
                            </td>
                            <td>{{ $event->description ? \Illuminate\Support\Str::limit($event->description, 120) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-calendar3',
                'message' => 'No upcoming events right now. Watch this space!',
            ])
        @endif

        @if($past->count())
            <h6 class="font-weight-bold mt-4">Past Events</h6>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($past as $event)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="font-weight-bold">{{ $event->title }}</td>
                            <td><span class="badge badge-light">{{ $event->type ?: 'Event' }}</span></td>
                            <td style="white-space:nowrap">
                                {{ $event->starts_at ? $event->starts_at->format('d M Y') : '—' }}
                                @if($event->ends_at && $event->starts_at && $event->ends_at->ne($event->starts_at))
                                    &ndash; {{ $event->ends_at->format('d M Y') }}
                                @endif
                            </td>
                            <td>{{ $event->description ? \Illuminate\Support\Str::limit($event->description, 120) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
@endsection
