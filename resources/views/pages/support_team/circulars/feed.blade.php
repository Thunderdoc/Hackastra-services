@extends('layouts.master')
@section('page_title', 'Circulars')
@section('content')
    @php
        $priorityEdges = ['normal' => '#66788c', 'important' => '#97590f', 'urgent' => '#c0392b'];
        $priorityBadges = ['normal' => 'badge-light', 'important' => 'badge-warning', 'urgent' => 'badge-danger'];
    @endphp
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Circulars &amp; Notices</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @forelse($circulars as $circular)
                <div class="card mb-3" style="border-left:4px solid {{ $priorityEdges[$circular->priority] ?? '#66788c' }}">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <h6 class="card-title mb-1">
                                <a href="{{ route('circulars.show', $circular->id) }}">{{ $circular->title }}</a>
                            </h6>
                            <span class="badge {{ $priorityBadges[$circular->priority] ?? 'badge-light' }} ml-2">{{ $priorities[$circular->priority] ?? $circular->priority }}</span>
                        </div>
                        <p class="text-muted mb-2">
                            {{ $circular->publisher->name ?? 'School Office' }}
                            &middot; {{ $circular->created_at->format('d M Y') }}
                            @if($circular->expires_at) &middot; Valid until {{ $circular->expires_at->format('d M Y') }} @endif
                        </p>
                        <p class="mb-2">{{ \Illuminate\Support\Str::limit(strip_tags($circular->body), 220) }}</p>
                        <a href="{{ route('circulars.show', $circular->id) }}" class="btn btn-light btn-sm">Read Full Circular</a>
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-4">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#66788c" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l14-5v12L3 13v-2z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                    <p class="mt-2 mb-0">No circulars for you right now. New notices from the school office will appear here.</p>
                </div>
            @endforelse
            {{ $circulars->links() }}
        </div>
    </div>
@endsection
