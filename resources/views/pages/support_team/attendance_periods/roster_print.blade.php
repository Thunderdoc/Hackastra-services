<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Class_Roster_{{ optional($period['class'])->name }}_{{ optional($period['subject'])->name }}</title>
    {{-- Self-contained styles: same v2 document tokens as the
         submitted-register print (navy #10263b, hairline #b9c6d4,
         shaded heads #eef2f7) — dompdf cannot fetch remote
         stylesheets, so everything lives in this block. This is
         the take-page roster: blank P/A/L boxes for manual
         classroom use, with the system roll numbers. --}}
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
        table.info, table.items { width: 100%; border-collapse: collapse; }
        table.info td { padding: 5px 8px; font-size: 13px; vertical-align: top; }
        table.info td.k { color: #718096; width: 110px; font-weight: bold; }
        table.items th { background: #eef2f7; border: 1px solid #b9c6d4; padding: 8px; font-size: 12px; text-align: left; color: #10263b; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.items td { border: 1px solid #b9c6d4; padding: 8px; font-size: 13px; }
        table.items td.box { width: 38px; text-align: center; }
        .note { color: #718096; font-size: 12px; margin: 0 0 4px; }
        .footer { width: 100%; border-collapse: collapse; margin-top: 26px; }
        .footer td { padding: 0 22px 18px; font-size: 11px; color: #718096; vertical-align: bottom; }
        .sign { text-align: right; color: #172536; }
        .sign .line { display: inline-block; width: 180px; border-top: 1px solid #172536; margin-top: 34px; padding-top: 4px; font-size: 12px; }
        @media print { body { padding: 0; } .doc { border: none; } .noprint { display: none; } }
        .printbar { max-width: 820px; margin: 0 auto 14px; text-align: right; }
        .printbar button { font: inherit; padding: 8px 18px; border: 1px solid #10263b; background: #10263b; color: #fff; border-radius: 6px; cursor: pointer; }
        @if(!empty($for_pdf))
        body { padding: 10px; }
        .footer { margin-top: 14px; }
        .footer td { padding-bottom: 10px; }
        .sign .line { margin-top: 22px; }
        @endif
    </style>
</head>
<body>
@if(empty($for_pdf))
<div class="printbar noprint"><button type="button" onclick="window.print()">Print</button></div>
@endif
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

    {{-- Title + roster reference --}}
    <table class="titlebar">
        <tr>
            <td>
                <div class="doc-title">CLASS ROSTER</div>
                <div class="note" style="margin-top:8px;">{{ $students->count() }} students &middot; Roll numbers allocated by the system in admission order.</div>
            </td>
            <td class="refbox">
                <div class="ref-no">{{ optional($period['subject'])->name }}</div>
                {{ optional($period['class'])->name }}@if(optional($period['section'])->name) &middot; {{ $period['section']->name }}@endif<br>
                {{ $date->format('l, d M Y') }}
            </td>
        </tr>
    </table>

    <div class="section">
        <h3>Roster Details</h3>
        <table class="info">
            <tr>
                <td class="k">Class</td>
                <td>{{ optional($period['class'])->name }}@if(optional($period['section'])->name) &middot; Section {{ $period['section']->name }}@endif</td>
                <td class="k">Time</td>
                <td>{{ $period['time_label'] }}</td>
            </tr>
            <tr>
                <td class="k">Subject</td>
                <td>{{ optional($period['subject'])->name }}</td>
                <td class="k">Teacher</td>
                <td>{{ optional($period['teacher'])->name ?: '—' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h3>Students</h3>
        <table class="items">
            <thead>
            <tr>
                <th style="width:64px">Roll No</th>
                <th>Student</th>
                <th>Section</th>
                <th>Admission No</th>
                <th style="width:38px">P</th>
                <th style="width:38px">A</th>
                <th style="width:38px">L</th>
            </tr>
            </thead>
            <tbody>
            @foreach($students as $student)
                <tr>
                    <td>{{ $student->roll_no ?? $loop->iteration }}</td>
                    <td>{{ optional($student->user)->name }}</td>
                    <td>{{ optional($student->section)->name ?: '—' }}</td>
                    <td>{{ $student->adm_no ?: '—' }}</td>
                    <td class="box">&nbsp;</td>
                    <td class="box">&nbsp;</td>
                    <td class="box">&nbsp;</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <table class="footer">
        <tr>
            <td>Blank P / A / L boxes are for manual marking. The same roll numbers appear on the attendance page and in the roster export.</td>
            <td class="sign"><span class="line">{{ optional($period['teacher'])->name ?: 'Teacher' }}<br>Subject Teacher</span></td>
        </tr>
    </table>
    <div class="genline">Generated by {{ $s['system_name'] ?? \App\Helpers\Qs::getSetting('system_name') }} &middot; {{ now()->format('d M Y, g:i A') }}</div>
</div>
</body>
</html>
