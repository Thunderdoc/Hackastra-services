{{--
    PDF surface of the Fee Collection Report (Loop 97) — a bare
    dompdf shell around the shared collection document partial.
    The controller stamps the running footer + page numbers on the
    canvas after render; @page margins reserve the room for them.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fee Collection Report — {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}</title>
    <style>
        @page{size:A4;margin:11mm 11mm 15mm;}
        body{font-family:'DejaVu Sans',sans-serif;margin:0;padding:0;}
    </style>
</head>
<body>
    @include('pages.support_team.payments.partials.collection_document')
</body>
</html>
