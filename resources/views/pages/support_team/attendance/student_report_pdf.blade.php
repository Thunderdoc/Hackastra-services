{{-- PDF surface of the student attendance report — bare shell
     around the shared document partial, drawn by dompdf. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Student Attendance Report — {{ optional($student->user)->name }} — {{ $range_label }}</title>
    <style>
        @page{size:A4;margin:10mm 11mm 14mm;}
        body{font-family:'DejaVu Sans',sans-serif;margin:0;padding:0;}
    </style>
</head>
<body>
    @include('pages.support_team.attendance.partials.student_report_document', ['for_pdf' => true])
</body>
</html>
