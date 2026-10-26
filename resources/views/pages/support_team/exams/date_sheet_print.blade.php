<html>
<head>
    <title>Exam Date Sheet - {{ $exam->name }} - {{ $my_class->name }}</title>
    {{-- Exam Date Sheet document (owner-ordered module, 2026-10-22):
         fed by the SAME App\Support\ExamDateSheet assembly as the
         on-screen sheet, so screen, print and PDF can never disagree
         on a date. Shell follows the v2 document design (navy
         #10263b, hairlines, shaded heads) used by the timetable and
         report-card prints. --}}
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

        .ds-head { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #10263b; }
        .ds-head td { vertical-align: middle; }
        .ds-brand-cell { width: 110px; text-align: left; }
        .ds-main-cell { text-align: center; }
        .ds-tile { display: block; width: 64px; height: 64px; background: #10263b; color: #fff; font-size: 20px; font-weight: 800; text-align: center; line-height: 64px; border-radius: 8px; }
        .ds-logo { max-height: 90px; max-width: 150px; }
        .ds-school { color: #10263b; font-size: 25px; font-weight: bold; text-transform: uppercase; letter-spacing: .2px; }
        .ds-addr { color: #000; font-size: 15px; font-style: italic; }
        .ds-contact { color: #000; font-size: 12.5px; font-style: italic; }

        .ds-titleband { background: #10263b; color: #fff; text-align: center; padding: 7px 8px; margin: 12px 0 4px; font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
        .ds-meta { text-align: center; color: #46586c; font-size: 13px; margin: 8px 0 2px; }
        .ds-meta strong { color: #10263b; }

        table.ds { width: 100%; border-collapse: collapse; border: 1.5px solid #10263b; margin: 12px auto; }
        table.ds th, table.ds td { border: 1px solid #7d8fa6; padding: 9px 10px; font-size: 13.5px; text-align: left; }
        table.ds thead th { background: #eef2f7; color: #10263b; text-transform: uppercase; letter-spacing: .4px; font-size: 12px; }
        .ds-date { font-weight: bold; color: #10263b; white-space: nowrap; }
        .ds-na { color: #8a94a6; font-style: italic; }
        .ds-empty-note { text-align: center; color: #46586c; font-size: 13px; margin: 14px 0 2px; }

        /* Signatures + seal: same digital-docs contract as the report
           card — the stamp and the principal's signature render only
           while uploaded in Settings; otherwise blank lines print. */
        .ds-signatures { position: relative; margin-top: 34px; }
        .ds-stamp { position: absolute; right: 40px; bottom: 0; width: 110px; height: 110px; object-fit: contain; transform: rotate(-10deg); opacity: .95; z-index: 2; }
        .ds-sign-table { width: 100%; border-collapse: collapse; }
        /* Batch fix 2026-10-25: the cells were bottom-aligned, so
           the principal's signature image (44px) lifted the
           principal's line above the teacher's. Top alignment +
           a fixed 44px top area on both sides keeps both lines on
           the same height whether a signature is uploaded or not. */
        .ds-sign-cell { width: 50%; padding: 0 30px; text-align: center; vertical-align: top; }
        .ds-sign-top { height: 44px; display: flex; align-items: flex-end; justify-content: center; }
        .ds-sign-img { display: block; max-height: 44px; max-width: 180px; object-fit: contain; }
        .ds-sign-line { border-bottom: 1.5px solid #10263b; height: 40px; }
        .ds-sign-role { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: #46586c; margin-top: 6px; }
        .ds-sign-name { font-size: 13px; font-weight: bold; color: #10263b; }

        .ds-genline { text-align: center; color: #718096; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; margin-top: 16px; border-top: 1px solid #b9c6d4; padding-top: 8px; }
    </style>
</head>
<body>
<div class="container">
    <div id="print">
        <table class="ds-head">
            <tr>
                <td class="ds-brand-cell">
                    @if(!empty($school['logo']))
                        <img class="ds-logo" src="{{ $school['logo'] }}" alt="">
                    @else
                        @php
                            $dsInitials = '';
                            foreach (preg_split('/\s+/', trim((string) $school['name'])) as $dsWord) {
                                if ($dsWord !== '') {
                                    $dsInitials .= strtoupper(substr($dsWord, 0, 1));
                                    if (strlen($dsInitials) >= 2) { break; }
                                }
                            }
                            if ($dsInitials === '') { $dsInitials = 'S'; }
                        @endphp
                        <span class="ds-tile" aria-hidden="true">{{ $dsInitials }}</span>
                    @endif
                </td>
                <td class="ds-main-cell">
                    <span class="ds-school">{{ strtoupper((string) $school['name']) }}</span><br/>
                    <span class="ds-addr">{{ ucwords((string) $school['address']) }}</span><br/>
                    <span class="ds-contact">{{ !empty($school['phone']) ? 'Tel: '.$school['phone'] : '' }}{{ (!empty($school['phone']) && !empty($school['email'])) ? ' | ' : '' }}{{ !empty($school['email']) ? 'Email: '.$school['email'] : '' }}</span>
                </td>
                <td class="ds-brand-cell"></td>
            </tr>
        </table>

        <div class="ds-titleband">Exam Date Sheet</div>
        <p class="ds-meta">
            Exam: <strong>{{ $exam->name }}</strong> &middot;
            Term: <strong>{{ $exam->term }}</strong> &middot;
            Session: <strong>{{ $exam->year }}</strong> &middot;
            Class: <strong>{{ $my_class->name }}</strong>
            @if($section)
                &middot; Section: <strong>{{ $section->name }}@if($section->stream) ({{ strtoupper($section->stream) }})@endif</strong>
            @endif
            @if($child)
                <br/>{{ $viewer === 'parent' ? 'Child' : 'Student' }}: <strong>{{ $child->user->name }}</strong>
                @if($child->adm_no) &middot; Admission No: <strong>{{ $child->adm_no }}</strong> @endif
            @endif
        </p>

        @if($announced === 0)
            <p class="ds-empty-note">Dates for this exam have not been announced yet. The subjects below will carry their dates here as soon as the school office publishes them.</p>
        @endif

        <table class="ds">
            <thead>
            <tr>
                <th style="width: 30%;">Date</th>
                <th style="width: 15%;">Time</th>
                <th style="width: 30%;">Subject</th>
                <th>Note</th>
            </tr>
            </thead>
            <tbody>
            @foreach($rows as $r)
                <tr>
                    @if($r['entry'])
                        <td class="ds-date">{{ $r['date_label'] }}</td>
                        <td>{{ $r['time_label'] ?: '—' }}</td>
                        <td>{{ $r['subject']->name }}</td>
                        <td>{{ $r['entry']->note ?: '—' }}</td>
                    @else
                        <td class="ds-na">Not announced</td>
                        <td>—</td>
                        <td>{{ $r['subject']->name }}</td>
                        <td>—</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="ds-signatures">
            @if(!empty($school['stamp']))
                <img class="ds-stamp" src="{{ $school['stamp'] }}" alt="School stamp">
            @endif
            <table class="ds-sign-table">
                <tr>
                    <td class="ds-sign-cell">
                        <div class="ds-sign-top"></div>
                        <div class="ds-sign-line"></div>
                        <div class="ds-sign-role">Class Teacher</div>
                        <div class="ds-sign-name">{{ $class_teacher_name ?? '' }}</div>
                    </td>
                    <td class="ds-sign-cell">
                        <div class="ds-sign-top">
                            @if(!empty($school['principal_signature']))
                                <img class="ds-sign-img" src="{{ $school['principal_signature'] }}" alt="Principal's signature">
                            @endif
                        </div>
                        <div class="ds-sign-line"></div>
                        <div class="ds-sign-role">Principal</div>
                        <div class="ds-sign-name">{{ $school['principal_name'] ?: '' }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="ds-genline">Generated by Hackstra &middot; {{ $school['session'] }}</div>
    </div>
</div>

@if(!$for_pdf)
    <script>
        window.print();
    </script>
@endif
</body>
</html>
