{{--
    FITTED week grid (LMS build, 2026-11-08, owner-approved
    My Week sample — the image-143 fix). Same data contract as
    partials.grid ($days, $slots, $map[day][slot full] =
    ['title','sub'], optional $day_labels) but the layout FITS:
    table-layout:fixed with weighted columns (day 7.5%, each
    Break/Lunch gap 5.2%, teaching periods share the rest —
    10.26% each on the standard 8-period day), cells wrap to two
    lines and NEVER truncate, zero sideways scroll. At phone
    widths the same data renders as a per-day stacked list
    (time · subject · teacher, slim gap bars, day summary line).
    Clock gaps between slots render as Break (<30 min) / Lunch
    columns, exactly like the shared grid's rule.
--}}
@php
    $mwSlots = collect($slots)->sortBy(function ($s) { return strtotime($s->time_from); })->values();
    $mwCols = [];
    $mwPrev = null;
    foreach ($mwSlots as $mwSlot) {
        if ($mwPrev) {
            $mwGap = (strtotime($mwSlot->time_from) - strtotime($mwPrev->time_to)) / 60;
            if ($mwGap > 0) {
                $mwCols[] = ['gap' => true, 'label' => $mwGap >= 30 ? 'Lunch' : 'Break', 'range' => $mwPrev->time_to . ' – ' . $mwSlot->time_from];
            }
        }
        $mwCols[] = ['gap' => false, 'slot' => $mwSlot];
        $mwPrev = $mwSlot;
    }
    $mwTeachCount = collect($mwCols)->where('gap', false)->count();
    $mwGapCount = collect($mwCols)->where('gap', true)->count();
    $mwTeachW = $mwTeachCount > 0 ? (100 - 7.5 - 5.2 * $mwGapCount) / $mwTeachCount : 10;
    $mwToday = \Carbon\Carbon::now()->format('l');
    // Per-day period counts (phone day-summary lines).
    $mwDayCounts = [];
    foreach ($days as $mwDay) {
        $n = 0;
        foreach ($mwCols as $mwCol) {
            if (!$mwCol['gap']) {
                $c = $map[$mwDay][$mwCol['slot']->full] ?? null;
                if ($c && !empty($c['title'])) { $n++; }
            }
        }
        $mwDayCounts[$mwDay] = $n;
    }
    $mwMaxDay = $mwDayCounts ? max($mwDayCounts) : 0;
@endphp

<div class="mw-grid-wrap">
    <table class="table mw-week-table">
        <colgroup>
            <col style="width:7.5%">
            @foreach($mwCols as $mwCol)
                <col style="width:{{ $mwCol['gap'] ? '5.2' : number_format($mwTeachW, 2) }}%">
            @endforeach
        </colgroup>
        <thead>
        <tr>
            <th class="mw-day-col">Day</th>
            @foreach($mwCols as $mwCol)
                @if($mwCol['gap'])
                    <th class="mw-gap-col"><span class="mw-gap-label">{{ $mwCol['label'] }}</span><span class="mw-gap-range">{{ $mwCol['range'] }}</span></th>
                @else
                    <th>{{ $mwCol['slot']->full }}</th>
                @endif
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach($days as $day)
            <tr class="{{ $day === $mwToday ? 'mw-today' : '' }}">
                <td class="mw-day-col"><strong>{{ $day_labels[$day] ?? $day }}</strong>@if($day === $mwToday) <span class="ttx-today-chip">Today</span>@endif</td>
                @foreach($mwCols as $mwCol)
                    @if($mwCol['gap'])
                        <td class="mw-gap-col"><span class="mw-gap-label">{{ $mwCol['label'] }}</span></td>
                    @else
                        @php $cell = $map[$day][$mwCol['slot']->full] ?? null; @endphp
                        <td class="mw-cell">
                            @if($cell && !empty($cell['title']))
                                <span class="mw-cell-title">{{ $cell['title'] }}</span>
                                @if(!empty($cell['sub']))<span class="mw-cell-sub">{{ $cell['sub'] }}</span>@endif
                            @else
                                <span class="ttx-empty">&mdash;</span>
                            @endif
                        </td>
                    @endif
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="mw-daylist">
    @foreach($days as $day)
        <div class="mw-day-block {{ $day === $mwToday ? 'mw-today' : '' }}">
            <div class="mw-day-head">
                <strong>{{ $day_labels[$day] ?? $day }}</strong>@if($day === $mwToday) <span class="ttx-today-chip">Today</span>@endif
                <span class="mw-day-count">{{ $mwDayCounts[$day] }} period{{ $mwDayCounts[$day] === 1 ? '' : 's' }}@if($mwMaxDay > 0 && $mwDayCounts[$day] < $mwMaxDay) &middot; half day @endif</span>
            </div>
            @foreach($mwCols as $mwCol)
                @if($mwCol['gap'])
                    <div class="mw-day-gap">{{ $mwCol['label'] }} &middot; {{ $mwCol['range'] }}</div>
                @else
                    @php $cell = $map[$day][$mwCol['slot']->full] ?? null; @endphp
                    @if($cell && !empty($cell['title']))
                        <div class="mw-day-row">
                            <span class="mw-day-time">{{ $mwCol['slot']->time_from }} – {{ $mwCol['slot']->time_to }}</span>
                            <span class="mw-day-subject">{{ $cell['title'] }}</span>
                            @if(!empty($cell['sub']))<span class="mw-day-teacher">{{ $cell['sub'] }}</span>@endif
                        </div>
                    @endif
                @endif
            @endforeach
            @if($mwDayCounts[$day] === 0)
                <div class="mw-day-row mw-day-none">No periods</div>
            @endif
        </div>
    @endforeach
</div>
