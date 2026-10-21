<html>
<head>
    <title>TimeTable - {{ $ttr->name.' - '.$ttr->year }}</title>
    <style>
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

        body {
            font-family: Arial, Helvetica, sans-serif;
        }

        td {
            text-align: center;
        }

        th {
            background-color: #f2f2f2;
        }

        td.ttx-print-day, th.ttx-print-day {
            text-align: left;
            white-space: nowrap;
        }

        .ttx-print-empty {
            color: #999;
        }

    </style>
</head>
<body>
<div class="container">
    <div id="print" xmlns:margin-top="http://www.w3.org/1999/xhtml">
        {{--    Logo N School Details--}}
        <table width="100%">
            <tr>
                <td >
                    <strong><span style="color: #1b0c80; font-size: 25px;">{{ strtoupper(config('app.name')) }}</span></strong><br/>
                    {{-- <strong><span style="color: #1b0c80; font-size: 20px;">MINNA, NIGER STATE</span></strong><br/>--}}
                    <strong><span style="color: #000; font-size: 15px;"><i>{{ ucwords($s['address']) }}</i></span></strong><br/>
                    <strong><span style="color: #000; text-decoration: underline; font-size: 15px;"><i>{{ config('app.url') }}</i></span></strong>
                    <br /> <br />
                    <strong><span style="color: #000; font-size: 15px;"> TIMETABLE FOR {{ strtoupper($my_class->name. ' ('.$ttr->year.')' ) }}
                    </span></strong>
                </td>
            </tr>
        </table>

        {{--Background Logo--}}
        <div style="position: relative;  text-align: center; ">
            <img src="{{ $s['logo'] }}"
                 style="max-width: 500px; max-height:600px; margin-top: 60px; position:absolute ; opacity: 0.2; margin-left: auto;margin-right: auto; left: 0; right: 0;" />
        </div>

        @php
            // Aligned grid (Batch 11): one cell per time slot for every day,
            // looked up from the controller's $d_time contract — a missing
            // entry prints "—" instead of shifting the row's cells left.
            $ttxMap = [];
            foreach ($d_time as $dt) {
                $ttxMap[$dt['day']][$dt['time']] = $dt['subject'];
            }
        @endphp

        {{-- Tabulation Begins --}}
        <table cellpadding="20" style="width:100%; border-collapse:collapse; border: 1px solid #000; margin: 10px auto;" border="1">
            <thead>
            <tr>
                <th class="ttx-print-day">Day</th>
                @foreach($time_slots as $tms)
                    <th>{{ $tms->full }}</th>
                @endforeach
            </tr>
            </thead>

            <tbody>
            @foreach($days as $day)
                <tr>
                    @if($ttr->exam_id)
                        <td class="ttx-print-day"><strong>{{ date('l', strtotime($day)) }} · {{ date('d/m/Y', strtotime($day)) }}</strong></td>
                    @else
                        <td class="ttx-print-day"><strong>{{ $day }}</strong></td>
                    @endif
                    @foreach($time_slots as $tms)
                        @php $subject = $ttxMap[$day][$tms->full] ?? null; @endphp
                        @if($subject)
                            <td>{{ $subject }}</td>
                        @else
                            <td class="ttx-print-empty">&mdash;</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<script>
    window.print();
</script>
</body>
</html>
