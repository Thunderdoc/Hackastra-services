{{-- Document design v2 (Workstream CY): the traits annex in the
     report-card family — the rating KEY as a legend line, then the
     AFFECTIVE / PSYCHOMOTOR tables in a fixed two-column wrapper
     (no floats, nothing can slide or overlap). Same traits, same
     ratings. --}}
<div style="clear: both;">
    {{--KEYS TO RATING--}}
    <div class="rc-key">
        <strong style="text-decoration: underline;">KEY</strong>: &nbsp;
        <span>5 - Excellent</span> &nbsp;|&nbsp;
        <span>4 - Very Good</span> &nbsp;|&nbsp;
        <span>3 - Good</span> &nbsp;|&nbsp;
        <span>2 - Fair</span> &nbsp;|&nbsp;
        <span>1 - Poor</span>
    </div>

    <table class="rc-traits">
        <tbody>
        <tr>
            <td>
                <table class="rc-trait-table">
                    <thead>
                    <tr>
                        <th>AFFECTIVE TRAITS</th>
                        <th class="rc-rating">RATING</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($skills->where('skill_type', 'AF') as $af)
                        <tr>
                            <td>{{ $af->name }}</td>
                            <td class="rc-rating">{{ $exr->af ? explode(',', $exr->af)[$loop->index] : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </td>
            <td>
                <table class="rc-trait-table">
                    <thead>
                    <tr>
                        <th>PSYCHOMOTOR</th>
                        <th class="rc-rating">RATING</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($skills->where('skill_type', 'PS') as $ps)
                        <tr>
                            <td>{{ $ps->name }}</td>
                            <td class="rc-rating">{{ $exr->ps ? explode(',', $exr->ps)[$loop->index] : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
</div>
