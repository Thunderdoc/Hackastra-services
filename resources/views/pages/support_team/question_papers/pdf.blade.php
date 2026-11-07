<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Question Paper — {{ $doc['paper']->title }}</title>
    {{-- dompdf host for the question paper document — the same
         paper_document partial as the screen/print hosts. --}}
    <style>
        @page { margin: 14mm; }
        body { margin: 0; }
    </style>
</head>
<body>
    @include('pages.support_team.question_papers.partials.paper_document')
</body>
</html>
