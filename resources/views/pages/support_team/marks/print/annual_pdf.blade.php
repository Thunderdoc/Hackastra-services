<html>
<head>
    <title>Annual Report Sheet - {{ $sr->user->name }}</title>
    {{-- Loop 85: the downloadable PDF twin of the printed annual
         document — the SAME annual_report_card partial, the SAME
         annualCardData assembly. Only the shell differs (dompdf page
         setup, an embeddable font stack, no print script). --}}
    <style>
        @page { size: a4; margin: 10mm; }
        html { background: #fff; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; }
    </style>
</head>
<body>
    @include('pages.support_team.marks.partials.annual_report_card')
</body>
</html>
