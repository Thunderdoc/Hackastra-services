{{--
    Monthly Attendance Report — THE official document (redesign
    workstream, document design v2 family: navy #10263b, hairline
    #b9c6d4, shaded heads #eef2f7). One template, three surfaces: the
    web report's print preview (report_print) and the dompdf download
    (report_pdf) both render THIS partial from the same
    AttendanceController::monthlyReportData assembly — no divergent
    document code.

    Expects (from monthlyReportData): $selected_class, $month,
    $rows, $totals, $shares, $sessions_held, $class_summary,
    $generated_on, $school. Optional: $for_pdf (bool) — when true the
    letterhead uses the on-disk logo/stamp paths dompdf can read.

    Honesty rules baked in: identity comes from Settings only (the
    monogram tile when no logo is uploaded, exactly like the report
    card); the signature is a blank line — a name prints only when
    Settings carries one, a stamp only when one is uploaded; a
    student with no marked periods shows "—", never 0%.
--}}
@php
    $marPdf = $for_pdf ?? false;
    $marLogo = $marPdf ? ($school['logo_file'] ?? null) : ($school['logo'] ?? null);
    $marStamp = $marPdf ? ($school['stamp_file'] ?? null) : ($school['stamp'] ?? null);
    $marPalette = ['#e8effc', '#e6f6ec', '#fdf3e0', '#efe9fd', '#fdecec', '#e0f2f1'];
    $marInitialsOf = function ($name) {
        $out = '';
        foreach (preg_split('/\s+/', trim((string) $name)) as $w) {
            if ($w !== '') {
                $out .= strtoupper(substr($w, 0, 1));
                if (strlen($out) >= 2) { break; }
            }
        }
        return $out !== '' ? $out : '?';
    };
@endphp
<style>
    .mar-doc{position:relative;background:#fff;color:#172536;font-size:12.5px;padding:4px 2px;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-family:'Segoe UI',Helvetica,Arial,sans-serif;}
    .mar-doc table{border-collapse:collapse;}
    .mar-head{width:100%;border-bottom:2.5px solid #10263b;padding-bottom:8px;}
    .mar-school{color:#10263b;font-size:24px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;line-height:1.15;}
    .mar-tagline{color:#718096;font-size:9.5px;font-weight:700;letter-spacing:.34em;text-transform:uppercase;margin-top:2px;}
    .mar-addr{color:#44546a;font-size:12.5px;font-style:italic;margin-top:3px;}
    .mar-contact{color:#44546a;font-size:11.5px;margin-top:2px;}
    .mar-logo-tile{display:block;width:62px;height:62px;line-height:62px;text-align:center;background:#10263b;color:#fff;font-size:20px;font-weight:800;letter-spacing:.03em;border-radius:8px;}
    .mar-logo-img{max-height:64px;max-width:100px;}
    .mar-title{text-align:center;margin:8px 0 2px;color:#10263b;font-size:20px;font-weight:800;}
    .mar-subtitle{text-align:center;color:#44546a;font-size:13px;margin-bottom:8px;}
    .mar-meta{width:100%;margin-bottom:8px;}
    .mar-meta-label{color:#718096;font-size:9.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;}
    .mar-meta-value{color:#10263b;font-size:13px;font-weight:700;}
    .mar-meta-box{background:#eef2f7;padding:8px 14px;border-radius:6px;}
    .mar-chips{width:100%;margin-bottom:9px;}
    .mar-chips td{width:20%;padding:0 4px;vertical-align:top;}
    .mar-chips td:first-child{padding-left:0;}
    .mar-chips td:last-child{padding-right:0;}
    .mar-chip{border-radius:8px;padding:8px 12px;}
    .mar-chip-num{font-size:22px;font-weight:800;color:#10263b;line-height:1.1;}
    .mar-chip-label{font-size:11px;font-weight:700;color:#172536;margin-top:1px;}
    .mar-chip-cap{font-size:9.5px;color:#718096;margin-top:1px;}
    .mar-chip-present{background:#e6f6ec;}
    .mar-chip-absent{background:#fdecec;}
    .mar-chip-late{background:#fdf3e0;}
    .mar-chip-periods{background:#e8effc;}
    .mar-chip-registers{background:#efe9fd;}
    .mar-table{width:100%;border:1px solid #b9c6d4;table-layout:fixed;}
    .mar-table th,.mar-table td{border-bottom:1px solid #e2e8f0;padding:4px 7px;font-size:12px;text-align:center;overflow:hidden;}
    .mar-table thead th{background:#eef2f7;color:#10263b;font-size:11px;letter-spacing:.05em;text-transform:uppercase;border-bottom:1.5px solid #b9c6d4;}
    .mar-table thead{display:table-header-group;}
    .mar-table tbody tr{page-break-inside:avoid;}
    .mar-table td.mar-student{text-align:left;}
    .mar-table th.mar-student{text-align:left;}
    .mar-avatar{display:inline-block;width:22px;height:22px;line-height:22px;border-radius:50%;text-align:center;font-size:9.5px;font-weight:800;color:#33475c;margin-right:6px;vertical-align:middle;}
    .mar-name{font-weight:700;color:#172536;vertical-align:middle;font-size:11.5px;}
    .mar-absent-bad{color:#c53030;font-weight:800;}
    .mar-late-bad{color:#b7791f;font-weight:800;}
    .mar-bar{display:inline-block;width:60px;height:8px;background:#e2e8f0;border-radius:99px;vertical-align:middle;text-align:left;}
    .mar-bar-fill{display:block;height:8px;border-radius:99px;}
    .mar-fill-excellent{background:#22a35a;}
    .mar-fill-good{background:#3478f6;}
    .mar-fill-attention{background:#e8a13a;}
    .mar-fill-critical{background:#e05252;}
    .mar-fill-none{background:transparent;}
    .mar-pct{display:inline-block;margin-left:8px;font-weight:700;color:#10263b;vertical-align:middle;min-width:38px;text-align:left;}
    .mar-pill{display:inline-block;padding:2.5px 8px;border-radius:99px;font-size:10px;font-weight:700;white-space:nowrap;}
    .mar-pill-excellent{background:#e6f6ec;color:#157347;}
    .mar-pill-good{background:#e8effc;color:#2563eb;}
    .mar-pill-attention{background:#fdf3e0;color:#b7791f;}
    .mar-pill-critical{background:#fdecec;color:#c53030;}
    .mar-pill-none{background:#eef2f7;color:#718096;}
    .mar-policy{color:#718096;font-size:10.5px;margin-top:5px;}
    .mar-panels{width:100%;margin-top:8px;}
    .mar-panels>tbody>tr>td{width:50%;vertical-align:top;}
    .mar-panels>tbody>tr>td:first-child{padding-right:7px;}
    .mar-panels>tbody>tr>td:last-child{padding-left:7px;}
    .mar-panel{border:1px solid #d7e0ec;background:#f7fafc;padding:9px 13px;border-radius:6px;}
    .mar-panel-title{color:#10263b;font-size:12px;font-weight:800;margin-bottom:5px;}
    .mar-panel p{margin:0;color:#44546a;font-size:11.5px;line-height:1.55;}
    .mar-summary-grid{width:100%;}
    .mar-summary-grid td{width:50%;padding:5px 4px;vertical-align:top;}
    .mar-summary-num{font-size:17px;font-weight:800;color:#10263b;}
    .mar-summary-label{font-size:10.5px;color:#44546a;}
    .mar-sign{width:100%;margin-top:12px;}
    .mar-sign-line{border-bottom:1.5px solid #172536;height:22px;width:230px;}
    .mar-sign-role{font-weight:800;color:#10263b;font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;margin-top:6px;}
    .mar-sign-name{font-size:12.5px;color:#172536;margin-top:2px;min-height:15px;}
    .mar-stamp-img{max-height:88px;max-width:150px;}
    .mar-foot-meta{text-align:right;color:#44546a;font-size:11px;line-height:1.6;}
    .mar-foot-meta strong{color:#10263b;}
    .mar-genline{text-align:center;color:#718096;font-size:9px;letter-spacing:.16em;text-transform:uppercase;margin-top:10px;}
    .mar-watermark{position:absolute;top:40%;left:0;width:100%;text-align:center;font-size:150px;font-weight:800;letter-spacing:.04em;color:#10263b;opacity:.045;z-index:0;pointer-events:none;}
    .mar-body{position:relative;z-index:1;}
    .mar-empty{color:#718096;text-align:center;padding:18px 8px;font-size:12.5px;}
    @media print{
        .mar-doc{padding:0;}
    }
</style>

<div class="mar-doc">
    {{-- Letterhead — Settings identity only; monogram tile when no
         logo has been uploaded (never the theme's light fallback). --}}
    <table class="mar-head">
        <tr>
            <td style="width:110px;text-align:left;vertical-align:middle;">
                @if($marLogo)
                    <img class="mar-logo-img" src="{{ $marLogo }}" alt="">
                @else
                    <span class="mar-logo-tile" aria-hidden="true">{{ $school['initials'] }}</span>
                @endif
            </td>
            <td style="text-align:center;vertical-align:middle;">
                <div class="mar-school">{{ $school['name'] }}</div>
                <div class="mar-tagline">School Management System</div>
                @if(!empty($school['address']))
                    <div class="mar-addr">{{ ucwords($school['address']) }}</div>
                @endif
                @if(!empty($school['phone']) || !empty($school['email']))
                    <div class="mar-contact">{{ !empty($school['phone']) ? 'Tel: '.$school['phone'] : '' }}{{ (!empty($school['phone']) && !empty($school['email'])) ? '  |  ' : '' }}{{ !empty($school['email']) ? 'Email: '.$school['email'] : '' }}</div>
                @endif
            </td>
            <td style="width:110px;"></td>
        </tr>
    </table>

    <div class="mar-watermark" aria-hidden="true">{{ $school['code'] }}</div>

    <div class="mar-body">
        <div class="mar-title">Monthly Attendance Report</div>
        <div class="mar-subtitle">{{ $selected_class->name }} &nbsp;|&nbsp; {{ $month->format('F Y') }}</div>

        {{-- Metadata row --}}
        <table class="mar-meta">
            <tr>
                <td style="vertical-align:middle;">
                    <div class="mar-meta-label">Generated On</div>
                    <div class="mar-meta-value">{{ $generated_on->format('d F Y, g:i A') }}</div>
                </td>
                <td style="width:190px;padding-left:8px;">
                    <div class="mar-meta-box">
                        <div class="mar-meta-label">Academic Session</div>
                        <div class="mar-meta-value">{{ $school['session'] }}</div>
                    </div>
                </td>
                <td style="width:190px;padding-left:8px;">
                    <div class="mar-meta-box">
                        <div class="mar-meta-label">Class</div>
                        <div class="mar-meta-value">{{ $selected_class->name }}</div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Summary chips: ENTRIES (student-period counts) stay
             distinct from REGISTERS (submitted period registers). --}}
        <table class="mar-chips">
            <tr>
                <td><div class="mar-chip mar-chip-present">
                    <div class="mar-chip-num">{{ $totals['present'] }}</div>
                    <div class="mar-chip-label">Total Present</div>
                    <div class="mar-chip-cap">{{ number_format($shares['present'], 1) }}% of all marked entries</div>
                </div></td>
                <td><div class="mar-chip mar-chip-absent">
                    <div class="mar-chip-num">{{ $totals['absent'] }}</div>
                    <div class="mar-chip-label">Total Absent</div>
                    <div class="mar-chip-cap">{{ number_format($shares['absent'], 1) }}% of all marked entries</div>
                </div></td>
                <td><div class="mar-chip mar-chip-late">
                    <div class="mar-chip-num">{{ $totals['late'] }}</div>
                    <div class="mar-chip-label">Total Late</div>
                    <div class="mar-chip-cap">{{ number_format($shares['late'], 1) }}% of all marked entries</div>
                </div></td>
                <td><div class="mar-chip mar-chip-periods">
                    <div class="mar-chip-num">{{ $totals['marked'] }}</div>
                    <div class="mar-chip-label">Total Periods Marked</div>
                    <div class="mar-chip-cap">Student-period entries this month</div>
                </div></td>
                <td><div class="mar-chip mar-chip-registers">
                    <div class="mar-chip-num">{{ $sessions_held }}</div>
                    <div class="mar-chip-label">Registers Submitted</div>
                    <div class="mar-chip-cap">Period registers submitted this month</div>
                </div></td>
            </tr>
        </table>

        {{-- Student table --}}
        <table class="mar-table">
            <colgroup>
                <col style="width:4%"><col style="width:24%"><col style="width:9%">
                <col style="width:7%"><col style="width:7%"><col style="width:6%"><col style="width:7%">
                <col style="width:19%"><col style="width:17%">
            </colgroup>
            <thead>
            <tr>
                <th>#</th>
                <th class="mar-student">Student Name</th>
                <th>Section</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Late</th>
                <th>Periods</th>
                <th style="text-align:left;">Attendance %</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="mar-student"><span class="mar-avatar" style="background:{{ $marPalette[$loop->index % count($marPalette)] }}" aria-hidden="true">{{ $marInitialsOf(optional($row['student']->user)->name) }}</span><span class="mar-name">{{ optional($row['student']->user)->name }}</span></td>
                    <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                    <td>{{ $row['present'] }}</td>
                    <td>@if($row['absent'] > 0)<span class="mar-absent-bad">{{ $row['absent'] }}</span>@else{{ $row['absent'] }}@endif</td>
                    <td>@if($row['late'] > 0)<span class="mar-late-bad">{{ $row['late'] }}</span>@else{{ $row['late'] }}@endif</td>
                    <td>{{ $row['marked'] }}</td>
                    <td style="text-align:left;">
                        @if($row['percent'] === null)
                            <span style="color:#718096;">—</span>
                        @else
                            <span class="mar-bar"><span class="mar-bar-fill mar-fill-{{ $row['status_band'] }}" style="width:{{ $row['percent'] }}%"></span></span><span class="mar-pct">{{ $row['percent'] }}%</span>
                        @endif
                    </td>
                    <td><span class="mar-pill mar-pill-{{ $row['status_band'] }}">{{ $row['status_label'] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="9" class="mar-empty">No active students in this class yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mar-policy">Status policy: <strong>Excellent</strong> 90% and above &middot; <strong>Good</strong> 75&ndash;89% &middot; <strong>Needs Attention</strong> 50&ndash;74% &middot; <strong>Critical</strong> below 50%.</div>

        {{-- Summary note + class summary --}}
        <table class="mar-panels">
            <tr>
                <td>
                    <div class="mar-panel">
                        <div class="mar-panel-title">Summary Note</div>
                        <p>Counts are period registers: Attendance % = (present + late) / periods marked &times; 100. Periods with no submitted register are excluded &mdash; never counted as absent. A student with no marked periods this month shows &mdash; and carries no status.</p>
                    </div>
                </td>
                <td>
                    <div class="mar-panel">
                        <div class="mar-panel-title">Class Summary ({{ $selected_class->name }})</div>
                        <table class="mar-summary-grid">
                            <tr>
                                <td><div class="mar-summary-num">{{ $class_summary['students'] }}</div><div class="mar-summary-label">Total Students</div></td>
                                <td><div class="mar-summary-num">{{ $class_summary['with_100'] }}</div><div class="mar-summary-label">With 100% Attendance</div></td>
                            </tr>
                            <tr>
                                <td><div class="mar-summary-num">{{ $class_summary['below_75'] }}</div><div class="mar-summary-label">Below 75%</div></td>
                                <td><div class="mar-summary-num">{{ $class_summary['registers'] }}</div><div class="mar-summary-label">Registers Submitted</div></td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Official footer: a blank signature line (a name prints
             only when Settings carries one; the stamp only when one
             is uploaded — nothing is ever drawn or fabricated). --}}
        <table class="mar-sign">
            <tr>
                <td style="width:42%;vertical-align:bottom;">
                    <div class="mar-sign-line"></div>
                    <div class="mar-sign-role">Principal</div>
                    <div class="mar-sign-name">{{ $school['principal_name'] ?: '' }}</div>
                </td>
                <td style="text-align:center;vertical-align:bottom;">
                    @if($marStamp)
                        <img class="mar-stamp-img" src="{{ $marStamp }}" alt="School stamp">
                    @endif
                </td>
                <td style="width:38%;vertical-align:bottom;">
                    <div class="mar-foot-meta">
                        <strong>Monthly Attendance Report &mdash; {{ $selected_class->name }}</strong><br>
                        Printed on {{ $generated_on->format('d M Y, g:i A') }}
                    </div>
                </td>
            </tr>
        </table>

        <div class="mar-genline">Generated by Hackstra</div>
    </div>
</div>
