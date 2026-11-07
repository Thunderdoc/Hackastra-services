<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Hall Sheet - {{ $exam->name }} - {{ $hall['room_name'] }}</title>
    {{-- F3 Hall Sheet document (design §1.6/§6): ONE partial feeds
         the screen print and the dompdf download, switched by
         $for_pdf — the twins can never drift. Solids only, tables
         for geometry, NO transforms, NO watermark (a hall sheet is
         a working document checked at a door). Branding comes from
         ExamDateSheet::schoolInfo(); images are data URIs when
         $for_pdf. Stamp + principal signature render only while
         uploaded in Settings — nothing is fabricated. --}}
    <style>
        @page { size: a4; margin: 12mm; }
        html { background: #fff; }
        body {
            margin: 0;
            font-family: {{ $for_pdf ? '"DejaVu Sans", Arial, sans-serif' : '"Segoe UI", Arial, sans-serif' }};
            color: #172536;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .hs-head { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #10263b; }
        .hs-head td { vertical-align: middle; }
        .hs-brand-cell { width: 110px; text-align: left; }
        .hs-main-cell { text-align: center; }
        .hs-tile { display: block; width: 64px; height: 64px; background: #10263b; color: #fff; font-size: 20px; font-weight: 800; text-align: center; line-height: 64px; border-radius: 8px; }
        .hs-logo { height: 84px; width: auto; max-width: 150px; }
        .hs-school { color: #10263b; font-size: 25px; font-weight: bold; text-transform: uppercase; letter-spacing: .2px; }
        .hs-addr { color: #000; font-size: 15px; font-style: italic; }
        .hs-contact { color: #000; font-size: 12.5px; font-style: italic; }
        .hs-titleband { background: #10263b; color: #fff; text-align: center; padding: 7px 8px; margin: 12px 0 4px; font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
        .hs-meta { text-align: center; color: #46586c; font-size: 13px; margin: 8px 0 2px; }
        .hs-meta strong { color: #10263b; }
        table.hs { width: 100%; border-collapse: collapse; border: 1.5px solid #10263b; margin: 12px auto; }
        table.hs th, table.hs td { border: 1px solid #7d8fa6; padding: 7px 9px; font-size: 13px; text-align: left; }
        table.hs thead th { background: #eef2f7; color: #10263b; text-transform: uppercase; letter-spacing: .4px; font-size: 11.5px; }
        .hs-signatures { position: relative; margin-top: 34px; }
        .hs-stamp { position: absolute; right: 30px; bottom: 48px; width: 96px; height: 96px; z-index: 2; }
        .hs-sign-table { width: 100%; border-collapse: collapse; }
        .hs-sign-cell { width: 50%; padding: 0 30px; text-align: center; vertical-align: top; }
        .hs-sign-top { height: 44px; }
        .hs-sign-img { max-height: 44px; max-width: 180px; }
        .hs-sign-line { border-bottom: 1.5px solid #10263b; height: 40px; }
        .hs-sign-role { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: #46586c; margin-top: 6px; }
        .hs-sign-name { font-size: 13px; font-weight: bold; color: #10263b; }
        .hs-toolbar { text-align: right; margin: 10px 0; }
        .hs-btn { display: inline-block; background: #10263b; color: #fff; padding: 7px 14px; font-size: 13px; text-decoration: none; border-radius: 4px; }
        @media print { .hs-toolbar { display: none; } }
    </style>
</head>
<body>
    @if(!$for_pdf)
        <div class="hs-toolbar">
            <a href="#" class="hs-btn" onclick="window.print(); return false;">Print Hall Sheet</a>
        </div>
    @endif

    @php
        $initials = collect(explode(' ', trim($school['name'] ?? 'School')))->map(function ($w) { return strtoupper(substr($w, 0, 1)); })->take(2)->implode('');
        $dateLabel = \Carbon\Carbon::parse($plan->exam_date)->format('D, d M Y');
        $timeLabel = $plan->start_time ? \Carbon\Carbon::parse($plan->start_time)->format('h:i A') : '—';
    @endphp

    <table class="hs-head">
        <tr>
            <td class="hs-brand-cell">
                @if(!empty($school['logo']))
                    <img class="hs-logo" src="{{ $school['logo'] }}" alt="">
                @else
                    <span class="hs-tile">{{ $initials ?: 'S' }}</span>
                @endif
            </td>
            <td class="hs-main-cell">
                <div class="hs-school">{{ $school['name'] }}</div>
                @if(!empty($school['address']))<div class="hs-addr">{{ $school['address'] }}</div>@endif
                @if(!empty($school['phone']) || !empty($school['email']))
                    <div class="hs-contact">{{ $school['phone'] }}@if(!empty($school['phone']) && !empty($school['email'])) · @endif{{ $school['email'] }}</div>
                @endif
            </td>
            <td class="hs-brand-cell"></td>
        </tr>
    </table>

    <div class="hs-titleband">Exam Hall Sheet — {{ $exam->name }}</div>
    <p class="hs-meta">
        <strong>Hall:</strong> {{ $hall['room_name'] }} &nbsp;·&nbsp;
        <strong>Date:</strong> {{ $dateLabel }} &nbsp;·&nbsp;
        <strong>Time:</strong> {{ $timeLabel }} &nbsp;·&nbsp;
        <strong>Students:</strong> {{ $hall['seats']->count() }} of {{ $hall['capacity'] }} seats &nbsp;·&nbsp;
        <strong>Invigilator:</strong> {{ $hall['invigilator_label'] }}
    </p>

    <table class="hs">
        <thead>
            <tr>
                <th style="width:52px;">Seat</th>
                <th style="width:110px;">Adm No</th>
                <th>Student</th>
                <th style="width:130px;">Class</th>
                <th style="width:140px;">Subject</th>
                <th style="width:110px;">Sign</th>
            </tr>
        </thead>
        <tbody>
            @foreach($hall['seats'] as $seat)
                <tr>
                    <td>{{ $seat->seat_no }}</td>
                    <td>{{ $seat->adm_no ?: '—' }}</td>
                    <td>{{ $seat->student_name }}</td>
                    <td>{{ $seat->class_name }}@if($seat->section_name) — {{ $seat->section_name }}@endif</td>
                    <td>{{ $seat->subject_name }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="hs-signatures">
        @if(!empty($school['stamp']))
            <img class="hs-stamp" src="{{ $school['stamp'] }}" alt="">
        @endif
        <table class="hs-sign-table">
            <tr>
                <td class="hs-sign-cell">
                    <div class="hs-sign-top"></div>
                    <div class="hs-sign-line"></div>
                    <div class="hs-sign-role">Invigilator</div>
                    <div class="hs-sign-name">{{ $hall['invigilator_label'] }}</div>
                </td>
                <td class="hs-sign-cell">
                    <div class="hs-sign-top">
                        @if(!empty($school['principal_signature']))
                            <img class="hs-sign-img" src="{{ $school['principal_signature'] }}" alt="">
                        @endif
                    </div>
                    <div class="hs-sign-line"></div>
                    <div class="hs-sign-role">Principal</div>
                    <div class="hs-sign-name">{{ $school['principal_name'] ?? '' }}</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
