{{-- Loop 75: live preview panel for the circular composer.
     Mirrors the feed item (Loop 60 .circ-* classes) from the real
     form values, synced by the script in _form. Branding is the
     school from Settings (Qs::getSystemName) — never hard-coded. --}}
@php
    $pvDate = isset($circular) && $circular->created_at ? $circular->created_at->format('d M Y') : now()->format('d M Y');
    $pvPriority = old('priority', isset($circular) ? $circular->priority : 'normal');
    $pvPriorityLabel = $priorities[$pvPriority] ?? ucfirst($pvPriority);
    $pvAudienceLabel = $audiences[old('audience', isset($circular) ? $circular->audience : 'all')] ?? 'Everyone';
@endphp
<aside class="l75-circ-side">
    <div class="card l75-circ-panel">
        <div class="l75-circ-panel-head">
            <span class="l75-circ-school">{{ Qs::getSystemName() }}</span>
            <span class="l75-live-tag"><i class="icon-eye"></i> Live Preview</span>
        </div>
        <div class="circ-item circ-pri-{{ $pvPriority }}" id="pv_item">
            <div class="circ-item-top">
                <h5 class="circ-title" id="pv_title">{{ old('title', isset($circular) ? $circular->title : '') ?: 'Your circular title' }}</h5>
                <span class="circ-pri-chip circ-pri-chip-{{ $pvPriority }}" id="pv_chip">@if($pvPriority === 'urgent')<i class="icon-alarm"></i> @endif{{ $pvPriorityLabel }}</span>
            </div>
            <div class="circ-meta">
                <span class="circ-meta-item"><i class="icon-user"></i>{{ Auth::user()->name }}</span>
                <span class="circ-meta-item"><i class="icon-calendar3"></i>{{ $pvDate }}</span>
                <span class="circ-aud"><i class="icon-users"></i><span id="pv_aud">For {{ $pvAudienceLabel }}</span></span>
            </div>
            <p class="circ-excerpt" id="pv_body">{{ \Illuminate\Support\Str::limit(strip_tags(old('body', isset($circular) ? $circular->body : '')), 220) ?: 'Your message will appear here, exactly as the audience reads it in their feed.' }}</p>
        </div>
        <div class="l75-circ-panel-foot">This is how the circular appears in the chosen audience's feed.</div>
    </div>
</aside>
