@extends('layouts.master')
@section('page_title', 'Payslip — ' . $payroll->monthName() . ' ' . $payroll->year)
@section('content')
    {{-- Payslip screen (premium redesign, 2026-10-22): the document
         itself lives in partials/payslip_document — the SAME partial
         the dompdf shell (payslip_pdf) renders — so what the staff
         member sees here, prints from here, and downloads as PDF is
         one document. This page only adds the preview chrome: a
         centred A4-ratio paper on a neutral backdrop (Loop 90),
         with Back / Print outside the paper. --}}
    <div class="pay-no-print mb-3">
        <a href="{{ $back_url }}" class="btn btn-light"><i class="icon-arrow-left7 mr-1"></i> Back</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="icon-printer mr-1"></i> Print Payslip</button>
    </div>

    <div class="pslip-screen">
        <div class="pslip-paper">
            @include('pages.support_team.payroll.partials.payslip_document')
        </div>
    </div>
@endsection
