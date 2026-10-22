<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Attendance_Register_{{ optional($session->my_class)->name }}_{{ optional($session->subject)->name }}_{{ $session->date->format('Y-m-d') }}</title>
    {{-- Self-contained styles (same rule as the receipt document):
         this view is rendered both in the browser (Print) and by
         dompdf (Download PDF); dompdf cannot fetch remote stylesheets,
         so everything lives in this block. Document design v2 tokens:
         navy #10263b, hairline #b9c6d4, shaded heads #eef2f7. --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #172536; margin: 0; padding: 24px; font-size: 13px; }
        .doc { position: relative; max-width: 820px; margin: 0 auto; border: 1px solid #b9c6d4; overflow: hidden; }
        .wm { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 340px; max-height: 420px; opacity: .10; z-index: 0; }
        .wm-mono { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 128px; font-weight: 800; letter-spacing: .04em; line-height: 1; color: #10263b; opacity: .055; z-index: 0; white-space: nowrap; }
        .doc > *:not(.wm):not(.wm-mono) { position: relative; z-index: 1; }
        .header { background: #10263b; color: #fff; padding: 18px 22px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .header table { width: 100%; border-collapse: collapse; }
        .header .tile-cell { width: 64px; vertical-align: middle; }
        .header .tile { display: block; width: 52px; height: 52px; background: #fff; color: #10263b; font-size: 19px; font-weight: 800; text-align: center; line-height: 52px; border-radius: 8px; }
        .header .logo { max-height: 56px; max-width: 120px; }
        .header .school { font-size: 22px; font-weight: bold; letter-spacing: .02em; }
        .header .meta { color: #c7d6e8; font-size: 11px; margin-top: 4px; line-height: 1.5; }
        .genline { text-align: center; color: #718096; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; margin: 2px 22px 16px; }
        .titlebar { width: 100%; border-collapse: collapse; border-bottom: 2px solid #10263b; }
        .titlebar td { padding: 12px 22px; vertical-align: top; }
        .titlebar .doc-title { font-size: 19px; font-weight: bold; color: #10263b; letter-spacing: .04em; }
        .refbox { text-align: right; font-size: 12px; line-height: 1.7; }
        .refbox .ref-no { font-size: 16px; font-weight: bold; color: #10263b; }
        .section { padding: 14px 22px 4px; }
        .section h3 { margin: 0 0 8px; font-size: 12px; letter-spacing: .08em; color: #10263b; text-transform: uppercase; }
        table.info, table.items, table.totals { width: 100%; border-collapse: collapse; }
        table.info td { padding: 5px 8px; font-size: 13px; vertical-align: top; }
        table.info td.k { color: #718096; width: 110px; font-weight: bold; }
        table.totals td { border: 1px solid #b9c6d4; padding: 10px 8px; text-align: center; font-size: 13px; }
        table.totals .tv { display: block; font-size: 20px; font-weight: bold; color: #10263b; }
        table.totals .tl { color: #718096; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
        table.totals .present .tv { color: #188b66; }
        table.totals .absent .tv { color: #c0392b; }
        table.totals .late .tv { color: #b7791f; }
        table.items th { background: #eef2f7; border: 1px solid #b9c6d4; padding: 8px; font-size: 12px; text-align: left; color: #10263b; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.items td { border: 1px solid #b9c6d4; padding: 8px; font-size: 13px; }
        .status { font-weight: bold; }
        .status-present { color: #188b66; }
        .status-absent { color: #c0392b; }
        .status-late { color: #b7791f; }
        .stamp { display: inline-block; border: 2px solid #188b66; color: #188b66; font-weight: bold; padding: 4px 12px; letter-spacing: .08em; }
        .footer { width: 100%; border-collapse: collapse; margin-top: 26px; }
        .footer td { padding: 0 22px 18px; font-size: 11px; color: #718096; vertical-align: bottom; }
        .sign { text-align: right; color: #172536; }
        .sign .line { display: inline-block; width: 180px; border-top: 1px solid #172536; margin-top: 34px; padding-top: 4px; font-size: 12px; }
        @media print { body { padding: 0; } .doc { border: none; } .noprint { display: none; } }
        .printbar { max-width: 820px; margin: 0 auto 14px; text-align: right; }
        .printbar button { font: inherit; padding: 8px 18px; border: 1px solid #10263b; background: #10263b; color: #fff; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body>
<div class="printbar noprint"><button type="button" onclick="window.print()">Print</button></div>
<div class="doc">
    {{-- Watermark (v2): uploaded logo, else the app-code monogram --}}
    @php $rLogo = $s['logo'] ?? \App\Helpers\Qs::getSetting('logo'); @endphp
    @if($rLogo)
        <img class="wm" src="{{ $rLogo }}" alt="">
    @else
        <div class="wm-mono" aria-hidden="true">{{ strtoupper((string) \App\Helpers\Qs::getAppCode()) }}</div>
    @endif

    {{-- School header (from Settings) --}}
    <div class="header">
        <table>
            <tr>
                <td class="tile-cell">
                    @if($rLogo)
                        <img class="logo" src="{{ $rLogo }}" alt="">
                    @else
                        @php
                            $rInitials = '';
                            foreach (preg_split('/\s+/', trim((string) ($s['system_name'] ?? \App\Helpers\Qs::getSetting('system_name')))) as $rWord) {
                                if ($rWord !== '') {
                                    $rInitials .= strtoupper(substr($rWord, 0, 1));
                                    if (strlen($rInitials) >= 2) { break; }
                                }
                            }
                            if ($rInitials === '') { $rInitials = 'S'; }
                        @endphp
                        <span class="tile" aria-hidden="true">{{ $rInitials }}</span>
                    @endif
                </td>
                <td>
                    <div class="school">{{ strtoupper($s['system_name'] ?? \App\Helpers\Qs::getSetting('system_name')) }}</div>
                    <div class="meta">
                        {{ $s['address'] ?? '' }}<br>
                        @if(!empty($s['phone'])) Tel: {{ $s['phone'] }} @endif
                        @if(!empty($s['system_email'])) &nbsp;|&nbsp; Email: {{ $s['system_email'] }} @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Title + register reference --}}
    <table class="titlebar">
        <tr>
            <td>
                <div class="doc-title">PERIOD ATTENDANCE REGISTER</div>
                <div style="margin-top:8px;"><span class="stamp">SUBMITTED &middot; LOCKED</span></div>
            </td>
            <td class="refbox">
                <div class="ref-no">{{ optional($session->subject)->name }}</div>
                {{ optional($session->my_class)->name }}@if(optional($session->section)->name) &middot; {{ $session->section->name }}@endif<br>
                {{ $session->date->format('l, d M Y') }}
            </td>
        </tr>
    </table>

    <div class="section">
        <h3>Register Details</h3>
        <table class="info">
            <tr>
                <td class="k">Date</td>
                <td>{{ $session->date->format('l, d M Y') }}</td>
                <td class="k">Time</td>
                <td>{{ $time_label }}</td>
            </tr>
            <tr>
                <td class="k">Teacher</td>
                <td>{{ optional($session->teacher)->name ?: '—' }}</td>
                <td class="k">Submitted At</td>
                <td>{{ $session->submitted_at ? $session->submitted_at->format('d M Y, g:i A') : '—' }}</td>
            </tr>
            <tr>
                <td class="k">Topic Taught</td>
                <td colspan="3">{{ $session->topic ?: '—' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h3>Attendance Summary</h3>
        <table class="totals">
            <tr>
                <td><span class="tv">{{ $counts['total'] }}</span><span class="tl">Total Students</span></td>
                <td class="present"><span class="tv">{{ $counts['present'] }}</span><span class="tl">Present</span></td>
                <td class="absent"><span class="tv">{{ $counts['absent'] }}</span><span class="tl">Absent</span></td>
                <td class="late"><span class="tv">{{ $counts['late'] }}</span><span class="tl">Late</span></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h3>Student Attendance</h3>
        <table class="items">
            <thead>
            <tr>
                <th style="width:36px">#</th>
                <th>Student</th>
                <th>Section</th>
                <th>Admission No</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            @foreach($entries as $entry)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ optional(optional($entry->student_record)->user)->name }}</td>
                    <td>{{ optional(optional($entry->student_record)->section)->name ?: '—' }}</td>
                    <td>{{ optional($entry->student_record)->adm_no ?: '—' }}</td>
                    <td><span class="status status-{{ $entry->status }}">{{ ucfirst($entry->status) }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <table class="footer">
        <tr>
            <td>This is a read-only record of a submitted attendance register. Submitted registers are locked and cannot be edited or re-taken.</td>
            <td class="sign"><span class="line">{{ optional($session->teacher)->name ?: 'Teacher' }}<br>Subject Teacher</span></td>
        </tr>
    </table>
    <div class="genline">Generated by {{ $s['system_name'] ?? \App\Helpers\Qs::getSetting('system_name') }} &middot; {{ now()->format('d M Y, g:i A') }}</div>
</div>
</body>
</html>
