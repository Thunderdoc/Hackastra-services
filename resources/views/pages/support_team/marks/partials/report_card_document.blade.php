{{--
    Report Card — COMPLETE REDESIGN (owner order 2026-11-06: not a
    patch — the old design was removed and this one built new).

    ONE document partial for every report-card surface. The term
    card (marks.show, marks.print, marks.bulk_download) and the
    annual card (marks.annual, marks.print_annual, marks.pdf_annual)
    reach this file through their thin adapters
    (partials/report_card / partials/annual_report_card), so no two
    surfaces can ever drift apart again.

    Layout law (owner, 2026-11-06):
      1. The school logo sits CENTERED at the very top, alone on its
         line; the school name is centered below it; address +
         phone/email form ONE centered line under the name. Nothing
         shares the header lines.
      2. Then the document title band, then the student block.
      3. The marks table (dynamic scheme columns on the term card,
         term columns on the annual card), the summary strip and the
         FULL grading-system legend — always completely visible.
      4. One A4 page per student; the signature block (Class Teacher
         / Principal, the uploaded principal signature and the school
         stamp) closes the page at its foot. In the print/PDF hosts
         (wrapped in .rcd-doc) the sheet carries the full A4 height:
         the ANNUAL card's signatures anchor to the sheet foot (its
         content is short — they never float mid-page) while the
         TERM card's signatures close its near-full-height content
         in flow. On screen the block follows the content.

    Every figure comes from the existing assemblies
    (App\Support\ReportCard::build / buildAnnual) and the stored
    exam_records aggregates — presentation only, nothing is
    recomputed here. NULL = not entered, prints '–'; a stored 0
    prints 0. Branding (logo, name, address, phone, email, stamp,
    signature) comes from Settings only.

    Print technology rules (house dompdf lessons): solid colours
    only (no gradient chains), tables for geometry, no transforms.

    Expects: $rcd_mode ('term'|'annual'), $card, $sr, $my_class,
    $class_type, $s. Term also: $ex, $exr, $ct, optional $skills and
    $rc_show_traits. Annual also: $year.
--}}
@php
    $rcdLogo = $s['logo'] ?? Qs::getSetting('logo');
    $rcdCode = strtoupper((string) Qs::getAppCode());
    $rcdInitials = '';
    foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $rcdWord) {
        if ($rcdWord !== '') {
            $rcdInitials .= strtoupper(substr($rcdWord, 0, 1));
            if (strlen($rcdInitials) >= 2) { break; }
        }
    }
    if ($rcdInitials === '') { $rcdInitials = 'S'; }
    $rcdPhone = $s['phone'] ?? null;
    $rcdEmail = $s['system_email'] ?? null;
    $rcdBands = Mk::getGradeList($class_type->id);
    $rcdAge = $sr->age ?: ($sr->user->dob ? date_diff(date_create($sr->user->dob), date_create('now'))->y : '-');

    if ($rcd_mode === 'term') {
        $rcdTitle = 'REPORT CARD';
        $rcdSubtitle = ($class_type ? $class_type->name.' · ' : '').$ex->name.' · '.strip_tags(Mk::getSuffix($ex->term)).' Term · '.$ex->year;
        $rcdScoreHead = 'TOTAL';
        $rcdScoreSub = '(100)';
        $rcdInfo2 = [
            ['TERM', strip_tags(Mk::getSuffix($ex->term)).' Term'],
            ['ACADEMIC YEAR', $ex->year],
            ['HOUSE', $sr->house ? strtoupper($sr->house) : '-'],
            ['AGE', $rcdAge],
        ];
        $rcdSummary = [
            ['TOTAL SCORES OBTAINED', $exr && $exr->total !== null ? $exr->total : '–'],
            ['FINAL AVERAGE', $exr && $exr->ave !== null ? $exr->ave : '–'],
            ['CLASS AVERAGE', $exr && $exr->class_ave !== null ? $exr->class_ave : '–'],
            ['POSITION', $exr && $exr->pos ? strip_tags(Mk::getSuffix($exr->pos)) : '–'],
        ];
    } else {
        $rcdTitle = 'ANNUAL REPORT CARD';
        $rcdSubtitle = 'Cumulative Report · Academic Year '.$year;
        $rcdScoreHead = 'ANNUAL SCORE';
        $rcdScoreSub = '(100)';
        $rcdInfo2 = [
            ['ACADEMIC YEAR', $year],
            ['REPORT TYPE', 'Annual / Cumulative'],
            ['HOUSE', $sr->house ? strtoupper($sr->house) : '-'],
            ['AGE', $rcdAge],
        ];
        $rcdSummary = [
            ['ANNUAL TOTAL MARKS', $card['footer']['total'] !== null ? $card['footer']['total'] : '–'],
            ['OVERALL ANNUAL AVERAGE', $card['footer']['overall'] !== null ? $card['footer']['overall'] : '–'],
            ['CLASS ANNUAL AVERAGE', $card['footer']['class_ave'] !== null ? $card['footer']['class_ave'] : '–'],
            ['ANNUAL POSITION', $card['footer']['pos'] ? strip_tags(Mk::getSuffix($card['footer']['pos'])) : '–'],
        ];
    }
