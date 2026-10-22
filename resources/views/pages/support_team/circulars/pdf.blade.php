<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Circular — {{ $circular->title }}</title>
    {{-- Loop 75: dompdf wrapper for the single-circular document —
         the same _document partial as the browser print view. --}}
    <style>
        @page { margin: 14mm; }
        body { margin: 0; }
    </style>
    @include('pages.support_team.circulars._document_styles')
</head>
<body>
    @include('pages.support_team.circulars._document')
</body>
</html>
