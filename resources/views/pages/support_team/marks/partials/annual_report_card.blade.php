{{--
    Batch M4 — THE annual / cumulative report card (design §3.6,
    owner decision D3). One template, three surfaces: the on-screen
    annual marksheet (marks.annual), the printed document
    (marks.print_annual) and the downloadable PDF (marks.pdf_annual)
    all render THIS partial from the
    App\Support\ReportCard::buildAnnual assembly — the annual twin
    of the M3 term card.

    Expects: $card (ReportCard::buildAnnual output), $sr, $year,
    $my_class, $class_type, $s (settings map), $ct (class type code).

    Display doctrine (same as the term card): NULL = no total for
    that term, prints '–'; a stored 0 is a real zero. The annual
    score is the mean of the term totals PRESENT — a missed term is
    excluded, never averaged in as 0. Totals/positions are computed
    once, in the assembler, from stored tex{term} values only.

    Design v3 (Loop 116, 2026-10-23 — replica pass against the
    owner's reference): no navy title band and no photo/initials box
    inside the document — the document title ("Annual Report Sheet /
    Cumulative Report – Academic Year …") sits in the letterhead's
    right cell, the student's identity lives in the card above the
    document on screen. Student facts are a six-cell label-above-
    value strip in normal case; GRADE and REMARKS are pills; the
    summary is four soft value-first chips; the grading key is a
    "Grading System" chip row. All styles stay table-based so the
    same markup renders identically on screen, in the browser print
    shell and in dompdf (no icon fonts, no flex/grid).

    Deliberately absent (no honest stored source exists):
    - the per-exam teacher/principal COMMENTS (they live on a
      single term's exam_record; an annual card has none);
    - the distinction/credit/pass counts (they count stored
      per-exam grade rows). The grading key below is printed from
      the same class-type bands as the term card.
--}}
<style>
    /* Document design v3 (Loop 116): light, reference-led — navy
       #10263b ink, hairline #d9e4f0, tinted strip #eaf2fc, soft
       summary chips. The watermark is ALWAYS on: the uploaded logo
       when one exists, otherwise a large acronym monogram from the
       settings app code. */
    .report-card{position:relative;background:#fff;color:#1c2b3a;font-size:13px;padding:6px 4px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .report-card .rc-head{width:100%;border-collapse:collapse;}
    .report-card .rc-head td{vertical-align:middle;}
    .report-card .rc-logo-img{max-height:64px;max-width:160px;}
    .report-card .rc-logo-tile{display:inline-block;width:56px;height:56px;line-height:56px;text-align:center;background:#e8f1fc;color:#10263b;font-size:19px;font-weight:800;letter-spacing:.03em;border-radius:12px;}
    .report-card .rc-school{color:#10263b;font-size:23px;font-weight:800;text-transform:uppercase;letter-spacing:.2px;}
    .report-card .rc-addr{color:#5a6b80;font-size:12.5px;margin-top:2px;}
    .report-card .rc-contact{color:#5a6b80;font-size:12px;margin-top:2px;}
    .report-card .rc-doctitle{text-align:right;}
    .report-card .rc-doc-name{color:#10263b;font-size:17px;font-weight:800;}
    .report-card .rc-doc-sub{color:#718096;font-size:12px;margin-top:3px;}
    .report-card .rc-rule{border-top:1px solid #dbe5f1;margin:10px 0 12px;}
    .report-card .rc-watermark{position:absolute;top:55%;left:50%;transform:translate(-50%,-50%);width:430px;max-height:560px;object-fit:contain;opacity:.10;z-index:0;pointer-events:none;}
    .report-card .rc-watermark-mono{position:absolute;top:55%;left:50%;transform:translate(-50%,-50%) rotate(-18deg);font-weight:800;letter-spacing:.05em;color:#10263b;opacity:.055;z-index:0;pointer-events:none;white-space:nowrap;line-height:1;}
    .report-card .rc-body{position:relative;z-index:1;}
    .report-card .rc-info{width:100%;border-collapse:separate;border-spacing:0;background:#eaf2fc;border-radius:10px;margin-bottom:12px;}
    .report-card .rc-info td{width:16.66%;padding:10px 12px;vertical-align:top;}
    .report-card .rc-info-label{color:#64748b;font-size:10.5px;margin-bottom:3px;}
    .report-card .rc-info-value{color:#10263b;font-size:13px;font-weight:700;}
    .report-card .rc-marks{width:100%;border-collapse:collapse;margin:6px auto 4px;}
    .report-card .rc-marks th,.report-card .rc-marks td{border:1px solid #d9e4f0;padding:6px 4px;text-align:center;font-size:12px;}
    .report-card .rc-marks thead th{background:#eef4fb;color:#33475c;font-size:11px;letter-spacing:.4px;}
    .report-card .rc-marks th.rc-sn,.report-card .rc-marks td.rc-sn{width:32px;color:#44546a;}
    .report-card .rc-marks td.rc-subject{font-weight:700;text-align:left;padding-left:8px;color:#10263b;}
    .report-card .rc-sub-tile{display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;background:#e6e9fd;color:#4f46e5;border-radius:6px;font-size:11px;font-weight:800;margin-right:7px;vertical-align:middle;}
    .report-card .rc-pill{display:inline-block;padding:2px 11px;border-radius:999px;font-size:11px;font-weight:700;}
    .report-card .rc-pill-a{background:#dcf2e3;color:#177245;}
    .report-card .rc-pill-b{background:#dcebfb;color:#1d5fae;}
    .report-card .rc-pill-c{background:#fdf0d5;color:#9a6a12;}
    .report-card .rc-pill-d{background:#fde8d7;color:#b3540e;}
    .report-card .rc-pill-e{background:#fde3e3;color:#b3352c;}
    .report-card .rc-pill-f{background:#fbdcdc;color:#a02c22;}
    .report-card .rc-pill-x{background:#e8edf3;color:#44546a;}
    .report-card .rc-chips{width:100%;border-collapse:separate;border-spacing:8px 4px;margin-top:8px;}
    .report-card .rc-chip{width:25%;border-radius:10px;padding:10px 12px;vertical-align:middle;}
    .report-card .rc-chip-value{font-size:17px;font-weight:800;color:#10263b;line-height:1.15;}
    .report-card .rc-chip-label{font-size:11px;color:#5a6b80;margin-top:2px;}
    .report-card .rc-chip-blue{background:#e3edfd;}
    .report-card .rc-chip-purple{background:#ece7fd;}
    .report-card .rc-chip-green{background:#dcf5e5;}
    .report-card .rc-chip-orange{background:#fdeeda;}
    .report-card .rc-grading{width:100%;border-collapse:collapse;margin-top:8px;}
    .report-card .rc-grading td{padding-top:10px;border-top:1px solid #e2e8f0;vertical-align:middle;}
    .report-card .rc-grading-label{color:#10263b;font-weight:800;font-size:12.5px;white-space:nowrap;width:150px;}
    .report-card .rc-info-i{display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%;background:#2563eb;color:#fff;font-size:11px;font-weight:700;font-style:normal;margin-right:6px;vertical-align:middle;}
    .report-card .rc-gchip{display:inline-block;margin:2px 12px 2px 0;font-size:11.5px;color:#44546a;white-space:nowrap;}
    .report-card .rc-gchip .rc-pill{margin-right:5px;}
    .report-card .rc-signatures{position:relative;margin-top:26px;padding-right:10px;}
    .report-card .rc-sign-table{width:100%;border-collapse:collapse;}
    .report-card .rc-sign-cell{width:33.33%;padding:0 30px 0 4px;vertical-align:top;}
    .report-card .rc-sign-role{color:#10263b;font-weight:700;font-size:12.5px;}
    .report-card .rc-sign-name{font-size:12.5px;margin-top:2px;min-height:16px;}
    .report-card .rc-sign-space{height:36px;}
    .report-card .rc-sign-img{display:block;height:34px;max-width:170px;object-fit:contain;}
    .report-card .rc-sign-line{border-top:1.5px solid #9db2c8;}
    .report-card .rc-stamp{position:absolute;right:56px;bottom:2px;width:110px;height:110px;object-fit:contain;transform:rotate(-10deg);opacity:.95;z-index:2;}
    .report-card .rc-issue{width:100%;border-collapse:collapse;margin-top:18px;border-top:1px solid #e2e8f0;}
    .report-card .rc-issue td{font-size:11px;color:#44546a;padding:8px 0 0;}
    .report-card .rc-issue td.rc-issue-right{text-align:right;color:#718096;}
    @media print{
        .report-card{padding:0;}
        .report-card .rc-marks th,.report-card .rc-marks td{padding:4px 3px;font-size:12px;}
    }
</style>

<div class="report-card">
    {{-- Branded header (settings: logo, school name, address,
         contacts) with the document title in the right cell — the
         reference letterhead. A real uploaded logo prints; with
         none uploaded a monogram tile built from the school
         initials carries the identity — the theme's light fallback
         logo is never used on paper. The student's photo/initials
         do NOT appear inside the document (Loop 116): identity
         lives in the card above it on screen. --}}
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
        // Pill tone for a grade letter (A green, B blue, C amber,
        // D orange, E/F reds, anything else grey).
        $rcGradePill = function ($grade) {
            $g = strtoupper(substr((string) $grade, 0, 1));
            return in_array($g, ['A', 'B', 'C', 'D', 'E', 'F'], true) ? 'rc-pill-' . strtolower($g) : 'rc-pill-x';
        };
        // Pill tone for a remark, by its wording.
        $rcRemarkPill = function ($remark) {
            $r = strtolower((string) $remark);
            if (strpos($r, 'excellent') !== false) { return 'rc-pill-b'; }
            if (strpos($r, 'very good') !== false || strpos($r, 'good') !== false) { return 'rc-pill-a'; }
            if (strpos($r, 'fair') !== false || strpos($r, 'average') !== false || strpos($r, 'credit') !== false) { return 'rc-pill-c'; }
            if (strpos($r, 'poor') !== false || strpos($r, 'weak') !== false || strpos($r, 'fail') !== false) { return 'rc-pill-f'; }
            return 'rc-pill-x';
        };
    @endphp
    <table class="rc-head">
        <tr>
            <td style="width:64px;">
                @if($rcLogo)
                    <img class="rc-logo-img" src="{{ $rcLogo }}" alt="">
                @else
                    <span class="rc-logo-tile" aria-hidden="true">{{ $rcInitials }}</span>
                @endif
            </td>
            <td style="padding-left:12px;">
                <span class="rc-school">{{ Qs::getSetting('system_name') }}</span>
                <div class="rc-addr">{{ ucwords($s['address'] ?? Qs::getSetting('address')) }}</div>
                @if(!empty($s['phone']) || !empty($s['system_email']))
                    <div class="rc-contact">{{ !empty($s['phone']) ? $s['phone'] : '' }}{{ (!empty($s['phone']) && !empty($s['system_email'])) ? ' | ' : '' }}{{ !empty($s['system_email']) ? $s['system_email'] : '' }}</div>
                @endif
            </td>
            <td class="rc-doctitle" style="width:260px;">
                <div class="rc-doc-name">Annual Report Sheet</div>
                <div class="rc-doc-sub">Cumulative Report &ndash; Academic Year {{ $year }}</div>
            </td>
        </tr>
    </table>
    <div class="rc-rule"></div>

    {{-- Watermark (v2): always on — uploaded logo, else app-code monogram. --}}
    @if($rcLogo)
        <img class="rc-watermark" src="{{ $rcLogo }}" alt="">
    @else
        <div class="rc-watermark-mono" style="font-size:{{ strlen($rcCode) > 5 ? 104 : 168 }}px;" aria-hidden="true">{{ $rcCode }}</div>
    @endif

    <div class="rc-body">
        {{-- Student block: six label-above-value cells, normal case --}}
        <table class="rc-info">
            <tbody>
            <tr>
                <td><div class="rc-info-label">Student Name</div><div class="rc-info-value">{{ $sr->user->name }}</div></td>
                <td><div class="rc-info-label">Admission No.</div><div class="rc-info-value">{{ $sr->adm_no ?: '–' }}</div></td>
                <td><div class="rc-info-label">Class</div><div class="rc-info-value">{{ $my_class->name }}</div></td>
                <td><div class="rc-info-label">Section</div><div class="rc-info-value">{{ $sr->section ? $sr->section->name : '–' }}</div></td>
                <td><div class="rc-info-label">Academic Year</div><div class="rc-info-value">{{ $year }}</div></td>
                <td><div class="rc-info-label">Report Type</div><div class="rc-info-value">Annual Report</div></td>
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
                <th style="text-align:left; padding-left:8px;">SUBJECT</th>
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
                    <td class="rc-subject"><span class="rc-sub-tile" aria-hidden="true">{{ strtoupper(substr((string) $row['subject']->name, 0, 1)) }}</span>{{ $row['subject']->name }}</td>
                    @foreach($card['columns'] as $col)
                        @php $cell = $row['terms'][$col['key']] ?? null; @endphp
                        <td>{{ $cell === null ? '–' : $cell }}</td>
                    @endforeach
                    <td>{{ $row['annual'] === null ? '–' : $row['annual'] }}</td>
                    <td>@if($row['grade'])<span class="rc-pill {{ $rcGradePill($row['grade']) }}">{{ $row['grade'] }}</span>@else – @endif</td>
                    <td>{!! $row['pos'] ? Mk::getSuffix($row['pos']) : '–' !!}</td>
                    <td>@if($row['remark'])<span class="rc-pill {{ $rcRemarkPill($row['remark']) }}">{{ $row['remark'] }}</span>@else – @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{-- Summary: four soft chips, value first — the annual
             aggregates from the assembly --}}
        <table class="rc-chips">
            <tr>
                <td class="rc-chip rc-chip-blue"><div class="rc-chip-value">{{ $card['footer']['total'] !== null ? $card['footer']['total'] : '–' }}</div><div class="rc-chip-label">Annual Total Marks</div></td>
                <td class="rc-chip rc-chip-purple"><div class="rc-chip-value">{{ $card['footer']['overall'] !== null ? $card['footer']['overall'] : '–' }}</div><div class="rc-chip-label">Overall Annual Average</div></td>
                <td class="rc-chip rc-chip-green"><div class="rc-chip-value">{{ $card['footer']['class_ave'] !== null ? $card['footer']['class_ave'] : '–' }}</div><div class="rc-chip-label">Class Annual Average</div></td>
                <td class="rc-chip rc-chip-orange"><div class="rc-chip-value">{!! $card['footer']['pos'] ? Mk::getSuffix($card['footer']['pos']) : '–' !!}</div><div class="rc-chip-label">Annual Position</div></td>
            </tr>
        </table>

        {{-- Grading System: the class type's bands, as on the term
             card. (The distinction/credit/pass COUNTS are not
             printed here: they count stored per-exam grade rows and
             an annual card has none — see the note at the top of
             this partial.) --}}
        <table class="rc-grading">
            <tr>
                <td class="rc-grading-label"><i class="rc-info-i">i</i>Grading System</td>
                <td>
                    @if(Mk::getGradeList($class_type->id)->count())
                        @foreach(Mk::getGradeList($class_type->id) as $gr)
                            <span class="rc-gchip"><span class="rc-pill {{ $rcGradePill($gr->name) }}">{{ $gr->name }}</span>{{ $gr->mark_from.' - '.$gr->mark_to }}</span>
                        @endforeach
                    @endif
                </td>
            </tr>
        </table>

        {{-- Signatures + school stamp — identical rules to the term
             card: the stamp renders ONLY when a stamp image has been
             uploaded in Settings (owner decision D4), and the
             principal's uploaded signature renders above the
             Principal line only while one is configured — never a
             fabricated signature (owner ruling 2026-10-22). --}}
        <div class="rc-signatures">
            @if(!empty($card['stamp']))
                <img class="rc-stamp" src="{{ $card['stamp'] }}" alt="School stamp">
            @endif
            <table class="rc-sign-table">
                <tr>
                    <td class="rc-sign-cell">
                        <div class="rc-sign-role">Class Teacher</div>
                        <div class="rc-sign-name">{{ $card['class_teacher'] ?: '' }}</div>
                        <div class="rc-sign-space"></div>
                        <div class="rc-sign-line"></div>
                    </td>
                    <td class="rc-sign-cell">
                        <div class="rc-sign-role">Checked By</div>
                        <div class="rc-sign-name"></div>
                        <div class="rc-sign-space"></div>
                        <div class="rc-sign-line"></div>
                    </td>
                    <td class="rc-sign-cell">
                        <div class="rc-sign-role">Principal</div>
                        <div class="rc-sign-name">{{ $card['principal_name'] ?: '' }}</div>
                        <div class="rc-sign-space">@if(!empty($card['principal_signature']))<img class="rc-sign-img" src="{{ $card['principal_signature'] }}" alt="Principal's signature">@endif</div>
                        <div class="rc-sign-line"></div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Issue line (Loop 85): the real generation date on the
             left, the system attribution on the right. --}}
        <table class="rc-issue">
            <tr>
                <td><strong>Date of Issue:</strong> {{ date('d M Y') }}</td>
                <td class="rc-issue-right">Generated by Hackstra School Management System</td>
            </tr>
        </table>
    </div>
</div>
