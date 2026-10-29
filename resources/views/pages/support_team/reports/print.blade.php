<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $doc['title'] }}</title>
    @php
        // Wide reports shrink one or two steps so narrow columns
        // stop breaking words mid-way (PDF polish, 2026-10-29).
        $colCount = count($doc['columns']);
        $fsBase = $doc['options']['font_size'];
        $fsBody = $colCount > 8 ? max(8, $fsBase - 2) : $fsBase;
        $fsHead = max(8, $fsBody - 1);
    @endphp
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #172536; margin: 0; padding: 0; font-size: {{ $doc['options']['font_size'] }}px; }
        @if($doc['options']['show_header'])
        @page { size: A4 {{ $doc['options']['orientation'] }}; margin: 48mm {{ $doc['options']['margin_mm'] }}mm 16mm {{ $doc['options']['margin_mm'] }}mm; }
        @else
        @page { size: A4 {{ $doc['options']['orientation'] }}; margin: 12mm {{ $doc['options']['margin_mm'] }}mm 16mm {{ $doc['options']['margin_mm'] }}mm; }
        @endif
        .rp-header, .rp-footer { position: fixed; left: 0; right: 0; }
        .rp-header { top: -46mm; }
        .rp-footer { bottom: -12mm; }
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
        .rp-header .subtitle { color: #718096; font-size: 11px; margin-top: 3px; }
        .rp-header .refbox { text-align: right; font-size: 11px; line-height: 1.55; }
        .rp-header .metalines { padding: 5px 14px; font-size: 10px; color: #4a5a6e; border-bottom: 1px solid #d5dce5; }
        .rp-footer { width: 100%; border-collapse: collapse; font-size: 10px; color: #718096; }
        .rp-footer td { vertical-align: top; }
        .instructions { color: #4a5a6e; font-size: 11px; margin: 0 0 12px; line-height: 1.5; }
        table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.items thead { display: table-header-group; }
        table.items tr { page-break-inside: avoid; }
        /* PDF polish (owner, 2026-10-29 night — "sab bekar hai"):
           the column header now wears the school's navy like the
           band above it (white text), one step smaller and tighter
           so words like "Section" stop breaking mid-word in narrow
           columns, and body rows alternate a faint tint so a long
           table reads as designed, not as a spreadsheet dump. */
        table.items th { background: #10263b; border: 1px solid #10263b; padding: 4px 5px; font-size: {{ $fsHead }}px; text-align: left; color: #ffffff; overflow-wrap: break-word; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.items td { border: 1px solid #b9c6d4; padding: 4px 6px; font-size: {{ $fsBody }}px; overflow-wrap: break-word; vertical-align: top; }
        table.items tr.alt td { background: #f4f7fb; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.items td.num { text-align: right; }
        table.items td.box, table.items td.center { text-align: center; }
        table.items td.sig { height: 30px; }
        table.items tr.totals-row td { font-weight: bold; border-top: 2px solid #10263b; background: #f7f9fc; }
        .tick { display: inline-block; width: 13px; height: 13px; border: 1.5px solid #334155; }
        .empty-note { color: #718096; font-size: 12px; margin: 18px 0; }
        /* Orphan-page fix: with 6px cell padding, a full last data page
           left the ~76px signature block alone on its own page (160-row
           landscape case: 11 pages, p.11 = signatures only). 4px rows
           + a tighter signature block pull it onto the last data page. */
        .sign { margin-top: 14px; width: 100%; border-collapse: collapse; }
        .sign td { width: 33%; vertical-align: bottom; padding-right: 24px; }
        .sign .line { border-top: 1px solid #172536; margin-top: 22px; padding-top: 4px; font-size: 12px; }
    </style>
</head>
<body>
@if($doc['options']['show_header'])
<div class="rp-header">
    <div class="band">
        <table>
            <tr>
                <td class="tile-cell">
                    @if(!empty($s['logo']))
                        <img class="logo" src="{{ $s['logo'] }}" alt="">
                    @else
                        <span class="tile">{{ \App\Helpers\Qs::initials($s['system_name'] ?? 'Hackstra Academy') }}</span>
                    @endif
                </td>
                <td>
                    <div class="school">{{ $s['system_name'] ?? 'Hackstra Academy' }}</div>
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
                <div class="doc-title">{{ strtoupper($doc['title']) }}</div>
                @if($doc['subtitle'] !== '')
                    <div class="subtitle">{{ $doc['subtitle'] }}</div>
                @endif
            </td>
            <td class="refbox">
                <strong>{{ $doc['data_count'] }} record(s)</strong>
            </td>
        </tr>
    </table>
    @if($doc['options']['show_meta'] && !empty($doc['meta']))
        <div class="metalines">{{ implode(' · ', $doc['meta']) }}</div>
    @endif
</div>
@endif
@if($doc['options']['show_footer'])
<table class="rp-footer">
    <tr>
        <td>Generated on: {{ date('d M Y | g:i A') }}</td>
        <td style="text-align: right;"></td>
    </tr>
</table>
@endif
@if($doc['instructions'] !== '')
    <p class="instructions">{{ $doc['instructions'] }}</p>
@endif
@if(empty($doc['rows']))
    <p class="empty-note">No records found for the selected filters.</p>
@else
    <table class="items">
        <thead>
            <tr>
                @foreach($doc['columns'] as $col)
                    <th style="width: {{ $col['width_pct'] }}%{{ !empty($col['align']) ? '; text-align: ' . $col['align'] : '' }}">{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($doc['rows'] as $row)
                <tr @if($loop->even) class="alt" @endif>
                    @foreach($doc['columns'] as $col)
                        @php $cellType = $col['type']; $cellVal = $row['cells'][$col['key']] ?? ''; $cellAlign = !empty($col['align']) ? ' style="text-align: ' . $col['align'] . '"' : ''; @endphp
                        @if($cellType === 'checkbox' || $cellType === 'box')
                            <td class="box"><span class="tick"></span></td>
                        @elseif($cellType === 'yes_no')
                            <td class="center"><span class="tick"></span> Yes &nbsp; <span class="tick"></span> No</td>
                        @elseif($cellType === 'signature')
                            <td class="sig"></td>
                        @elseif($cellType === 'number' || $cellType === 'currency')
                            <td class="num"{!! $cellAlign !!}>{{ $cellVal }}</td>
                        @else
                            <td{!! $cellAlign !!}>{{ $cellVal }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
            @if(!empty($doc['totals']))
                <tr class="totals-row">
                    @foreach($doc['columns'] as $colIndex => $col)
                        @if($colIndex === 0)
                            {{-- A total on the FIRST column used to vanish: this cell
                                 unconditionally printed the "Total" label, so the
                                 column's own aggregate never appeared anywhere.
                                 Keep the label and append the value when present. --}}
                            <td>Total{{ isset($doc['totals_formatted'][$col['key']]) ? ' — ' . $doc['totals_formatted'][$col['key']] : '' }}</td>
                        @elseif(isset($doc['totals_formatted'][$col['key']]))
                            <td class="num"@if(!empty($col['align'])) style="text-align: {{ $col['align'] }}"@endif>{{ $doc['totals_formatted'][$col['key']] }}</td>
                        @else
                            <td></td>
                        @endif
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
@endif
@if($doc['options']['show_signatures'])
    <table class="sign">
        <tr>
            <td><div class="line">Prepared by</div></td>
            <td><div class="line">Checked by</div></td>
            <td><div class="line">Authorised by</div></td>
        </tr>
    </table>
@endif
</body>
</html>
