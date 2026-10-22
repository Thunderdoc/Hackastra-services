{{--
    Batch M4 — THE annual / cumulative report card (design §3.6,
    owner decision D3). One template, two surfaces: the on-screen
    annual marksheet (marks.annual) and the printed document
    (marks.print_annual) both render THIS partial from the
    App\Support\ReportCard::buildAnnual assembly — the annual twin
    of the M3 term card, sharing its branded header, student block,
    signature block and stamp rules.

    Expects: $card (ReportCard::buildAnnual output), $sr, $year,
    $my_class, $class_type, $s (settings map), $ct (class type code).

    Display doctrine (same as the term card): NULL = no total for
    that term, prints '–'; a stored 0 is a real zero. The annual
    score is the mean of the term totals PRESENT — a missed term is
    excluded, never averaged in as 0. Totals/positions are computed
    once, in the assembler, from stored tex{term} values only.

    Deliberately absent (no honest stored source exists):
    - the per-exam teacher/principal COMMENTS (they live on a
      single term's exam_record; an annual card has none);
    - the distinction/credit/pass counts (they count stored
      per-exam grade rows). The grading KEY below is printed from
      the same class-type bands as the term card.
--}}
<style>
    /* Document design v2 (Workstream CY, Loop 68 tokens): one family
       with the admit + ID cards — accent navy #10263b, hairline
       #b9c6d4, shaded heads #eef2f7. The watermark is ALWAYS on: the
       uploaded logo when one exists, otherwise a large acronym
       monogram from the settings app code. */
    .report-card{position:relative;background:#fff;color:#000;font-size:13px;padding:6px 4px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .report-card .rc-head{width:100%;border-collapse:collapse;border-bottom:2.5px solid #10263b;padding-bottom:6px;}
    .report-card .rc-school{color:#10263b;font-size:25px;font-weight:bold;text-transform:uppercase;letter-spacing:.2px;}
    .report-card .rc-addr{color:#000;font-size:15px;font-style:italic;}
    .report-card .rc-contact{color:#000;font-size:12px;}
    .report-card .rc-logo-tile{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:#10263b;color:#fff;font-size:20px;font-weight:800;letter-spacing:.03em;border-radius:8px;}
    .report-card .rc-photo{width:100px;height:100px;object-fit:cover;border:1px solid #b9c6d4;}
    .report-card .rc-photo-tile{display:inline-flex;align-items:center;justify-content:center;width:100px;height:100px;background:#eef2f7;color:#10263b;font-size:30px;font-weight:800;letter-spacing:.03em;border:1px solid #b9c6d4;}
    .report-card .rc-titleband{background:#10263b;color:#fff;text-align:center;padding:6px 8px 7px;margin:10px 0;}
    .report-card .rc-title{color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:.6px;}
    .report-card .rc-subtitle{color:#dbe7fb;font-size:12.5px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;margin-top:1px;}
    .report-card .rc-watermark{position:absolute;top:55%;left:50%;transform:translate(-50%,-50%);width:430px;max-height:560px;object-fit:contain;opacity:.10;z-index:0;pointer-events:none;}
    .report-card .rc-watermark-mono{position:absolute;top:55%;left:50%;transform:translate(-50%,-50%) rotate(-18deg);font-weight:800;letter-spacing:.05em;color:#10263b;opacity:.055;z-index:0;pointer-events:none;white-space:nowrap;line-height:1;}
    .report-card .rc-body{position:relative;z-index:1;}
    .report-card .rc-student{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:8px;}
    .report-card .rc-student td{text-align:left;padding:4px 6px;border:1px solid #b9c6d4;font-size:12.5px;}
    .report-card .rc-student td:nth-child(1){width:27%;}
    .report-card .rc-student td:nth-child(2){width:23%;}
    .report-card .rc-student td:nth-child(3){width:27%;}
    .report-card .rc-student td:nth-child(4){width:23%;}
    .report-card .rc-student strong{color:#33475c;letter-spacing:.2px;}
    .report-card .rc-marks{width:100%;border-collapse:collapse;border:1.5px solid #10263b;margin:8px auto;}
    .report-card .rc-marks th,.report-card .rc-marks td{border:1px solid #7d8fa6;padding:4px 3px;text-align:center;font-size:12px;}
    .report-card .rc-marks thead th{background:#eef2f7;color:#10263b;}
    .report-card .rc-marks th.rc-sn,.report-card .rc-marks td.rc-sn{width:32px;color:#44546a;}
    .report-card .rc-marks td.rc-subject{font-weight:bold;text-align:left;padding-left:6px;}
    .report-card .rc-marks td.rc-remark{text-align:left;}
    .report-card .rc-summary{width:100%;border-collapse:collapse;margin-top:4px;border:1px solid #b9c6d4;background:#f7fafc;}
    .report-card .rc-summary td{text-align:left;padding:5px 6px;font-size:12.5px;width:25%;border-right:1px solid #d7e0ec;}
    .report-card .rc-summary td:last-child{border-right:0;}
    .report-card .rc-legend{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:8px;border:1px solid #b9c6d4;}
    .report-card .rc-legend td{padding:4px 6px;text-align:center;font-size:11.5px;white-space:nowrap;border-right:1px solid #d7e0ec;}
    .report-card .rc-legend td:last-child{border-right:0;}
    .report-card .rc-legend td.rc-legend-label{background:#eef2f7;color:#10263b;font-weight:bold;text-align:left;letter-spacing:.3px;width:126px;}
    .report-card .rc-signatures{position:relative;margin-top:26px;padding-right:10px;}
    .report-card .rc-sign-table{width:100%;border-collapse:collapse;}
    .report-card .rc-sign-cell{width:33.33%;padding:0 30px 0 4px;vertical-align:top;}
    .report-card .rc-issue{width:100%;border-collapse:collapse;margin-top:16px;}
    .report-card .rc-issue td{font-size:11px;color:#44546a;padding:0;}
    .report-card .rc-issue td.rc-issue-right{text-align:right;color:#718096;font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;}
    .report-card .rc-sign-line{border-bottom:1px solid #000;height:38px;}
    .report-card .rc-sign-line-filled{height:4px;}
    .report-card .rc-sign-img{display:block;height:34px;max-width:170px;object-fit:contain;margin-left:6px;}
    .report-card .rc-sign-role{font-weight:bold;margin-top:6px;font-size:12px;letter-spacing:.4px;}
    .report-card .rc-sign-name{font-size:13px;margin-top:2px;min-height:16px;}
    .report-card .rc-stamp{position:absolute;right:56px;bottom:2px;width:110px;height:110px;object-fit:contain;transform:rotate(-10deg);opacity:.95;z-index:2;}
    .report-card .rc-genline{text-align:center;color:#718096;font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;margin-top:16px;}
    @media print{
        .report-card{padding:0;}
        .report-card .rc-marks th,.report-card .rc-marks td{padding:2px;font-size:12px;}
    }
</style>

<div class="report-card">
    {{-- Branded header (settings: logo, school name, address, contacts).
         v2: a real uploaded logo prints; with none uploaded a monogram
         tile built from the school initials carries the identity — the
         theme's light fallback logo is never used on paper. --}}
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
                {{-- Photo rule (Workstream BR): a real uploaded photo,
                     else an initials tile — never the stock silhouette
                     the users-table default points at. --}}
                @if(Qs::hasRealPhoto($sr->user->photo))
                    <img class="rc-photo" src="{{ $sr->user->photo }}" alt="">
                @else
                    @php
                        $rcStuInitials = '';
                        foreach (preg_split('/\s+/', trim((string) $sr->user->name)) as $rcWord) {
                            if ($rcWord !== '') {
                                $rcStuInitials .= strtoupper(substr($rcWord, 0, 1));
                                if (strlen($rcStuInitials) >= 2) { break; }
                            }
                        }
                    @endphp
                    <span class="rc-photo-tile" aria-hidden="true">{{ $rcStuInitials ?: 'S' }}</span>
                @endif
            </td>
        </tr>
    </table>

    {{-- Document title band (v2) --}}
    <div class="rc-titleband">
        <div class="rc-title">Annual Report Sheet {{ $class_type ? '('.strtoupper($class_type->name).')' : '' }}</div>
        <div class="rc-subtitle">Cumulative Report &mdash; Academic Year {{ $year }}</div>
    </div>

    {{-- Watermark (v2): always on — uploaded logo, else app-code monogram. --}}
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
                <td><strong>REPORT SHEET FOR</strong> FULL ACADEMIC SESSION</td>
                <td><strong>ACADEMIC YEAR:</strong> {{ $year }}</td>
                <td><strong>HOUSE:</strong> {{ $sr->house ? strtoupper($sr->house) : '-' }}</td>
                <td><strong>AGE:</strong> {{ $sr->age ?: ($sr->user->dob ? date_diff(date_create($sr->user->dob), date_create('now'))->y : '-') }}</td>
            </tr>
            </tbody>
        </table>

        {{-- Marks table: one column per term of the year, then the
             annual score (mean of the terms present), grade and the
             annual subject position. --}}
        <table class="rc-marks">
            <thead>
            <tr>
                <th class="rc-sn">S.NO</th>
                <th style="text-align:left; padding-left:6px;">SUBJECTS</th>
                @foreach($card['columns'] as $col)
                    <th>{{ $col['label'] }}<br>TOTAL (100)</th>
                @endforeach
                <th>ANNUAL<br>SCORE (100)</th>
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
                        @php $cell = $row['terms'][$col['key']] ?? null; @endphp
                        <td>{{ $cell === null ? '–' : $cell }}</td>
                    @endforeach
                    <td>{{ $row['annual'] === null ? '–' : $row['annual'] }}</td>
                    <td class="rc-grade">{{ $row['grade'] ?: '–' }}</td>
                    <td>{!! $row['pos'] ? Mk::getSuffix($row['pos']) : '–' !!}</td>
                    <td class="rc-remark">{{ $row['remark'] ?: '–' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{-- Footer: the annual aggregates from the assembly --}}
        <table class="rc-summary">
            <tr>
                <td><strong>ANNUAL TOTAL:</strong> <strong>{{ $card['footer']['total'] !== null ? $card['footer']['total'] : '–' }}</strong></td>
                <td><strong>OVERALL ANNUAL AVERAGE:</strong> <strong>{{ $card['footer']['overall'] !== null ? $card['footer']['overall'] : '–' }}</strong></td>
                <td><strong>CLASS ANNUAL AVERAGE:</strong> <strong>{{ $card['footer']['class_ave'] !== null ? $card['footer']['class_ave'] : '–' }}</strong></td>
                <td><strong>ANNUAL POSITION:</strong> <strong>{!! $card['footer']['pos'] ? Mk::getSuffix($card['footer']['pos']) : '–' !!}</strong></td>
            </tr>
        </table>

        {{-- Key to grading: the class type's bands, as on the term card.
             (The distinction/credit/pass COUNTS are not printed here:
             they count stored per-exam grade rows and an annual card
             has none — see the note at the top of this partial.) --}}
        <table class="rc-legend">
            <tr>
                <td class="rc-legend-label">KEY TO THE GRADING</td>
                @if(Mk::getGradeList($class_type->id)->count())
                    @foreach(Mk::getGradeList($class_type->id) as $gr)
                        <td><strong>{{ $gr->name }}</strong> =&gt; {{ $gr->mark_from.' - '.$gr->mark_to }}</td>
                    @endforeach
                @endif
            </tr>
        </table>

        <div style="margin-top:14px; clear:both;"></div>

        {{-- Signatures + school stamp — identical rules to the term
             card: the stamp renders ONLY when a stamp image has been
             uploaded in Settings (owner decision D4). --}}
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
                        <div class="rc-sign-role">CHECKED BY</div>
                        <div class="rc-sign-name"></div>
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

        {{-- Issue line (Loop 85): the real generation date on the
             left, the system attribution on the right. Signature
             imagery follows the owner's digital-signature ruling
             (2026-10-22): the principal's uploaded signature renders
             above the Principal line only while one is configured in
             Settings — never a fabricated signature. --}}
        <table class="rc-issue">
            <tr>
                <td><strong>Date of Issue:</strong> {{ date('d M Y') }}</td>
                <td class="rc-issue-right">Generated by Hackstra School Management System</td>
            </tr>
        </table>
    </div>
</div>
