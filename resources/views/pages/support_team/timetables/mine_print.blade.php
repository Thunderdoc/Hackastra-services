<html>
<head>
    <title>Teacher Weekly Timetable - {{ $teacher->name.' - '.$year }}</title>
    <style>
        /* Document design v2 (Workstream DB, Loop 68 tokens) — the same
           stylesheet as the class timetable print: accent navy #10263b,
           hairline #b9c6d4, shaded heads #eef2f7, landscape geometry.
           Loop 81 adds only the teacher meta line and the two-line cell
           (subject + class) this document needs. */
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #172536;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .tt-head { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #10263b; }
        .tt-head td { vertical-align: middle; }
        .tt-head .tt-brand-cell { width: 110px; text-align: left; }
        .tt-head .tt-main-cell { text-align: center; }
        .tt-tile { display: block; width: 64px; height: 64px; background: #10263b; color: #fff; font-size: 20px; font-weight: 800; text-align: center; line-height: 64px; border-radius: 8px; }
        .tt-logo { max-height: 90px; max-width: 150px; }
        .tt-school { color: #10263b; font-size: 25px; font-weight: bold; text-transform: uppercase; letter-spacing: .2px; }
        .tt-addr { color: #000; font-size: 15px; font-style: italic; }
        .tt-contact { color: #000; font-size: 12.5px; font-style: italic; }

        .tt-titleband { background: #10263b; color: #fff; text-align: center; padding: 7px 8px; margin: 12px 0 4px; font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
        .tt-meta { text-align: center; color: #46586c; font-size: 13px; margin: 8px 0 2px; }
        .tt-meta strong { color: #10263b; }

        .tt-wrap { position: relative; }
        /* B round 6: on screens narrower than the grid, the wide
           week table scrolls INSIDE its wrap (the accepted
           .l7-table-scroll pattern) instead of pushing the whole
           document sideways — at 360px the page scrolled to ~787px.
           Screen-only: print geometry is untouched. */
        @media screen {
            .tt-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
        }
        .tt-wm { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 280px; max-height: 340px; opacity: .06; z-index: 0; filter: grayscale(1); }
        .tt-wm-mono { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 150px; font-weight: 800; letter-spacing: .05em; line-height: 1; color: #10263b; opacity: .055; z-index: 0; white-space: nowrap; }

        table.tt { position: relative; z-index: 1; width: 100%; border-collapse: collapse; border: 1.5px solid #10263b; margin: 10px auto; }
        table.tt th, table.tt td { border: 1px solid #7d8fa6; padding: 20px 5px; text-align: center; font-size: 14px; }
        table.tt thead th { background: #eef2f7; color: #10263b; }
        td.tt-day, th.tt-day { text-align: left; white-space: nowrap; }
        .tt-empty { color: #999; }
        .tt-cell-sub { display: block; font-size: 11px; color: #5b6b7f; margin-top: 3px; }
        .tt-chip { display: inline-block; width: 9px; height: 9px; border-radius: 3px; margin-right: 5px; vertical-align: baseline; }
        .tt-period { display: block; font-size: 10px; font-weight: normal; color: #5b6b7f; text-transform: uppercase; letter-spacing: .08em; margin-top: 2px; }

        .tt-genline { text-align: center; color: #718096; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; margin-top: 14px; border-top: 1px solid #b9c6d4; padding-top: 8px; }

        @media print {
            td, th {
                padding: 20px 5px;
                text-align: center;
                font-size: 14px;
            }

            @page {
                size: landscape;
                margin: 0;
            }

            html {
                background-color: #FFFFFF;
                margin: 0;
            }

            body {
                margin: 0 10mm;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div id="print" xmlns:margin-top="http://www.w3.org/1999/xhtml">
        {{-- Branded header (Settings: logo or monogram tile, school
             name, address, contacts) — identical to the class print --}}
        @php $ttLogo = $s['logo'] ?? Qs::getSetting('logo'); @endphp
        <table class="tt-head">
            <tr>
                <td class="tt-brand-cell">
                    @if($ttLogo)
                        <img class="tt-logo" src="{{ $ttLogo }}" alt="">
                    @else
                        @php
                            $ttInitials = '';
                            foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $ttWord) {
                                if ($ttWord !== '') {
                                    $ttInitials .= strtoupper(substr($ttWord, 0, 1));
                                    if (strlen($ttInitials) >= 2) { break; }
                                }
                            }
                            if ($ttInitials === '') { $ttInitials = 'S'; }
                        @endphp
                        <span class="tt-tile" aria-hidden="true">{{ $ttInitials }}</span>
                    @endif
                </td>
                <td class="tt-main-cell">
                    <span class="tt-school">{{ strtoupper(Qs::getSetting('system_name')) }}</span><br/>
                    <span class="tt-addr">{{ ucwords($s['address'] ?? '') }}</span><br/>
                    <span class="tt-contact">{{ !empty($s['phone']) ? 'Tel: '.$s['phone'] : '' }}{{ (!empty($s['phone']) && !empty($s['system_email'])) ? ' | ' : '' }}{{ !empty($s['system_email']) ? 'Email: '.$s['system_email'] : '' }}</span>
                </td>
                <td class="tt-brand-cell"></td>
            </tr>
        </table>

        <div class="tt-titleband">Teacher Weekly Timetable</div>
        <p class="tt-meta">Teacher: <strong>{{ $teacher->name }}</strong> &middot; Academic Session: <strong>{{ $year }}</strong> &middot; {{ $stats['periods'] }} period{{ $stats['periods'] === 1 ? '' : 's' }} per week</p>

        <div class="tt-wrap">
            {{-- Watermark (v2): uploaded logo, else the app-code monogram --}}
            @if($ttLogo)
                <img class="tt-wm" src="{{ $ttLogo }}" alt="">
            @else
                <div class="tt-wm-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
            @endif

            <table class="tt">
                <thead>
                <tr>
                    <th class="tt-day">Day</th>
                    @php
                        $ttCols = [];
                        $ttPrev = null;
                        foreach (collect($slots)->sortBy(function ($s) { return strtotime($s->time_from); })->values() as $ttSlot) {
                            if ($ttPrev) {
                                $ttGap = (strtotime($ttSlot->time_from) - strtotime($ttPrev->time_to)) / 60;
                                if ($ttGap > 0) {
                                    $ttCols[] = ['gap' => true, 'label' => $ttGap >= 30 ? 'Lunch' : 'Break', 'range' => $ttPrev->time_to . ' – ' . $ttSlot->time_from];
                                }
                            }
                            $ttCols[] = ['gap' => false, 'slot' => $ttSlot];
                            $ttPrev = $ttSlot;
                        }
                        $ttPeriodNo = [];
                        $ttN = 0;
                        foreach ($ttCols as $ttCol) {
                            if (!$ttCol['gap']) { $ttN++; $ttPeriodNo[$ttCol['slot']->full] = $ttN; }
                        }
                    @endphp
                    @foreach($ttCols as $ttCol)
                        @if($ttCol['gap'])
                            <th>{{ $ttCol['label'] }}<span class="tt-period">{{ $ttCol['range'] }}</span></th>
                        @else
                            <th>{{ $ttCol['slot']->full }}<span class="tt-period">Period {{ $ttPeriodNo[$ttCol['slot']->full] }}</span></th>
                        @endif
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach($days as $day)
                    <tr>
                        <td class="tt-day"><strong>{{ $day }}</strong></td>
                        @foreach($ttCols as $ttCol)
                            @if($ttCol['gap'])
                                <td class="tt-empty">{{ $ttCol['label'] }}</td>
                            @else
                                @php $cell = $map[$day][$ttCol['slot']->full] ?? null; @endphp
                                @if($cell && !empty($cell['title']))
                                    <td>@if(!empty($cell['color']))<span class="tt-chip" style="background: {{ $cell['color'] }}"></span>@endif{{ $cell['title'] }}@if(!empty($cell['sub']))<span class="tt-cell-sub">{{ $cell['sub'] }}</span>@endif</td>
                                @else
                                    <td class="tt-empty">Free</td>
                                @endif
                            @endif
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="tt-genline">Generated by Hackstra &middot; {{ Qs::getSystemName() }} &middot; Printed on {{ now()->format('d M Y') }}</div>
    </div>
</div>

@empty($for_pdf)
<script>
    window.print();
</script>
@endempty
</body>
</html>
