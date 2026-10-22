@extends('layouts.master')
@section('page_title', 'Circulars')
@section('content')
    @php $audienceLabels = \App\Models\Circular::audiences(); @endphp
    <div class="card circ-card">
        @forelse($circulars as $circular)
            <article class="circ-item circ-pri-{{ $circular->priority }}">
                <div class="circ-item-top">
                    <h5 class="circ-title">
                        <a href="{{ route('circulars.show', $circular->id) }}">{{ $circular->title }}</a>
                    </h5>
                    <span class="circ-pri-chip circ-pri-chip-{{ $circular->priority }}">@if($circular->priority === 'urgent')<i class="icon-alarm"></i> @endif{{ $priorities[$circular->priority] ?? $circular->priority }}</span>
                </div>
                <div class="circ-meta">
                    <span class="circ-meta-item"><i class="icon-user"></i>{{ $circular->publisher->name ?? 'School Office' }}</span>
                    <span class="circ-meta-item"><i class="icon-calendar3"></i>{{ $circular->created_at->format('d M Y') }}</span>
                    <span class="circ-aud"><i class="icon-users"></i>For {{ $audienceLabels[$circular->audience] ?? $circular->audience }}</span>
                    @if($circular->expires_at)
                        <span class="circ-meta-item circ-valid"><i class="icon-history"></i>Valid until {{ $circular->expires_at->format('d M Y') }}</span>
                    @endif
                </div>
                <p class="circ-excerpt">{{ \Illuminate\Support\Str::limit(strip_tags($circular->body), 220) }}</p>
                <a href="{{ route('circulars.show', $circular->id) }}" class="circ-read">Read Full Circular <i class="icon-arrow-right14"></i></a>
            </article>
        @empty
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-megaphone',
                'message' => 'No circulars for you right now. New notices from the school office will appear here.',
            ])
        @endforelse
        @if($circulars->hasPages())
            <div class="circ-pager">{{ $circulars->links() }}</div>
        @endif
    </div>
@endsection
