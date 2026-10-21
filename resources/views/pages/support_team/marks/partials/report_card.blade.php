{{--
    Batch M3 — THE official report card. One template, two surfaces:
    the on-screen marksheet (marks.show) and the printed document
    (marks.print) both render THIS partial from the same
    App\Support\ReportCard assembly — no divergent print code.

    Expects: $card (ReportCard::build output), $sr, $ex (exam), $exr
    (exam record), $my_class, $class_type, $s (settings map), $ct
    (class type code); optional $skills, $rc_show_traits (print only:
    also render the traits annex).

    Display doctrine: NULL = not entered, prints '–'; a stored 0 is
    a real zero and prints 0. Totals/averages/positions are the
    MarkComputation-written tex{term} / exam_records values — never
    recomputed here.
--}}
@php $rc_traits = $rc_show_traits ?? false; @endphp
<style>
    .report-card{position:relative;background:#fff;color:#000;font-size:13px;padding:6px 4px;}
    .report-card .rc-head{width:100%;border-collapse:collapse;}
    .report-card .rc-school{color:#1b0c80;font-size:25px;font-weight:bold;text-transform:uppercase;}
    .report-card .rc-addr{color:#000;font-size:15px;font-style:italic;}
    .report-card .rc-contact{color:#000;font-size:12px;}
    .report-card .rc-title{color:#000;font-size:15px;font-weight:bold;text-transform:uppercase;}
    .report-card .rc-watermark{position:absolute;left:0;right:0;margin:60px auto 0;max-width:500px;max-height:600px;opacity:.15;z-index:0;pointer-events:none;}
    .report-card .rc-body{position:relative;z-index:1;}
    .report-card .rc-student{width:100%;border-collapse:collapse;margin-bottom:6px;}
    .report-card .rc-student td{text-align:left;padding:2px 4px;}
    .report-card .rc-marks{width:100%;border-collapse:collapse;border:1px solid #000;margin:10px auto;}
    .report-card .rc-marks th,.report-card .rc-marks td{border:1px solid #000;padding:4px 3px;text-align:center;font-size:12px;}
    .report-card .rc-marks td.rc-subject{font-weight:bold;text-align:left;padding-left:6px;}
    .report-card .rc-marks td.rc-remark{text-align:left;}
    .report-card .rc-summary{width:100%;border-collapse:collapse;margin-top:2px;}
    .report-card .rc-summary td{text-align:left;padding:3px 4px;font-size:13px;}
    .report-card .rc-signatures{position:relative;margin-top:34px;padding-right:10px;}
    .report-card .rc-sign-table{width:100%;border-collapse:collapse;}
    .report-card .rc-sign-cell{width:50%;padding:0 30px 0 4px;vertical-align:top;}
    .report-card .rc-sign-line{border-bottom:1px solid #000;height:38px;}
    .report-card .rc-sign-role{font-weight:bold;margin-top:6px;font-size:12px;letter-spacing:.4px;}
    .report-card .rc-sign-name{font-size:13px;margin-top:2px;min-height:16px;}
    .report-card .rc-stamp{position:absolute;right:56px;bottom:2px;width:110px;height:110px;object-fit:contain;transform:rotate(-10deg);opacity:.95;z-index:2;}
    @media print{
        .report-card{padding:0;}
        .report-card .rc-marks th,.report-card .rc-marks td{padding:2px;font-size:12px;}
    }
</style>

<div class="report-card">
    {{-- Branded header (settings: logo, school name, address, contacts) --}}
    <table class="rc-head">
        <tr>
            <td style="width:110px; text-align:left;"><img src="{{ Qs::getLogo() }}" style="max-height:100px;" alt=""></td>
            <td style="text-align:center;">
                <span class="rc-school">{{ Qs::getSetting('system_name') }}</span><br/>
                <span class="rc-addr">{{ ucwords($s['address'] ?? Qs::getSetting('address')) }}</span><br/>
                @if(!empty($s['phone']) || !empty($s['system_email']))
                    <span class="rc-contact">{{ !empty($s['phone']) ? 'Tel: '.$s['phone'] : '' }}{{ (!empty($s['phone']) && !empty($s['system_email'])) ? ' | ' : '' }}{{ !empty($s['system_email']) ? 'Email: '.$s['system_email'] : '' }}</span><br/>
                @endif
                <span class="rc-title">Report Sheet {{ $class_type ? '('.strtoupper($class_type->name).')' : '' }}</span><br/>
                <span class="rc-title" style="font-size:13px;">{{ $ex->name }} &mdash; {!! Mk::getSuffix($ex->term) !!} Term, {{ $ex->year }}</span>
            </td>
            <td style="width:100px; text-align:right;">
                <img src="{{ $sr->user->photo }}" alt="" width="100" height="100">
            </td>
        </tr>
    </table>

    @if(Qs::getSetting('logo'))
        <img class="rc-watermark" src="{{ $s['logo'] ?? Qs::getSetting('logo') }}" alt="">
    @endif

    <div class="rc-body">
        {{-- Student block --}}
        <table class="rc-student">
            <tbody>
            <tr>
                <td><strong>NAME:</strong> {{ strtoupper($sr->user->name) }}</td>
                <td><strong>ADM NO:</strong> {{ $sr->adm_no }}</td>
                <td><strong>CLASS:</strong> {{ strtoupper($my_class->name) }}</td>
                <td><strong>SECTION:</strong> {{ $sr->section ? strtoupper($sr->section->name) : '-' }}</td>
            </tr>
            <tr>
                <td><strong>REPORT SHEET FOR</strong> {!! strtoupper(Mk::getSuffix($ex->term)) !!} TERM</td>
                <td><strong>ACADEMIC YEAR:</strong> {{ $ex->year }}</td>
                <td><strong>HOUSE:</strong> {{ $sr->house ? strtoupper($sr->house) : '-' }}</td>
                <td><strong>AGE:</strong> {{ $sr->age ?: ($sr->user->dob ? date_diff(date_create($sr->user->dob), date_create('now'))->y : '-') }}</td>
            </tr>
            </tbody>
        </table>

        {{-- Marks table: dynamic scheme columns (union across subjects) --}}
        <table class="rc-marks">
            <thead>
            <tr>
                <th style="text-align:left; padding-left:6px;">SUBJECTS</th>
                @foreach($card['columns'] as $col)
                    <th>{{ $col['label'] }}</th>
                @endforeach
                <th>TOTAL<br>(100)</th>
                <th>GRADE</th>
                <th>SUBJECT<br>POSITION</th>
                <th>REMARKS</th>
            </tr>
            </thead>
            <tbody>
            @foreach($card['rows'] as $row)
                <tr>
                    <td class="rc-subject">{{ $row['subject']->name }}</td>
                    @foreach($card['columns'] as $col)
                        @php $cell = $row['cells'][$col['key']] ?? null; @endphp
                        <td>{{ $cell === null ? '–' : $cell }}</td>
                    @endforeach
                    <td>{{ $row['total'] === null ? '–' : $row['total'] }}</td>
                    <td>{{ $row['grade'] ?: '–' }}</td>
                    <td>{!! $row['pos'] ? Mk::getSuffix($row['pos']) : '–' !!}</td>
                    <td class="rc-remark">{{ $row['remark'] ?: '–' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{-- Footer: the exam_records aggregates, exactly as stored --}}
        <table class="rc-summary">
            <tr>
                <td><strong>TOTAL SCORES OBTAINED:</strong> <strong>{{ $exr && $exr->total !== null ? $exr->total : '–' }}</strong></td>
                <td><strong>FINAL AVERAGE:</strong> <strong>{{ $exr && $exr->ave !== null ? $exr->ave : '–' }}</strong></td>
                <td><strong>CLASS AVERAGE:</strong> <strong>{{ $exr && $exr->class_ave !== null ? $exr->class_ave : '–' }}</strong></td>
                <td><strong>POSITION:</strong> <strong>{!! $exr && $exr->pos ? Mk::getSuffix($exr->pos) : '–' !!}</strong></td>
            </tr>
        </table>

        {{-- Key to grading (bands of this class type + outcome counts) --}}
        @include('pages.support_team.marks.print.grading', ['marks' => $card['exam_marks']])

        {{-- Traits annex (printed document only) --}}
        @if($rc_traits && !empty($skills))
            @include('pages.support_team.marks.print.skills')
        @endif

        <div style="margin-top:25px; clear:both;"></div>

        {{-- Comments (teacher / principal text + next-term information) --}}
        @include('pages.support_team.marks.print.comments')

        {{-- Signatures + school stamp. The stamp renders ONLY when a
             stamp image has been uploaded in Settings — no placeholder
             seal is ever drawn (owner decision D4). --}}
        <div class="rc-signatures">
            @if(!empty($card['stamp']))
                <img class="rc-stamp" src="{{ $card['stamp'] }}" alt="School stamp">
            @endif
            <table class="rc-sign-table">
                <tr>
                    <td class="rc-sign-cell">
                        <div class="rc-sign-line"></div>
                        <div class="rc-sign-role">CLASS TEACHER</div>
                        <div class="rc-sign-name">{{ $card['class_teacher'] ?: '' }}</div>
                    </td>
                    <td class="rc-sign-cell">
                        <div class="rc-sign-line"></div>
                        <div class="rc-sign-role">PRINCIPAL</div>
                        <div class="rc-sign-name">{{ $card['principal_name'] ?: '' }}</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>
