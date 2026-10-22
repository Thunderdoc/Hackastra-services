<html>
<head>
    <title>Payslip - {{ $payroll->user->name }} - {{ $payroll->monthName() }} {{ $payroll->year }}</title>
    {{-- The downloadable PDF twin of the printed payslip — the SAME
         payslip_document partial, the SAME renderPayslip data. Only
         the shell differs (dompdf page setup, embeddable font stack).

         ONE-PAGE CONTRACT: A4 portrait, 9mm margins. The document
         is table-only with compact rows and occupies roughly half
         the printable height for any real record (the payroll model
         stores totals only — three component rows at most), so it
         always fits exactly one page without clipping anything. --}}
    <style>
        @page { size: a4 portrait; margin: 9mm; }
        html { background: #fff; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; }
    </style>
</head>
<body>
    @include('pages.support_team.payroll.partials.payslip_document')
</body>
</html>
