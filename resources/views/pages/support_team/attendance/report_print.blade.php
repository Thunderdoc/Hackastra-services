@extends('layouts.master')
@section('page_title', 'Monthly Attendance Report — Print Preview')
@section('content')

    {{-- Print preview of the monthly attendance document. The sheet
         below is the v2 document partial — the exact template the
         PDF download renders — so what is previewed here is what
         prints and what downloads. The toolbar is screen-only. --}}
    <style>
        .mar-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px;}
        .mar-toolbar .mar-toolbar-title{font-weight:700;margin-right:auto;}
        .mar-sheet{background:#fff;max-width:900px;margin:0 auto;padding:34px 38px;border:1px solid #d7e0ec;box-shadow:0 10px 34px rgba(16,38,59,.10);}
        @page{size:A4;margin:13mm 12mm 16mm;
            @bottom-left{content:"Monthly Attendance Report — {{ $selected_class->name }} · {{ $month->format('F Y') }}";font-size:9px;color:#718096;}
            @bottom-right{content:"Page " counter(page) " of " counter(pages);font-size:9px;color:#718096;}
        }
        @media print{
            .mar-sheet{max-width:none;padding:0;border:0;box-shadow:none;}
        }
    </style>

    <div class="mar-toolbar print-hide">
        <span class="mar-toolbar-title">Print Preview — Monthly Attendance Report</span>
        <a href="{{ route('attendance.report', ['class_id' => $selected_class->id, 'month' => $month->format('Y-m')]) }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Back to Report</a>
        <a href="{{ route('attendance.report.pdf', ['class_id' => $selected_class->id, 'month' => $month->format('Y-m')]) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download PDF</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
    </div>

    <div class="mar-sheet">
        @include('pages.support_team.attendance.partials.monthly_report_document')
    </div>

    @if($autoprint)
        <script>
            window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });
        </script>
    @endif

@endsection
