<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslips {{ $year }}</title>
    @include('pages.support_team.payroll.partials.payslip_pdf_styles')
</head>
<body>
    @foreach($records as $rec)
        <div class="{{ $loop->last ? 'psdoc-page-last' : 'psdoc-page' }}">
            @include('pages.support_team.payroll.partials.payslip_document', [
                'payroll' => $rec,
                'net_words' => \App\Support\NumberToWords::convert((int) round((float) $rec->net_pay)),
            ])
        </div>
    @endforeach
</body>
</html>
