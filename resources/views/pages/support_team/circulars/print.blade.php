<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Circular — {{ $circular->title }}</title>
    {{-- Loop 75: standalone print document for one circular (the
         v2 document design: settings letterhead, meta table,
         escaped message, footer). Same _document partial as the
         PDF download, so both always agree. --}}
    <style>
        @page { size: auto; margin: 12mm; }
        html { background: #fff; }
        body { margin: 0; }
    </style>
    @include('pages.support_team.circulars._document_styles')
</head>
<body>
    @include('pages.support_team.circulars._document')

    <script>
        window.print();
    </script>
</body>
</html>
