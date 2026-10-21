{{--
    Reusable pure-SVG bar chart (no JS, no chart library).

    Usage:
        @include('partials.charts.bar', [
            'labels' => ['Jan', 'Feb', 'Mar'],
            'values' => [12, 30, 7],
        ])

    Optional params:
        'chartTitle'  string  Accessible title / aria-label (default 'Bar chart')
        'chartColor'  string  Bar colour (default app blue #3478f6)
        'chartHeight' int     SVG height in px (default 180)
        'chartUnit'   string  Suffix appended to value labels (default '')
--}}
@php
    $labels      = $labels ?? [];
    $values      = $values ?? [];
    $chartTitle  = $chartTitle ?? 'Bar chart';
    $chartColor  = $chartColor ?? '#3478f6';
    $chartHeight = $chartHeight ?? 180;
    $chartUnit   = $chartUnit ?? '';
    $count       = count($values);
    $max         = $count ? max($values) : 0;
    // Round the axis ceiling up to a "nice" number so gridlines stay readable.
    if ($max > 0) {
        $pow  = pow(10, floor(log10($max)));
        $max  = ceil($max / $pow) * $pow;
    }
    $W = 560; $H = $chartHeight;
    $padTop = 18; $padBottom = 24; $padX = 10;
    $plotH = $H - $padTop - $padBottom;
    $slot  = $count ? ($W - 2 * $padX) / $count : 0;
    $barW  = $slot ? min(46, $slot * 0.58) : 0;
@endphp
@if($count === 0 || $max <= 0)
    <p class="text-muted text-center mb-0" style="padding:18px 0">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#3478f6" stroke-width="1.5" stroke-linecap="round" style="opacity:.55;display:block;margin:0 auto 6px" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        No data yet.
    </p>
@else
    <svg viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" aria-label="{{ $chartTitle }}" preserveAspectRatio="xMidYMid meet" style="display:block">
        @foreach([0, 0.25, 0.5, 0.75, 1] as $frac)
            @php $gy = $padTop + $plotH * (1 - $frac); @endphp
            <line x1="{{ $padX }}" y1="{{ $gy }}" x2="{{ $W - $padX }}" y2="{{ $gy }}" stroke="#e3e8ef" stroke-width="1"/>
            <text x="{{ $padX }}" y="{{ $gy - 3 }}" font-size="9" fill="#8a94a6">{{ rtrim(rtrim(number_format($max * $frac, 1), '0'), '.') }}</text>
        @endforeach
        @foreach($values as $i => $v)
            @php
                $bh = $max > 0 ? ($v / $max) * $plotH : 0;
                $bx = $padX + $slot * $i + ($slot - $barW) / 2;
                $by = $padTop + $plotH - $bh;
            @endphp
            <rect x="{{ round($bx, 1) }}" y="{{ round($by, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($bh, 1) }}" rx="3" fill="{{ $chartColor }}">
                <title>{{ $labels[$i] ?? '' }}: {{ $v }}{{ $chartUnit }}</title>
            </rect>
            <text x="{{ round($bx + $barW / 2, 1) }}" y="{{ round($by - 4, 1) }}" font-size="10" font-weight="600" fill="#22314a" text-anchor="middle">{{ $v }}{{ $chartUnit }}</text>
            <text x="{{ round($padX + $slot * $i + $slot / 2, 1) }}" y="{{ $H - 8 }}" font-size="9.5" fill="#8a94a6" text-anchor="middle">{{ $labels[$i] ?? '' }}</text>
        @endforeach
    </svg>
@endif