@endphp
<style>
    /* Report card redesign 2026-11-06 — one family with the other
       official documents: navy #10263b ink, hairline #bfcfdf,
       tint #edf2f8. Solids only; tables for geometry. */
    .rcd{position:relative;background:#fff;color:#1c2b3a;font-size:10.5pt;padding:2pt;line-height:1.28;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .rcd-doc .rcd{height:274mm;}
    .rcd-head{text-align:center;}
    .rcd-logo-line{line-height:1;}
    .rcd-logo-img{height:40pt;max-width:170pt;}
    .rcd .rc-logo-tile{display:inline-block;width:46pt;height:46pt;line-height:46pt;text-align:center;background:#10263b;color:#fff;font-size:17pt;font-weight:800;letter-spacing:.03em;border-radius:8pt;}
    .rcd-school{color:#10263b;font-size:17pt;font-weight:800;text-transform:uppercase;letter-spacing:.3pt;margin-top:3pt;line-height:1.1;}
    .rcd-addr{color:#44546a;font-size:9.5pt;margin-top:2pt;line-height:1.15;}
    .rcd-contact{color:#5a6b80;font-size:9pt;margin-top:1pt;line-height:1.15;}
    .rcd-rule-a{border-top:2.4pt solid #10263b;margin-top:4pt;}
    .rcd-rule-b{border-top:.7pt solid #bfcfdf;margin-top:1.6pt;}
    .rcd-titleband{background:#10263b;color:#fff;text-align:center;padding:4pt 6pt 5pt;margin-top:4pt;}
    .rcd-title{color:#fff;font-size:12pt;font-weight:800;text-transform:uppercase;letter-spacing:1.2pt;}
    .rcd-subtitle{color:#cfe0f2;font-size:9.5pt;margin-top:1pt;letter-spacing:.3pt;line-height:1.15;}
    .rcd-watermark{position:absolute;top:33%;left:50%;margin-left:-165pt;width:330pt;opacity:.07;z-index:0;}
    .rcd-watermark-mono{position:absolute;top:38%;left:0;right:0;text-align:center;font-size:120pt;font-weight:800;letter-spacing:.05em;color:#10263b;opacity:.05;z-index:0;line-height:1;}
    .rcd-body{position:relative;z-index:1;}
    .rcd-info{width:100%;border-collapse:collapse;background:#edf2f8;border:1pt solid #bfcfdf;margin-top:4pt;}
    .rcd-info td{width:25%;padding:1.8pt 8pt 2.2pt;vertical-align:top;}
    .rcd-info tr.rcd-info-r2 td{border-top:1pt solid #d7e0ec;}
    .rcd-info-label{color:#5a6b80;font-size:7pt;text-transform:uppercase;letter-spacing:.7pt;line-height:1.1;}
    .rcd-info-value{color:#10263b;font-size:10pt;font-weight:700;margin-top:1pt;line-height:1.15;}
    .rcd-marks{width:100%;border-collapse:collapse;border:1.2pt solid #10263b;margin-top:5pt;}
    .rcd-marks th,.rcd-marks td{border:1pt solid #bfcfdf;padding:.7pt 3pt;text-align:center;font-size:9.1pt;line-height:1.12;}
    .rcd-marks thead th{background:#10263b;color:#fff;font-size:7.8pt;text-transform:uppercase;letter-spacing:.3pt;padding:2pt 3pt;border-color:#10263b;line-height:1.1;}
    .rcd-marks thead th .rcd-th-sub{color:#cfe0f2;font-size:7pt;letter-spacing:.2pt;}
    .rcd-marks td.rcd-subject{font-weight:700;text-align:left;padding-left:6pt;color:#10263b;width:158pt;}
    .rcd-marks td.rcd-sn{color:#5a6b80;width:26pt;}
    .rcd-marks td.rcd-score{background:#eef3f9;font-weight:800;color:#10263b;}
    .rcd-marks td.rcd-grade{font-weight:800;color:#10263b;}
    .rcd-marks td.rcd-remark{text-align:left;font-size:8.8pt;}
    .rcd-marks tr.rcd-alt td{background:#f7fafc;}
    .rcd-marks tr.rcd-alt td.rcd-score{background:#e7eef6;}
    .rcd-summary{width:100%;border-collapse:collapse;background:#edf2f8;border:1pt solid #bfcfdf;margin-top:5pt;}
    .rcd-summary td{width:25%;padding:3.2pt 8pt;vertical-align:top;border-right:1pt solid #d7e0ec;}
    .rcd-summary td:last-child{border-right:0;}
    .rcd-summary-value{color:#10263b;font-size:11.5pt;font-weight:800;line-height:1.15;}
    .rcd-summary-label{color:#5a6b80;font-size:7pt;text-transform:uppercase;letter-spacing:.6pt;margin-top:1pt;}
    .rcd-legend{border:1pt solid #bfcfdf;margin-top:5pt;}
    .rcd-legend-head{background:#edf2f8;color:#10263b;font-size:8.5pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;padding:1.8pt 8pt;border-bottom:1pt solid #d7e0ec;}
    .rcd-bands{width:100%;border-collapse:collapse;}
    .rcd-bands td{padding:2pt 2pt;text-align:center;font-size:9pt;color:#44546a;line-height:1.15;}
    .rcd-bands td strong{color:#10263b;font-size:10pt;}
    .rcd-counts{width:100%;border-collapse:collapse;border-top:1pt solid #d7e0ec;}
    .rcd-counts td{padding:2pt 2pt;text-align:center;font-size:8.8pt;color:#44546a;line-height:1.15;}
    .rcd-counts td strong{color:#10263b;}
    .rcd .rc-key{font-size:9pt;color:#33475c;margin:3pt 0 1.5pt;line-height:1.15;}
    .rcd .rc-traits{width:100%;border-collapse:collapse;}
    .rcd .rc-traits>tbody>tr>td{vertical-align:top;padding:0;}
    .rcd .rc-traits>tbody>tr>td:first-child{padding-right:10pt;}
    .rcd .rc-trait-table{width:100%;border-collapse:collapse;border:1pt solid #bfcfdf;}
    .rcd .rc-trait-table th,.rcd .rc-trait-table td{border:1pt solid #d7e0ec;padding:.8pt 5pt;font-size:8.8pt;text-align:left;line-height:1.08;}
    .rcd .rc-trait-table thead th{background:#edf2f8;color:#10263b;font-size:8.5pt;letter-spacing:.4pt;}
    .rcd .rc-trait-table td.rc-rating,.rcd .rc-trait-table th.rc-rating{width:52pt;text-align:center;}
    .rcd .rc-comments{width:100%;border-collapse:collapse;margin-top:5pt;}
    .rcd .rc-comments td{padding:.6pt 3pt;font-size:9.2pt;vertical-align:top;line-height:1.18;}
    .rcd .rc-comments td.rc-clabel{width:146pt;font-weight:bold;color:#10263b;font-size:8.4pt;letter-spacing:.3pt;padding-top:2.2pt;}
    .rcd-signatures{margin-top:8pt;}
    .rcd-doc .rcd-annual .rcd-signatures{position:absolute;bottom:0;left:2pt;right:2pt;margin-top:0;}
    .rcd-sign-table{width:100%;border-collapse:collapse;}
    .rcd-sign-cell{padding:0 22pt 0 2pt;vertical-align:top;}
    .rcd-sign-space{height:21pt;}
    .rcd-sign-img{height:28pt;max-width:150pt;}
    .rcd-sign-line{border-top:1.1pt solid #33475c;}
    .rcd-sign-role{font-weight:800;margin-top:4pt;font-size:8.8pt;letter-spacing:.5pt;color:#10263b;}
    .rcd-sign-name{font-size:9.5pt;margin-top:1pt;min-height:12pt;}
    .rcd-stamp-cell{width:66pt;text-align:center;vertical-align:bottom;padding-bottom:2pt;}
    .rcd-stamp{width:56pt;height:56pt;}
    .rcd-issue{width:100%;border-collapse:collapse;margin-top:3pt;border-top:1pt solid #d7e0ec;}
    .rcd-issue td{font-size:8pt;color:#5a6b80;padding-top:2.5pt;}
    .rcd-issue td.rcd-issue-right{text-align:right;}
    @media print{
        .rcd{padding:0;}
    }
</style>

<div class="rcd rcd-{{ $rcd_mode }}">
    {{-- Header (owner law): logo centered ALONE at the top, school
         name centered below it, one centered address/contact line.
         The uploaded logo prints when one exists; otherwise a
         monogram tile from the school initials carries the identity
         — never the theme's light fallback logo. --}}
    <div class="rcd-head">
        <div class="rcd-logo-line">
            @if($rcdLogo)
                <img class="rcd-logo-img" src="{{ $rcdLogo }}" alt="">
            @else
                <span class="rc-logo-tile" aria-hidden="true">{{ $rcdInitials }}</span>
            @endif
        </div>
        <div class="rcd-school">{{ Qs::getSetting('system_name') }}</div>
        <div class="rcd-addr">{{ ucwords($s['address'] ?? Qs::getSetting('address')) }}</div>
        @if(!empty($rcdPhone) || !empty($rcdEmail))
            <div class="rcd-contact">{{ !empty($rcdPhone) ? 'Tel: '.$rcdPhone : '' }}{{ (!empty($rcdPhone) && !empty($rcdEmail)) ? ' | ' : '' }}{{ !empty($rcdEmail) ? 'Email: '.$rcdEmail : '' }}</div>
        @endif
    </div>
    <div class="rcd-rule-a"></div>
    <div class="rcd-rule-b"></div>

    {{-- Document title band --}}
    <div class="rcd-titleband">
        <div class="rcd-title">{{ $rcdTitle }}</div>
        <div class="rcd-subtitle">{{ $rcdSubtitle }}</div>
    </div>

    {{-- Watermark: the uploaded logo, faint and centered behind the
         marks area; with no logo, the settings app code as a large
         monogram. No transforms — plain absolute placement. --}}
    @if($rcdLogo)
        <img class="rcd-watermark" src="{{ $rcdLogo }}" alt="">
    @else
        <div class="rcd-watermark-mono" aria-hidden="true">{{ $rcdCode }}</div>
    @endif

    <div class="rcd-body">
        {{-- Student block: two label-over-value rows --}}
        <table class="rcd-info">
            <tbody>
            <tr>
                <td><div class="rcd-info-label">Student Name</div><div class="rcd-info-value">{{ strtoupper($sr->user->name) }}</div></td>
                <td><div class="rcd-info-label">Admission No</div><div class="rcd-info-value">{{ $sr->adm_no ?: '–' }}</div></td>
                <td><div class="rcd-info-label">Class</div><div class="rcd-info-value">{{ $my_class->name }}</div></td>
                <td><div class="rcd-info-label">Section</div><div class="rcd-info-value">{{ $sr->section ? $sr->section->name : '–' }}</div></td>
            </tr>
            <tr class="rcd-info-r2">
                @foreach($rcdInfo2 as $rcdInfoCell)
                    <td><div class="rcd-info-label">{{ $rcdInfoCell[0] }}</div><div class="rcd-info-value">{{ $rcdInfoCell[1] }}</div></td>
                @endforeach
            </tr>
            </tbody>
        </table>

        {{-- Marks table --}}
        <table class="rcd-marks">
            <thead>
            <tr>
                <th>S/N</th>
                <th style="text-align:left; padding-left:6pt;">Subject</th>
                @foreach($card['columns'] as $col)
                    @if($rcd_mode === 'term')
                        <th>{{ $col['label'] }}</th>
                    @else
                        <th>{{ $col['label'] }}<br><span class="rcd-th-sub">TOTAL (100)</span></th>
                    @endif
                @endforeach
                <th>{{ $rcdScoreHead }}<br><span class="rcd-th-sub">{{ $rcdScoreSub }}</span></th>
                <th>Grade</th>
                <th>Subject<br>Position</th>
                <th style="text-align:left;">Remarks</th>
            </tr>
            </thead>
            <tbody>
            @foreach($card['rows'] as $row)
                <tr{{ $loop->odd ? ' class="rcd-alt"' : '' }}>
                    <td class="rcd-sn">{{ $loop->iteration }}</td>
                    <td class="rcd-subject">{{ $row['subject']->name }}</td>
                    @foreach($card['columns'] as $col)
                        @php $cell = $rcd_mode === 'term' ? ($row['cells'][$col['key']] ?? null) : ($row['terms'][$col['key']] ?? null); @endphp
                        <td>{{ $cell === null ? '–' : $cell }}</td>
                    @endforeach
                    @php $rcdScore = $rcd_mode === 'term' ? $row['total'] : $row['annual']; @endphp
                    <td class="rcd-score">{{ $rcdScore === null ? '–' : $rcdScore }}</td>
                    <td class="rcd-grade">{{ $row['grade'] ?: '–' }}</td>
                    <td>{!! $row['pos'] ? Mk::getSuffix($row['pos']) : '–' !!}</td>
                    <td class="rcd-remark">{{ $row['remark'] ?: '–' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{-- Summary strip: the stored aggregates, exactly as computed --}}
        <table class="rcd-summary">
            <tr>
                @foreach($rcdSummary as $rcdSum)
                    <td><div class="rcd-summary-value">{{ $rcdSum[1] }}</div><div class="rcd-summary-label">{{ $rcdSum[0] }}</div></td>
                @endforeach
            </tr>
        </table>

        {{-- Grading system: the class type's configured bands, in a
             free-flowing cell row (no fixed layout, no nowrap) so
             every band is always completely visible. The term card
             adds its outcome counts underneath. --}}
        <div class="rcd-legend">
            <div class="rcd-legend-head">Grading System</div>
            @if($rcdBands->count())
                <table class="rcd-bands">
                    <tr>
                        @foreach($rcdBands as $gr)
                            <td><strong>{{ $gr->name }}</strong> &nbsp;{{ $gr->mark_from.' - '.$gr->mark_to }}</td>
                        @endforeach
                    </tr>
                </table>
            @endif
            @if($rcd_mode === 'term')
                <table class="rcd-counts">
                    <tr>
                        <td><strong>Number of</strong></td>
                        <td>Distinctions: <strong>{{ Mk::countDistinctions($card['exam_marks']) }}</strong></td>
                        <td>Credits: <strong>{{ Mk::countCredits($card['exam_marks']) }}</strong></td>
                        <td>Passes: <strong>{{ Mk::countPasses($card['exam_marks']) }}</strong></td>
                        <td>Failures: <strong>{{ Mk::countFailures($card['exam_marks']) }}</strong></td>
                        <td>Subjects Offered: <strong>{{ Mk::countSubjectsOffered($card['exam_marks']) }}</strong></td>
                    </tr>
                </table>
            @endif
        </div>

        @if($rcd_mode === 'term')
            {{-- Traits annex (printed document only) --}}
            @if(($rc_show_traits ?? false) && !empty($skills))
                @include('pages.support_team.marks.print.skills')
            @endif

            {{-- Comments (teacher / principal text + next-term lines) --}}
            @include('pages.support_team.marks.print.comments')
        @endif
    </div>

        {{-- Signatures + school stamp. The stamp renders ONLY when a
             stamp image has been uploaded in Settings (owner decision
             D4 — no placeholder seal is ever drawn); the principal's
             uploaded signature sits above the Principal line only
             while one is configured (owner ruling 2026-10-22). --}}
        <div class="rcd-signatures">
            <table class="rcd-sign-table">
                <tr>
                    <td class="rcd-sign-cell">
                        <div class="rcd-sign-space"></div>
                        <div class="rcd-sign-line"></div>
                        <div class="rcd-sign-role">CLASS TEACHER</div>
                        <div class="rcd-sign-name">{{ $card['class_teacher'] ?: '' }}</div>
                    </td>
                    @if($rcd_mode === 'annual')
                        <td class="rcd-sign-cell">
                            <div class="rcd-sign-space"></div>
                            <div class="rcd-sign-line"></div>
                            <div class="rcd-sign-role">CHECKED BY</div>
                            <div class="rcd-sign-name"></div>
                        </td>
                    @endif
                    <td class="rcd-sign-cell">
                        <div class="rcd-sign-space">@if(!empty($card['principal_signature']))<img class="rcd-sign-img" src="{{ $card['principal_signature'] }}" alt="Principal's signature">@endif</div>
                        <div class="rcd-sign-line"></div>
                        <div class="rcd-sign-role">PRINCIPAL</div>
                        <div class="rcd-sign-name">{{ $card['principal_name'] ?: '' }}</div>
                    </td>
                    @if(!empty($card['stamp']))
                        <td class="rcd-stamp-cell"><img class="rcd-stamp" src="{{ $card['stamp'] }}" alt="School stamp"></td>
                    @endif
                </tr>
            </table>
            <table class="rcd-issue">
                <tr>
                    <td><strong>Date of Issue:</strong> {{ date('d M Y') }}</td>
                    <td class="rcd-issue-right">Generated by Hackstra</td>
                </tr>
            </table>
        </div>
    </div>
</div>
