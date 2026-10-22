@extends('layouts.master')
@section('page_title', 'Circular')
@section('content')
    <article class="card circ-doc circ-pri-{{ $circular->priority }}">
        <div class="circ-doc-head">
            <div class="circ-item-top">
                <h4 class="circ-doc-title">{{ $circular->title }}</h4>
                <span class="circ-pri-chip circ-pri-chip-{{ $circular->priority }}">@if($circular->priority === 'urgent')<i class="icon-alarm"></i> @endif{{ $priorities[$circular->priority] ?? $circular->priority }}</span>
            </div>
            <div class="circ-meta">
                <span class="circ-aud"><i class="icon-users"></i>For {{ $audiences[$circular->audience] ?? $circular->audience }}</span>
                <span class="circ-meta-item"><i class="icon-user"></i>Published by {{ $circular->publisher->name ?? 'School Office' }}</span>
                <span class="circ-meta-item"><i class="icon-calendar3"></i>{{ $circular->created_at->format('d M Y') }}</span>
                @if($circular->starts_at)
                    <span class="circ-meta-item"><i class="icon-calendar3"></i>From {{ $circular->starts_at->format('d M Y') }}</span>
                @endif
                @if($circular->expires_at)
                    <span class="circ-meta-item circ-valid"><i class="icon-history"></i>Valid until {{ $circular->expires_at->format('d M Y') }}</span>
                @endif
                @if(Qs::userIsTeamSA())
                    <span class="circ-meta-item"><i class="icon-eye"></i>Status: <strong>{{ $circular->statusLabel() }}</strong></span>
                @endif
            </div>
        </div>
        <div class="circ-doc-body">{!! nl2br(e($circular->body)) !!}</div>
        <div class="circ-doc-foot">
            <a href="{{ route('circulars.index') }}" class="btn btn-light"><i class="icon-arrow-left13 mr-1"></i> Back to Circulars</a>
            @if(Qs::userIsTeamSAWrite())
                <a href="{{ route('circulars.edit', $circular->id) }}" class="btn btn-primary">Edit Circular</a>
            @endif
        </div>
    </article>
@endsection
