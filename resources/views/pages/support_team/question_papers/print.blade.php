<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Question Paper — {{ $doc['paper']->title }}</title>
    {{-- Browser-print host for the question paper document — the
         same paper_document partial as the PDF host. --}}
    <style>
        @page { margin: 14mm; }
        body { margin: 12px; }
        @media print { .qp-no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="qp-no-print"><button type="button" onclick="window.print()">Print</button></p>
    @include('pages.support_team.question_papers.partials.paper_document')
</body>
</html>
