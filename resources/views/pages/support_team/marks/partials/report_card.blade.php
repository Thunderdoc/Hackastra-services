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
    /* Document design v2 (Workstream CY, Loop 68 tokens): one family
       with the admit + ID cards — accent navy #10263b, hairline
       #b9c6d4, shaded heads #eef2f7. The watermark is ALWAYS on: the
       uploaded logo when one exists, otherwise a large acronym
       monogram from the settings app code. */
    .report-card{position:relative;background:#fff;color:#000;font-size:13px;padding:4px 4px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .report-card .rc-head{width:100%;border-collapse:collapse;border-bottom:2.5px solid #10263b;padding-bottom:4px;}
    .report-card .rc-school{color:#10263b;font-size:25px;font-weight:bold;text-transform:uppercase;letter-spacing:.2px;}
    .report-card .rc-addr{color:#000;font-size:15px;font-style:italic;}
    .report-card .rc-contact{color:#000;font-size:12px;}
    .report-card .rc-logo-tile{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:#10263b;color:#fff;font-size:20px;font-weight:800;letter-spacing:.03em;border-radius:8px;}
    .report-card .rc-photo{width:100px;height:100px;object-fit:cover;border:1px solid #b9c6d4;}
    .report-card .rc-photo-tile{display:inline-flex;align-items:center;justify-content:center;width:84px;height:84px;background:#eef2f7;color:#10263b;font-size:28px;font-weight:800;letter-spacing:.03em;border:1px solid #b9c6d4;}
    .report-card .rc-titleband{background:#10263b;color:#fff;text-align:center;padding:6px 8px 7px;margin:4px 0;}
    .report-card .rc-title{color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:.6px;}
    .report-card .rc-subtitle{color:#dbe7fb;font-size:12.5px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;margin-top:1px;}
    .report-card .rc-watermark{position:absolute;top:55%;left:50%;transform:translate(-50%,-50%);width:430px;max-height:560px;object-fit:contain;opacity:.10;z-index:0;pointer-events:none;}
    .report-card .rc-watermark-mono{position:absolute;top:55%;left:50%;transform:translate(-50%,-50%) rotate(-18deg);font-weight:800;letter-spacing:.05em;color:#10263b;opacity:.055;z-index:0;pointer-events:none;white-space:nowrap;line-height:1;}
    .report-card .rc-body{position:relative;z-index:1;}
    .report-card .rc-student{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:4px;}
    .report-card .rc-student td{text-align:left;padding:3px 6px;border:1px solid #b9c6d4;font-size:12.5px;}
    .report-card .rc-student td:nth-child(1){width:27%;}
    .report-card .rc-student td:nth-child(2){width:23%;}
    .report-card .rc-student td:nth-child(3){width:27%;}
    .report-card .rc-student td:nth-child(4){width:23%;}
    .report-card .rc-student strong{color:#33475c;letter-spacing:.2px;}
    .report-card .rc-marks{width:100%;border-collapse:collapse;border:1.5px solid #10263b;margin:4px auto;}
    .report-card .rc-marks th,.report-card .rc-marks td{border:1px solid #7d8fa6;padding:2px 3px;text-align:center;font-size:12px;}
    .report-card .rc-marks thead th{background:#eef2f7;color:#10263b;}
    .report-card .rc-marks th.rc-sn,.report-card .rc-marks td.rc-sn{width:32px;color:#44546a;}
    .report-card .rc-marks td.rc-subject{font-weight:bold;text-align:left;padding-left:6px;}
    .report-card .rc-marks td.rc-remark{text-align:left;}
    .report-card .rc-summary{width:100%;border-collapse:collapse;margin-top:4px;border:1px solid #b9c6d4;background:#f7fafc;}
    .report-card .rc-summary td{text-align:left;padding:4px 6px;font-size:12.5px;width:25%;border-right:1px solid #d7e0ec;}
    .report-card .rc-summary td:last-child{border-right:0;}
    .report-card .rc-legend{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:2px;border:1px solid #b9c6d4;}
    .report-card .rc-legend td{padding:4px 6px;text-align:center;font-size:11.5px;white-space:nowrap;border-right:1px solid #d7e0ec;}
    .report-card .rc-legend td:last-child{border-right:0;}
    .report-card .rc-legend td.rc-legend-label{background:#eef2f7;color:#10263b;font-weight:bold;text-align:left;letter-spacing:.3px;width:126px;}
    .report-card .rc-counts{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:-1px;border:1px solid #b9c6d4;border-top:0;}
    .report-card .rc-counts td{padding:4px 6px;font-size:11.5px;white-space:nowrap;border-right:1px solid #d7e0ec;}
    .report-card .rc-counts td:last-child{border-right:0;}
    .report-card .rc-comments{width:100%;border-collapse:collapse;margin-top:4px;}
    .report-card .rc-comments td{padding:2px 4px;font-size:12.5px;vertical-align:top;}
    .report-card .rc-comments td.rc-clabel{width:185px;white-space:nowrap;font-weight:bold;color:#10263b;letter-spacing:.2px;}
    .report-card .rc-key{margin:4px 0 2px;font-size:12px;color:#10263b;}
    .report-card .rc-traits{width:100%;border-collapse:collapse;}
    .report-card .rc-traits>tbody>tr>td{vertical-align:top;padding:0;}
    .report-card .rc-traits>tbody>tr>td:first-child{padding-right:14px;}
    .report-card .rc-trait-table{width:100%;border-collapse:collapse;border:1px solid #10263b;}
    .report-card .rc-trait-table th,.report-card .rc-trait-table td{border:1px solid #7d8fa6;padding:1px 6px;font-size:12px;text-align:left;}
    .report-card .rc-trait-table thead th{background:#eef2f7;color:#10263b;}
    .report-card .rc-trait-table td.rc-rating,.report-card .rc-trait-table th.rc-rating{width:64px;text-align:center;}
    .report-card .rc-signatures{position:relative;margin-top:8px;padding-right:10px;}
    .report-card .rc-sign-table{width:100%;border-collapse:collapse;}
    .report-card .rc-sign-cell{width:50%;padding:0 30px 0 4px;vertical-align:top;}
    .report-card .rc-sign-line{border-bottom:1px solid #000;height:38px;}
    .report-card .rc-sign-line-filled{height:4px;}
    .report-card .rc-sign-img{display:block;height:34px;max-width:170px;object-fit:contain;margin-left:6px;}
    .report-card .rc-sign-role{font-weight:bold;margin-top:4px;font-size:12px;letter-spacing:.4px;}
    .report-card .rc-sign-name{font-size:13px;margin-top:2px;min-height:16px;}
    .report-card .rc-stamp{position:absolute;right:56px;bottom:2px;width:110px;height:110px;object-fit:contain;transform:rotate(-10deg);opacity:.95;z-index:2;}
    .report-card .rc-genline{text-align:center;color:#718096;font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;margin-top:4px;}
    @media print{
        .report-card{padding:0;}
        .report-card .rc-marks th,.report-card .rc-marks td{padding:2px;font-size:12px;}
    }
</style>

<div class="report-card">
    {{-- Branded header (settings: logo, school name, address, contacts).
         v2: a real uploaded logo prints; with none uploaded a monogram
         tile built from the school initials carries the identity — the
         theme's light fallback logo is never used on paper (it is
         invisible on white). --}}
    @php
        $rcLogo = $s['logo'] ?? Qs::getSetting('logo');
        $rcCode = strtoupper((string) Qs::getAppCode());
        $rcInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $rcWord) {
            if ($rcWord !== '') {
                $rcInitials .= strtoupper(substr($rcWord, 0, 1));
                if (strlen($rcInitials) >= 2) { break; }
            }
        }
        if ($rcInitials === '') { $rcInitials = 'S'; }
    @endphp
    <table class="rc-head">
        <tr>
            <td style="width:110px; text-align:left;">
                @if($rcLogo)
                    <img src="{{ $rcLogo }}" style="max-height:100px;" alt="">
                @else
                    <span class="rc-logo-tile" aria-hidden="true">{{ $rcInitials }}</span>
                @endif
            </td>
            <td style="text-align:center;">
                <span class="rc-school">{{ Qs::getSetting('system_name') }}</span><br/>
                <span class="rc-addr">{{ ucwords($s['address'] ?? Qs::getSetting('address')) }}</span><br/>
                @if(!empty($s['phone']) || !empty($s['system_email']))
                    <span class="rc-contact">{{ !empty($s['phone']) ? 'Tel: '.$s['phone'] : '' }}{{ (!empty($s['phone']) && !empty($s['system_email'])) ? ' | ' : '' }}{{ !empty($s['system_email']) ? 'Email: '.$s['system_email'] : '' }}</span>
                @endif
            </td>
            <td style="width:100px; text-align:right;">
                {{-- Photo rule (Workstream BR, audit Fix Wave 1 R-02):
                     a real uploaded photo, else an initials tile —
                     never the stock silhouette. Mirrors the annual
                     report card exactly; this partial missed the guard. --}}
                @if(Qs::hasRealPhoto($sr->user->photo))
                    <img class="rc-photo" src="{{ $sr->user->photo }}" alt="">
                @else
                    @php
                        $rcTermInitials = '';
                        foreach (preg_split('/\s+/', trim((string) $sr->user->name)) as $rcTermWord) {
                            if ($rcTermWord !== '') {
                                $rcTermInitials .= strtoupper(substr($rcTermWord, 0, 1));
                                if (strlen($rcTermInitials) >= 2) { break; }
                            }
                        }
                    @endphp
                    <span class="rc-photo-tile" aria-hidden="true">{{ $rcTermInitials ?: 'S' }}</span>
                @endif
            </td>
        </tr>
    </table>

    {{-- Document title band (v2) --}}
    <div class="rc-titleband">
        <div class="rc-title">Report Sheet {{ $class_type ? '('.strtoupper($class_type->name).')' : '' }}</div>
        <div class="rc-subtitle">{{ $ex->name }} &mdash; {!! Mk::getSuffix($ex->term) !!} Term, {{ $ex->year }}</div>
    </div>

    {{-- Watermark (v2): always on. The uploaded logo when one exists;
         otherwise the settings app code as a large monogram, so the
         document looks official either way. --}}
    @if($rcLogo)
        <img class="rc-watermark" src="{{ $rcLogo }}" alt="">
    @else
        <div class="rc-watermark-mono" style="font-size:{{ strlen($rcCode) > 5 ? 104 : 168 }}px;" aria-hidden="true">{{ $rcCode }}</div>
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
                <th class="rc-sn">S.NO</th>
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
                    <td class="rc-sn">{{ $loop->iteration }}</td>
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

        <div style="margin-top:8px; clear:both;"></div>

        {{-- Comments (teacher / principal text + next-term information) --}}
        @include('pages.support_team.marks.print.comments')

        {{-- Signatures + school stamp. The stamp renders ONLY when a
             stamp image has been uploaded in Settings — no placeholder
             seal is ever drawn (owner decision D4). The principal's
             digital signature (owner ruling 2026-10-22) follows the
             same rule: the uploaded image sits above the Principal
             line while one is configured in Settings, otherwise the
             blank line prints exactly as before. --}}
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
                        @if(!empty($card['principal_signature']))
                            <img class="rc-sign-img" src="{{ $card['principal_signature'] }}" alt="Principal's signature">
                            <div class="rc-sign-line rc-sign-line-filled"></div>
                        @else
                            <div class="rc-sign-line"></div>
                        @endif
                        <div class="rc-sign-role">PRINCIPAL</div>
                        <div class="rc-sign-name">{{ $card['principal_name'] ?: '' }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="rc-genline">Generated by Hackstra</div>
    </div>
</div>
