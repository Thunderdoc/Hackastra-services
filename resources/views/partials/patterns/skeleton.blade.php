{{--
    partials/patterns/skeleton — the shared loading shapes
    (Round 5 Slice 1, P2).

    THIS KIT IS THE ONE REGION LOADING STANDARD (Round 5 Slice 3,
    P5): async regions use these shapes via window.hkSkel /
    hkSkelHtml; buttons use the shared .btn.is-loading spinner.
    There is deliberately NO page-level loader — pages are
    server-rendered, so a page overlay would never paint.

    Server-rendered placeholder shown while real content is in
    flight. Data-driven, like partials/patterns/empty_state:

        @include('partials.patterns.skeleton', [
            'type' => 'table',   // table | stats | card | frame
            'rows' => 6,         // rows (table) / lines (card, frame)
        ])

    The shapes are aria-hidden decoration; the REGION hosting them
    carries aria-busy (window.hkSkel manages it for AJAX regions).
    Client-side, window.hkSkelHtml(type, rows) renders the same
    markup — the two must stay in step.
--}}
@php
    $hkSkelType = $type ?? 'card';
    $hkSkelRows = max(1, (int) ($rows ?? 5));
@endphp
<div class="hk-skel-wrap" aria-hidden="true">
    @if($hkSkelType === 'table')
        @for($i = 0; $i < $hkSkelRows; $i++)
            <div class="hk-skel-row"><span class="hk-skel" style="width:34px;height:34px;border-radius:50%"></span><span class="hk-skel hk-skel-line" style="flex:2"></span><span class="hk-skel hk-skel-line" style="flex:3"></span><span class="hk-skel hk-skel-line" style="flex:1"></span><span class="hk-skel hk-skel-line" style="flex:1"></span></div>
        @endfor
    @elseif($hkSkelType === 'stats')
        <div class="hk-skel-stats">
            @for($i = 0; $i < 4; $i++)
                <div class="hk-skel-stat"><span class="hk-skel" style="width:44px;height:44px;border-radius:50%"></span><span style="flex:1"><span class="hk-skel hk-skel-line"></span><span class="hk-skel hk-skel-line"></span></span></div>
            @endfor
        </div>
    @elseif($hkSkelType === 'frame')
        <div class="hk-skel-frame">
            <span class="hk-skel hk-skel-line hk-skel-title"></span>
            @for($i = 0; $i < $hkSkelRows; $i++)
                <span class="hk-skel hk-skel-line"></span>
            @endfor
        </div>
    @else
        <span class="hk-skel hk-skel-line hk-skel-title"></span>
        @for($i = 0; $i < $hkSkelRows; $i++)
            <span class="hk-skel hk-skel-line"></span>
        @endfor
    @endif
</div>
