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
        /* B round 7: on phone screens the marks table (681px wide at
           360px) pushed the whole document sideways — a FIT-rule
           violation. Screen-only: the card itself becomes the inner
           scrollport (the accepted wide-table pattern); print
           geometry is untouched, and the dompdf blades (bulk_pdf)
           never include this blade, so no PDF surface moves. */
        @media screen {
            .report-card { overflow-x: auto; max-width: 100%; }
        }
    </style>
</head>
<body>
    @include('pages.support_team.marks.partials.report_card', ['rc_show_traits' => true])

    <script>
        window.print();
    </script>
</body>
</html>
