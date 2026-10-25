<html>
<head>
    <title>Section Marksheets</title>
    {{-- Wave 5: one printed report card per student of a section,
         same marks/partials/report_card template as the single
         marksheet print — the bundle and the single can never
         disagree. Images arrive already inlined as data URIs. --}}
    <style>
        @page { size: auto; margin: 10mm; }
        html { background: #fff; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; }
        .mk-page { page-break-after: always; }
        .mk-page-last { page-break-after: auto; }
    </style>
</head>
<body>
    @foreach($cards as $card)
        <div class="{{ $loop->last ? 'mk-page-last' : 'mk-page' }}">
            @include('pages.support_team.marks.partials.report_card', array_merge($card, ['rc_show_traits' => true]))
        </div>
    @endforeach
</body>
</html>
