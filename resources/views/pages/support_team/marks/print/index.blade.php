<html>
<head>
    <title>Student Marksheet - {{ $sr->user->name }}</title>
    {{-- Batch M3: the printed document is ONLY the official report
         card (marks/partials/report_card — the same template the
         on-screen marksheet renders). The legacy my_print.css link
         is gone; print sizing lives here and in the card itself. --}}
    <style>
        @page { size: auto; margin: 10mm; }
        html { background: #fff; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; }
    </style>
</head>
<body>
    @include('pages.support_team.marks.partials.report_card', ['rc_show_traits' => true])

    <script>
        window.print();
    </script>
</body>
</html>
