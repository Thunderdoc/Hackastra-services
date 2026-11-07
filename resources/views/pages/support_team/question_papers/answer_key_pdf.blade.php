<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Answer Key — {{ $doc['paper']->title }}</title>
    {{-- dompdf host for the answer key — a separate document from
         the paper PDF, always. --}}
    <style>
        @page { margin: 14mm; }
        body { margin: 0; }
    </style>
</head>
<body>
    @include('pages.support_team.question_papers.partials.answer_key_document')
</body>
</html>
