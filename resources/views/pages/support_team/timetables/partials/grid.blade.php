{{--
    Shared aligned timetable grid (Batch 11).
    Expects: $days (ordered day keys), $slots (TimeSlot models, ->full),
    $map[day][slot full] = ['title' => ..., 'sub' => ...],
    optional $day_labels[day] for a custom first-column label.
    EVERY day row renders exactly one cell per slot — a missing entry is
    a muted "—", so columns can never shift.
--}}
<div class="l7-table-scroll">
    <table class="table table-striped ttx-grid">
        <thead>
        <tr>
            <th class="ttx-day-col">Day</th>
            @foreach($slots as $slot)
                <th>{{ $slot->full }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach($days as $day)
            <tr>
                <td class="ttx-day-col"><strong>{{ $day_labels[$day] ?? $day }}</strong></td>
                @foreach($slots as $slot)
                    @php $cell = $map[$day][$slot->full] ?? null; @endphp
                    <td>
                        @if($cell && !empty($cell['title']))
                            <span class="ttx-cell-title">{{ $cell['title'] }}</span>
                            @if(!empty($cell['sub']))<span class="ttx-cell-sub">{{ $cell['sub'] }}</span>@endif
                        @else
                            <span class="ttx-empty">&mdash;</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
