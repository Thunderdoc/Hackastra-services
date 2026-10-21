{{--<!--NAME , CLASS AND OTHER INFO -->--}}
<table style="width:100%; border-collapse:collapse; ">
    <tbody>
    <tr>
        <td style="text-align: left;"><strong>NAME:</strong> {{ strtoupper($sr->user->name) }}</td>
        <td style="text-align: left;"><strong>ADM NO:</strong> {{ $sr->adm_no }}</td>
        <td style="text-align: left;"><strong>CLASS:</strong> {{ strtoupper($my_class->name) }}</td>
        <td style="text-align: left;"><strong>SECTION:</strong> {{ $sr->section ? strtoupper($sr->section->name) : '-' }}</td>
    </tr>
    <tr>
        <td style="text-align: left;"><strong>REPORT SHEET FOR</strong> {!! strtoupper(Mk::getSuffix($ex->term)) !!} TERM </td>
        <td style="text-align: left;"><strong>ACADEMIC YEAR:</strong> {{ $ex->year }}</td>
        <td style="text-align: left;"><strong>HOUSE:</strong> {{ $sr->house ? strtoupper($sr->house) : '-' }}</td>
        <td style="text-align: left;"><strong>AGE:</strong> {{ $sr->age ?: ($sr->user->dob ? date_diff(date_create($sr->user->dob), date_create('now'))->y : '-') }}</td>
    </tr>

    </tbody>
</table>


{{--Exam Table--}}
<table style="width:100%; border-collapse:collapse; border: 1px solid #000; margin: 10px auto;" border="1">
    <thead>
    <tr>
        <th rowspan="2">SUBJECTS</th>
        <th colspan="3">CONTINUOUS ASSESSMENT</th>
        <th rowspan="2">EXAM<br>(60)</th>
        <th rowspan="2">FINAL MARKS <br> (100%)</th>
        <th rowspan="2">GRADE</th>
        <th rowspan="2">SUBJECT <br> POSITION</th>


      {{--  @if($ex->term == 3) --}}{{-- 3rd Term --}}{{--
        <th rowspan="2">FINAL MARKS <br>(100%) 3<sup>RD</sup> TERM</th>
        <th rowspan="2">1<sup>ST</sup> <br> TERM</th>
        <th rowspan="2">2<sup>ND</sup> <br> TERM</th>
        <th rowspan="2">CUM (300%) <br> 1<sup>ST</sup> + 2<sup>ND</sup> + 3<sup>RD</sup></th>
        <th rowspan="2">CUM AVE</th>
        <th rowspan="2">GRADE</th>
        @endif--}}

        <th rowspan="2">REMARKS</th>
    </tr>
    <tr>
        <th>CA1(20)</th>
        <th>CA2(20)</th>
        <th>TOTAL(40)</th>
    </tr>
    </thead>
    <tbody>
    @foreach($subjects as $sub)
        <tr>
            <td style="font-weight: bold; text-align: left;">{{ $sub->name }}</td>
            @foreach($marks->where('subject_id', $sub->id)->where('exam_id', $ex->id) as $mk)
                <td>{{ $mk->t1 ?: '-' }}</td>
                <td>{{ $mk->t2 ?: '-' }}</td>
                <td>{{ $mk->tca ?: '-' }}</td>
                <td>{{ $mk->exm ?: '-' }}</td>

                <td>{{ $mk->$tex ?: '-'}}</td>
                <td>{{ $mk->grade ? $mk->grade->name : '-' }}</td>
                <td>{!! ($mk->grade) ? Mk::getSuffix($mk->sub_pos) : '-' !!}</td>
                <td style="text-align: left;">{{ $mk->grade ? $mk->grade->remark : '-' }}</td>

                {{--@if($ex->term == 3)
                    <td>{{ $mk->tex3 ?: '-' }}</td>
                    <td>{{ Mk::getSubTotalTerm($student_id, $sub->id, 1, $mk->my_class_id, $year) }}</td>
                    <td>{{ Mk::getSubTotalTerm($student_id, $sub->id, 2, $mk->my_class_id, $year) }}</td>
                    <td>{{ $mk->cum ?: '-' }}</td>
                    <td>{{ $mk->cum_ave ?: '-' }}</td>
                    <td>{{ $mk->grade ? $mk->grade->name : '-' }}</td>
                    <td>{{ $mk->grade ? $mk->grade->remark : '-' }}</td>
                @endif--}}

            @endforeach
        </tr>
    @endforeach
    <tr>
        <td colspan="3"><strong>TOTAL SCORES OBTAINED: </strong> <strong>{{ $exr->total ?: '-' }}</strong></td>
        <td colspan="2"><strong>FINAL AVERAGE: </strong> <strong>{{ $exr->ave ?: '-' }}</strong></td>
        <td colspan="2"><strong>CLASS AVERAGE: </strong> <strong>{{ $exr->class_ave ?: '-' }}</strong></td>
        <td colspan="2"><strong>POSITION: </strong> <strong>{!! $exr->pos ? Mk::getSuffix($exr->pos) : '-' !!}</strong></td>
    </tr>
    </tbody>
</table>
