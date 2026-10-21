@extends('layouts.master')
@section('page_title', 'Payslip — ' . $payroll->monthName() . ' ' . $payroll->year)
@section('content')
    <style>
        @media print {
            .page-header, .pay-no-print { display: none !important; }
            .pay-slip { border: none !important; box-shadow: none !important; }
            body { background: #fff !important; }
        }
    </style>

    <div class="pay-no-print mb-3">
        <a href="{{ $back_url }}" class="btn btn-light"><i class="icon-arrow-left7 mr-1"></i> Back</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="icon-printer mr-1"></i> Print Payslip</button>
    </div>

    <div class="card pay-slip">
        <div class="card-body">
            <div class="pay-slip-head">
                <div>
                    <h4 class="pay-slip-school">{{ $school_name }}</h4>
                    @if($school_address)<div class="text-muted">{{ $school_address }}</div>@endif
                    <div class="text-muted">Session: {{ $current_session }}</div>
                </div>
                <div class="text-right">
                    <h5 class="pay-slip-title">PAYSLIP</h5>
                    <div>{{ $payroll->monthName() }} {{ $payroll->year }}</div>
                    @if($payroll->isPaid())
                        <span class="pay-stamp pay-stamp-paid">PAID</span>
                    @else
                        <span class="pay-stamp pay-stamp-pending">PENDING</span>
                    @endif
                </div>
            </div>

            <div class="pay-slip-staff">
                <div><span>Staff Name</span><strong>{{ $payroll->user->name }}</strong></div>
                <div><span>Staff Code</span><strong>{{ $payroll->user->code ?: '—' }}</strong></div>
                <div><span>Role</span><strong>{{ ucwords(str_replace('_', ' ', $payroll->user->user_type)) }}</strong></div>
                <div><span>Email</span><strong>{{ $payroll->user->email }}</strong></div>
            </div>

            <div class="table-responsive">
                <table class="table pay-slip-table">
                    <thead>
                    <tr><th style="width:60%">Earnings</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody>
                    <tr><td>Basic Salary</td><td class="text-right">{{ \App\Helpers\Money::inr($payroll->basic_salary) }}</td></tr>
                    <tr><td>Allowances</td><td class="text-right">{{ \App\Helpers\Money::inr($payroll->allowances) }}</td></tr>
                    <tr class="pay-slip-subtotal"><td>Total Earnings</td><td class="text-right">{{ \App\Helpers\Money::inr((float) $payroll->basic_salary + (float) $payroll->allowances) }}</td></tr>
                    </tbody>
                    <thead>
                    <tr><th>Deductions</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody>
                    <tr><td>Deductions</td><td class="text-right">{{ \App\Helpers\Money::inr($payroll->deductions) }}</td></tr>
                    <tr class="pay-slip-subtotal"><td>Total Deductions</td><td class="text-right">{{ \App\Helpers\Money::inr($payroll->deductions) }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="pay-slip-net">
                <div>
                    <span>Net Pay</span>
                    <strong>{{ \App\Helpers\Money::inr($payroll->net_pay) }}</strong>
                </div>
                <div class="pay-slip-words">Rupees {{ $net_words }} Only</div>
            </div>

            @if($payroll->remarks)
                <p class="text-muted mt-2 mb-0"><strong>Remarks:</strong> {{ $payroll->remarks }}</p>
            @endif

            <div class="pay-slip-foot">
                <span>
                    @if($payroll->isPaid() && $payroll->paid_at)
                        Paid on {{ $payroll->paid_at->format('d M Y') }}
                    @else
                        Payment pending
                    @endif
                    @if($payroll->creator) · Recorded by {{ $payroll->creator->name }} @endif
                </span>
                <span>This is a system-generated payslip.</span>
            </div>
        </div>
    </div>
@endsection
