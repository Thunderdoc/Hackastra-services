{{--
    Shared aligned timetable grid (Batch 11).
    Expects: $days (ordered day keys), $slots (TimeSlot models, ->full),
    $map[day][slot full] = ['title' => ..., 'sub' => ...],
    optional $day_labels[day] for a custom first-column label.
    EVERY day row renders exactly one cell per slot — a missing entry is
    a muted "—", so columns can never shift.
    Owner fix 2026-10-25: clock gaps between consecutive slots render
    as their own columns — a gap of 30+ minutes is Lunch, a shorter
    gap is Break — so lunch is visible on the grid instead of hidden
    in the jump between slot times.
--}}
@php
    $ttxSlots = collect($slots)->sortBy(function ($s) { return strtotime($s->time_from); })->values();
    $ttxCols = [];
    $ttxPrev = null;
    foreach ($ttxSlots as $ttxSlot) {
        if ($ttxPrev) {
            $ttxGap = (strtotime($ttxSlot->time_from) - strtotime($ttxPrev->time_to)) / 60;
            if ($ttxGap > 0) {
                $ttxCols[] = ['gap' => true, 'label' => $ttxGap >= 30 ? 'Lunch' : 'Break', 'range' => $ttxPrev->time_to . ' – ' . $ttxSlot->time_from];
            }
        }
        $ttxCols[] = ['gap' => false, 'slot' => $ttxSlot];
        $ttxPrev = $ttxSlot;
    }
@endphp
<div class="l7-table-scroll">
    <table class="table table-striped ttx-grid">
        <thead>
        <tr>
            <th class="ttx-day-col">Day</th>
            @foreach($ttxCols as $ttxCol)
                @if($ttxCol['gap'])
                    <th class="ttx-gap-col"><span class="ttx-gap-label">{{ $ttxCol['label'] }}</span><span class="ttx-gap-range">{{ $ttxCol['range'] }}</span></th>
                @else
                    <th>{{ $ttxCol['slot']->full }}</th>
                @endif
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach($days as $day)
            <tr>
                <td class="ttx-day-col"><strong>{{ $day_labels[$day] ?? $day }}</strong></td>
                @foreach($ttxCols as $ttxCol)
                    @if($ttxCol['gap'])
                        <td class="ttx-gap-col"><span class="ttx-gap-label">{{ $ttxCol['label'] }}</span></td>
                    @else
                        @php $cell = $map[$day][$ttxCol['slot']->full] ?? null; @endphp
                        <td>
                            @if($cell && !empty($cell['title']))
                                <span class="ttx-cell-title">{{ $cell['title'] }}</span>
                                @if(!empty($cell['sub']))<span class="ttx-cell-sub">{{ $cell['sub'] }}</span>@endif
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
