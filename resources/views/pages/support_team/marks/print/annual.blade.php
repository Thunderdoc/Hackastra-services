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
        /* B round 7: same screen-only inner-scroll fix as the term
           marksheet — the annual info table (463px at 360px) pushed
           the document sideways. Print geometry untouched; the
           dompdf annual_pdf blade never includes this blade. */
        @media screen {
            .rcd { overflow-x: auto; max-width: 100%; }
        }
    </style>
</head>
<body>
    {{-- rcd-doc: the print sheet carries the full A4 height and the
         card's signature block anchors to its foot (redesign
         2026-11-06). --}}
    <div class="rcd-doc">
        @include('pages.support_team.marks.partials.annual_report_card')
    </div>

    <script>
        window.print();
    </script>
</body>
</html>
