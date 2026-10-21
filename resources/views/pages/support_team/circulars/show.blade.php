@extends('layouts.master')
@section('page_title', 'Circular')
@section('content')
    @php
        $priorityBadges = ['normal' => 'badge-light', 'important' => 'badge-warning', 'urgent' => 'badge-danger'];
        $priorityEdges = ['normal' => '#66788c', 'important' => '#97590f', 'urgent' => '#c0392b'];
    @endphp
    <div class="card" style="border-top:4px solid {{ $priorityEdges[$circular->priority] ?? '#66788c' }}">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">{{ $circular->title }}</h6>
            <span class="badge {{ $priorityBadges[$circular->priority] ?? 'badge-light' }}">{{ $priorities[$circular->priority] ?? $circular->priority }}</span>
        </div>
        <div class="card-body">
            <p class="text-muted">
                For: <strong>{{ $audiences[$circular->audience] ?? $circular->audience }}</strong>
                &middot; Published by {{ $circular->publisher->name ?? 'School Office' }}
                &middot; {{ $circular->created_at->format('d M Y') }}
                @if($circular->starts_at) &middot; From {{ $circular->starts_at->format('d M Y') }} @endif
                @if($circular->expires_at) &middot; Until {{ $circular->expires_at->format('d M Y') }} @endif
                @if(Qs::userIsTeamSA()) &middot; Status: <strong>{{ $circular->statusLabel() }}</strong> @endif
            </p>
            <div>{!! nl2br(e($circular->body)) !!}</div>
            <hr>
            <a href="{{ route('circulars.index') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left13 mr-1"></i> Back to Circulars</a>
            @if(Qs::userIsTeamSA())
                <a href="{{ route('circulars.edit', $circular->id) }}" class="btn btn-primary btn-sm">Edit Circular</a>
            @endif
        </div>
    </div>
@endsection
