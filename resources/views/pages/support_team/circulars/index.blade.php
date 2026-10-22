@extends('layouts.master')
@section('page_title', 'Circulars')
@section('content')
    @php
        $priorityBadges = ['normal' => 'badge-light', 'important' => 'badge-warning', 'urgent' => 'badge-danger'];
        $statusBadges = ['Live' => 'badge-success', 'Draft' => 'badge-secondary', 'Scheduled' => 'badge-info', 'Expired' => 'badge-light'];
    @endphp
    <div class="card">
        <div class="card-header header-elements-inline">
            @if(Qs::userIsTeamSAWrite())
            <a href="{{ route('circulars.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New Circular</a>
            @endif
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Title</th><th>Audience</th><th>Priority</th><th>Display Window</th><th>Status</th><th>Published By</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse($circulars as $circular)
                        <tr>
                            <td>
                                <a href="{{ route('circulars.show', $circular->id) }}"><strong>{{ $circular->title }}</strong></a>
                                <div class="text-muted">{{ \Illuminate\Support\Str::limit(strip_tags($circular->body), 80) }}</div>
                            </td>
                            <td><span class="badge badge-light">{{ $audiences[$circular->audience] ?? $circular->audience }}</span></td>
                            <td><span class="badge {{ $priorityBadges[$circular->priority] ?? 'badge-light' }}">{{ $priorities[$circular->priority] ?? $circular->priority }}</span></td>
                            <td>
                                {{ $circular->starts_at ? $circular->starts_at->format('d M Y') : 'Immediately' }}
                                – {{ $circular->expires_at ? $circular->expires_at->format('d M Y') : 'No end date' }}
                            </td>
                            <td><span class="badge {{ $statusBadges[$circular->statusLabel()] ?? 'badge-light' }}">{{ $circular->statusLabel() }}</span></td>
                            <td>{{ $circular->publisher->name ?? '—' }}</td>
                            <td style="white-space:nowrap">
                                @if(Qs::userIsTeamSAWrite())
                                <a href="{{ route('circulars.edit', $circular->id) }}" class="btn btn-light btn-sm">Edit</a>
                                <form method="post" action="{{ route('circulars.toggle_publish', $circular->id) }}" style="display:inline">@csrf @method('patch')<button type="submit" class="btn btn-light btn-sm">{{ $circular->is_published ? 'Unpublish' : 'Publish' }}</button></form>
                                    <form method="post" action="{{ route('circulars.destroy', $circular->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this circular?" data-confirm-title="Delete Circular" data-confirm-action="Delete" data-confirm-danger>Delete</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No circulars yet. Create the first one — it will appear in the Circulars feed of its audience.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $circulars->links() }}
        </div>
    </div>
@endsection
