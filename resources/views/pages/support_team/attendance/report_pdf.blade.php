{{--
    PDF surface of the monthly attendance report — a bare document
    shell around the shared v2 document partial (the print preview
    renders the identical partial), drawn by dompdf. Page numbers
    and the running footer are stamped by the controller's canvas
    page script; @page margins reserve the room for them.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Report — {{ $class_label }} — {{ $range_label }}</title>
    <style>
        @page{size:A4;margin:10mm 11mm 14mm;}
        body{font-family:'DejaVu Sans',sans-serif;margin:0;padding:0;}
    </style>
</head>
<body>
    @include('pages.support_team.attendance.partials.monthly_report_document', ['for_pdf' => true])
</body>
</html>
