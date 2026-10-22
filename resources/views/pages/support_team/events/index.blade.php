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
            <a href="{{ route('events.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New Event</a>
        @endslot

        @if(session('flash_success'))
            <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
        @endif

        @if($events->count() === 0)
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-calendar3',
                'message' => 'No events yet. Create the first one — it will appear on the dashboard calendar and Upcoming Events.',
                'action_url' => route('events.create'),
                'action_label' => 'New Event',
            ])
        @else
            <table class="table">
                <thead><tr><th>Date</th><th>Title</th><th>Type</th><th>All Day</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($events as $event)
                    <tr>
                        <td>{{ $event->starts_at->format('d M Y') }}@if($event->ends_at && !$event->ends_at->equalTo($event->starts_at)) – {{ $event->ends_at->format('d M Y') }}@endif</td>
                        <td><strong>{{ $event->title }}</strong>@if($event->description)<div class="text-muted">{{ \Illuminate\Support\Str::limit($event->description, 80) }}</div>@endif</td>
                        <td><span class="badge badge-light">{{ $types[$event->type] ?? $event->type }}</span></td>
                        <td>{{ $event->all_day ? 'Yes' : 'No' }}</td>
                        <td>
                            <a href="{{ route('events.edit', $event->id) }}" class="btn btn-light btn-sm">Edit</a>
                            @if(Qs::userIsTeamSA())
                                <form method="post" action="{{ route('events.destroy', $event->id) }}" style="display:inline" data-confirm="Delete this event? This cannot be undone." data-confirm-title="Delete Event" data-confirm-action="Delete" data-confirm-danger>@csrf @method('delete')<button class="btn btn-light btn-sm text-danger">Delete</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $events->links() }}
        @endif
    @endcomponent
@endsection
