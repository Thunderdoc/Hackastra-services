<html>
<head>
    <title>Annual Report Sheet - {{ $sr->user->name }}</title>
    {{-- Batch M4: the printed annual document is ONLY the official
         annual report card (marks/partials/annual_report_card — the
         same template the on-screen annual marksheet renders). --}}
    <style>
        @page { size: auto; margin: 10mm; }
        html { background: #fff; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; }
    </style>
</head>
<body>
    @include('pages.support_team.marks.partials.annual_report_card')

    <script>
        window.print();
    </script>
</body>
</html>
