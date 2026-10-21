@extends('layouts.master')
@section('page_title', 'School Events')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">School Events</h6>
            <a href="{{ route('events.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New Event</a>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Title</th><th>Type</th><th>All Day</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse($events as $event)
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
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No events yet. Create the first one — it will appear on the dashboard calendar and Upcoming Events.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $events->links() }}
        </div>
    </div>
@endsection
