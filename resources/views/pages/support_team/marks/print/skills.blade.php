{{-- Batch 6 (M3): the KEY legend used to be a width-less float:left div sitting
     beside two floated tables — the AFFECTIVE table's left edge slid under the
     legend text in print. The legend is now its own block above the tables and
     the tables sit in a cleared row of their own, so nothing can overlap. --}}
<div style="clear: both;">
    {{--KEYS TO RATING--}}
    <div style="margin: 10px 0 4px;">
        <strong style="text-decoration: underline;">KEY</strong>: &nbsp;
        <span>5 - Excellent</span> &nbsp;|&nbsp;
        <span>4 - Very Good</span> &nbsp;|&nbsp;
        <span>3 - Good</span> &nbsp;|&nbsp;
        <span>2 - Fair</span> &nbsp;|&nbsp;
        <span>1 - Poor</span>
    </div>

    <div style="clear: both;">
        <table style="float: left; width:40%; border-collapse:collapse; border: 1px solid #000; margin:10px 20px 10px 0;" border="1">
            <thead>
            <tr>
                <td><strong>AFFECTIVE TRAITS</strong></td>
                <td><strong>RATING</strong></td>
            </tr>
            </thead>
            <tbody>
            @foreach ($skills->where('skill_type', 'AF') as $af)
                <tr>
                    <td>{{ $af->name }}</td>
                    <td>{{ $exr->af ? explode(',', $exr->af)[$loop->index] : '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <table style="float: left; width:35%; border-collapse:collapse; border: 1px solid #000; margin: 10px 20px 10px 0;" border="1">
            <thead>
            <tr>
                <td><strong>PSYCHOMOTOR</strong></td>
                <td><strong>RATING</strong></td>
            </tr>
            </thead>
            <tbody>
            @foreach ($skills->where('skill_type', 'PS') as $ps)
                <tr>
                    <td>{{ $ps->name }}</td>
                    <td>{{ $exr->ps ? explode(',', $exr->ps)[$loop->index] : '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div style="clear: both;"></div>
    </div>

</div>
