{{-- Slim module banner (Beauty Wave 1): the shared slim sibling of
     partials/role_banner — one white card bar (~76px) with the
     module's pastel icon circle, title and one-line context, plus an
     optional right side (stat row + chip). Formalises the tt3-head /
     l10-head pattern into ONE component.
     Required: $hkModIcon (icomoon class), $hkModSub.
     $hkModTitle is OPTIONAL since Beauty Batch 3 (2026-11-07,
     audit B1/B12): the shared page header owns each page's ONE
     title (settled Loop 46 doctrine), so callers no longer pass a
     title and the h2 renders only for a non-empty one.
     Optional: $hkModTone (blue|green|orange|purple|red, default blue),
     $hkModStats ([['value' => .., 'label' => ..], ...]),
     $hkModChip (text), $hkModChipIcon (icomoon class),
     $hkModRightClass (extra class on the right container),
     $hkModActions (raw HTML — e.g. buttons — rendered at the end
     of the right side; added 2026-11-06 for the Workload header,
     whose two links ride the banner instead of a private slab). --}}
<div class="hk-mod-banner">
    <span class="hk-mod-ic hk-mod-ic-{{ $hkModTone ?? 'blue' }}"><i class="{{ $hkModIcon }}"></i></span>
    <div class="hk-mod-text">
        @if(!empty($hkModTitle))
            <h2 class="hk-mod-title">{{ $hkModTitle }}</h2>
        @endif
        @if(!empty($hkModSub))
            <p class="hk-mod-sub">{{ $hkModSub }}</p>
        @endif
    </div>
    @if(!empty($hkModStats) || !empty($hkModChip) || !empty($hkModActions))
        <div class="hk-mod-right {{ $hkModRightClass ?? '' }}">
            @foreach($hkModStats ?? [] as $hkModStat)
                <div class="hk-mod-stat" style="{{ $loop->first ? 'border:none;' : '' }}"><b>{{ $hkModStat['value'] }}</b><span>{{ $hkModStat['label'] }}</span></div>
            @endforeach
            @if(!empty($hkModChip))
                <span class="hk-mod-chip">
                    @if(!empty($hkModChipIcon))
                        <i class="{{ $hkModChipIcon }}"></i>
                    @endif
                    {{ $hkModChip }}
                </span>
            @endif
            @if(!empty($hkModActions))
                {!! $hkModActions !!}
            @endif
        </div>
    @endif
</div>
