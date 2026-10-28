<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Class_Roster_{{ optional($period['class'])->name }}_{{ optional($period['subject'])->name }}</title>
    {{-- Class Roster print document (wave 31 fix).
         Pagination model (dompdf 3.x, verified by render):
         - @page reserves a tall top margin; the school header lives
           in that margin as a position:fixed block, so dompdf repeats
           it on EVERY page (flow content never repeats).
         - thead repeats natively (proper thead + table-header-group).
         - the footer is position:fixed with counter(page)/counter(pages).
         - tr never splits (page-break-inside: avoid).
         For the browser view ($for_pdf empty) the same fixed rules
         live under the print media block, so screen rendering stays normal
         while browser printing repeats the header too. --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #172536; margin: 0; padding: 24px; font-size: 11px; }
        @page { size: A4 portrait; margin: 44mm 10mm 16mm 10mm; }
        /* Fixed header/footer: dompdf path (body.pdf) + browser-print path. */
        body.pdf .rp-header, body.pdf .rp-footer { position: fixed; left: 0; right: 0; }
        body.pdf .rp-header { top: -42mm; }
        body.pdf .rp-footer { bottom: -12mm; }
        @media print {
            body { padding: 0; }
            .rp-header, .rp-footer { position: fixed; left: 0; right: 0; }
            .rp-header { top: -42mm; }
            .rp-footer { bottom: -12mm; }
            .noprint { display: none; }
        }
        .rp-header .band { background: #10263b; color: #fff; padding: 8px 14px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .rp-header .band table { width: 100%; border-collapse: collapse; }
        .rp-header .tile-cell { width: 52px; vertical-align: middle; }
        .rp-header .tile { display: block; width: 42px; height: 42px; background: #fff; color: #10263b; font-size: 16px; font-weight: 800; text-align: center; line-height: 42px; border-radius: 8px; }
        .rp-header .logo { max-height: 44px; max-width: 110px; }
        .rp-header .school { font-size: 18px; font-weight: bold; letter-spacing: .02em; }
        .rp-header .meta { color: #c7d6e8; font-size: 10px; margin-top: 2px; line-height: 1.4; }
        .rp-header .titlebar { width: 100%; border-collapse: collapse; border-bottom: 2px solid #10263b; }
        .rp-header .titlebar td { padding: 7px 14px; vertical-align: top; }
        .rp-header .doc-title { font-size: 17px; font-weight: bold; color: #10263b; letter-spacing: .04em; }
        .rp-header .note { color: #718096; font-size: 11px; margin-top: 3px; }
        .rp-header .refbox { text-align: right; font-size: 11px; line-height: 1.55; }
        .rp-header .refbox .ref-no { font-size: 14px; font-weight: bold; color: #10263b; }
        .rp-footer { width: 100%; border-collapse: collapse; font-size: 10px; color: #718096; }
        .rp-footer td { vertical-align: top; }
        table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.items thead { display: table-header-group; }
        table.items tr { page-break-inside: avoid; }
        table.items th { background: #eef2f7; border: 1px solid #b9c6d4; padding: 6px 7px; font-size: 11px; text-align: left; color: #10263b; overflow-wrap: break-word; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.items td { border: 1px solid #b9c6d4; padding: 6px 7px; font-size: 11px; overflow-wrap: break-word; vertical-align: top; }
        table.items td.box { text-align: center; }
        .endnote { color: #718096; font-size: 11px; margin: 14px 0 0; }
        .sign { text-align: right; color: #172536; margin-top: 8px; }
        .sign .line { display: inline-block; width: 180px; border-top: 1px solid #172536; margin-top: 26px; padding-top: 4px; font-size: 12px; }
        .printbar { max-width: 820px; margin: 0 auto 14px; text-align: right; }
        .printbar button { font: inherit; padding: 8px 18px; border: 1px solid #10263b; background: #10263b; color: #fff; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body @if(!empty($for_pdf)) class="pdf" @endif>
@if(empty($for_pdf))
<div class="printbar noprint"><button type="button" onclick="window.print()">Print</button></div>
@endif
@php $rLogo = $s['logo'] ?? \App\Helpers\Qs::getSetting('logo'); @endphp
<div class="rp-header">
    <div class="band">
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
                        {{ $s['address'] ?? '' }}
                        @if(!empty($s['phone'])) &nbsp;|&nbsp; Tel: {{ $s['phone'] }} @endif
                        @if(!empty($s['system_email'])) &nbsp;|&nbsp; Email: {{ $s['system_email'] }} @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>
    <table class="titlebar">
        <tr>
            <td>
                <div class="doc-title">CLASS ROSTER</div>
                <div class="note">{{ $students->count() }} students &middot; Roll numbers allocated by the system in admission order.</div>
            </td>
            <td class="refbox">
                <div class="ref-no">{{ optional($period['subject'])->name }}</div>
                {{ optional($period['class'])->name }}@if(optional($period['section'])->name) &middot; Section {{ $period['section']->name }}@endif &nbsp;|&nbsp; {{ optional($period['teacher'])->name ?: '—' }}<br>
                {{ $date->format('l, d M Y') }} &nbsp;|&nbsp; {{ $period['time_label'] }}
            </td>
        </tr>
    </table>
</div>

<table class="items">
    <thead>
    <tr>
        <th style="width:13%">Roll No</th>
        <th style="width:33%">Student</th>
        <th style="width:11%">Section</th>
        <th style="width:25%">Admission No</th>
        <th style="width:6%">P</th>
        <th style="width:6%">A</th>
        <th style="width:6%">L</th>
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

<p class="endnote">Blank P / A / L boxes are for manual marking. The same roll numbers appear on the attendance page and in the roster export.</p>
<div class="sign"><span class="line">{{ optional($period['teacher'])->name ?: 'Teacher' }}<br>Subject Teacher</span></div>

<table class="rp-footer">
    <tr>
        <td>Generated on: {{ now()->format('d M Y') }} &nbsp;|&nbsp; {{ now()->format('g:i A') }}</td>
    </tr>
</table>
</body>
</html>
