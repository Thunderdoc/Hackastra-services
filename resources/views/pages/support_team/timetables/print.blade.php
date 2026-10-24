<html>
<head>
    <title>TimeTable - {{ $ttr->name.' - '.$ttr->year }}</title>
    <style>
        /* Document design v2 (Workstream DB, Loop 68 tokens): accent
           navy #10263b, hairline #b9c6d4, shaded heads #eef2f7. The
           header carries the uploaded logo or a navy monogram tile;
           the watermark — logo or app-code monogram — sits behind
           the grid. Print geometry (landscape, cell padding) is the
           original document's, unchanged. */
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

        .tt-wrap { position: relative; }
        .tt-wm { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 280px; max-height: 340px; opacity: .06; z-index: 0; filter: grayscale(1); }
        .tt-wm-mono { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 150px; font-weight: 800; letter-spacing: .05em; line-height: 1; color: #10263b; opacity: .055; z-index: 0; white-space: nowrap; }

        table.tt { position: relative; z-index: 1; width: 100%; border-collapse: collapse; border: 1.5px solid #10263b; margin: 10px auto; }
        table.tt th, table.tt td { border: 1px solid #7d8fa6; padding: 20px 5px; text-align: center; font-size: 14px; }
        .tt-teacher { display: block; font-size: 11px; color: #5b6b80; font-weight: normal; margin-top: 3px; }
        table.tt thead th { background: #eef2f7; color: #10263b; }
        td.tt-day, th.tt-day { text-align: left; white-space: nowrap; }
        .tt-empty { color: #999; }

        .tt-genline { text-align: center; color: #718096; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; margin-top: 14px; border-top: 1px solid #b9c6d4; padding-top: 8px; }

        @media print {

            td, th {
                padding: 20px 5px;
                text-align: center;
                font-size: 14px;
            }

            @page {
                size: landscape;   /* auto is the initial value */
                margin: 0;  /* this affects the margin in the printer settings */
            }

            html {
                background-color: #FFFFFF;
                margin: 0; /* this affects the margin on the html before sending to printer */
            }

            body {
                margin: 0 10mm; /* margin you want for the content */
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div id="print" xmlns:margin-top="http://www.w3.org/1999/xhtml">
        {{-- Branded header (Settings: logo or monogram tile, school
             name, address, contacts) --}}
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
                    <span class="tt-addr">{{ ucwords($s['address']) }}</span><br/>
                    <span class="tt-contact">{{ !empty($s['phone']) ? 'Tel: '.$s['phone'] : '' }}{{ (!empty($s['phone']) && !empty($s['system_email'])) ? ' | ' : '' }}{{ !empty($s['system_email']) ? 'Email: '.$s['system_email'] : '' }}</span>
                </td>
                <td class="tt-brand-cell"></td>
            </tr>
        </table>

        <div class="tt-titleband">Timetable for {{ strtoupper($my_class->name. ' ('.$ttr->year.')' ) }}</div>

        @php
            // Aligned grid (Batch 11): one cell per time slot for every day,
            // looked up from the controller's $d_time contract — a missing
            // entry prints "—" instead of shifting the row's cells left.
            $ttxMap = [];
            foreach ($d_time as $dt) {
                $ttxMap[$dt['day']][$dt['time']] = ['subject' => $dt['subject'], 'teacher' => $dt['teacher'] ?? null];
            }
        @endphp

        <div class="tt-wrap">
            {{-- Watermark (v2): uploaded logo, else the app-code monogram --}}
            @if($ttLogo)
                <img class="tt-wm" src="{{ $ttLogo }}" alt="">
            @else
                <div class="tt-wm-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
            @endif

            {{-- Tabulation Begins --}}
            <table class="tt">
                <thead>
                <tr>
                    <th class="tt-day">Day</th>
                    @foreach($time_slots as $tms)
                        <th>{{ $tms->full }}</th>
                    @endforeach
                </tr>
                </thead>

                <tbody>
                @foreach($days as $day)
                    <tr>
                        @if($ttr->exam_id)
                            <td class="tt-day"><strong>{{ date('l', strtotime($day)) }} · {{ date('d/m/Y', strtotime($day)) }}</strong></td>
                        @else
                            <td class="tt-day"><strong>{{ $day }}</strong></td>
                        @endif
                        @foreach($time_slots as $tms)
                            @php $cell = $ttxMap[$day][$tms->full] ?? null; @endphp
                            @if(!empty($cell['subject']))
                                <td>{{ $cell['subject'] }}@if(!empty($cell['teacher']))<span class="tt-teacher">{{ $cell['teacher'] }}</span>@endif</td>
                            @else
                                <td class="tt-empty">&mdash;</td>
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

<script>
    window.print();
</script>
</body>
</html>
