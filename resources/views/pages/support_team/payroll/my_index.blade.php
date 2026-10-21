@extends('layouts.master')
@section('page_title', 'My Payslips')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">My Payslips</h6>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <span class="text-muted">Total received so far: <strong>{{ \App\Helpers\Money::inr($total_received) }}</strong></span>
            </div>
        </div>
        <div class="card-body">
            @if($records->isEmpty())
                <div class="alert alert-info border-0 mb-0">
                    No salary records have been generated for you yet. Payslips will appear here once the accounts office generates your payroll.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-right">Basic</th>
                            <th class="text-right">Allowances</th>
                            <th class="text-right">Deductions</th>
                            <th class="text-right">Net Pay</th>
                            <th>Status</th>
                            <th>Paid On</th>
                            <th class="text-right">Payslip</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($records as $rec)
                            <tr>
                                <td><strong>{{ $rec->monthName() }} {{ $rec->year }}</strong></td>
                                <td class="text-right">{{ \App\Helpers\Money::inr($rec->basic_salary) }}</td>
                                <td class="text-right">{{ \App\Helpers\Money::inr($rec->allowances) }}</td>
                                <td class="text-right">{{ \App\Helpers\Money::inr($rec->deductions) }}</td>
                                <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($rec->net_pay) }}</td>
                                <td>
                                    @if($rec->isPaid())
                                        <span class="badge pay-badge-paid">Paid</span>
                                    @else
                                        <span class="badge pay-badge-pending">Pending</span>
                                    @endif
                                </td>
                                <td>{{ $rec->paid_at ? $rec->paid_at->format('d M Y') : '—' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('my_payslips.payslip', $rec->id) }}" class="btn btn-light btn-sm"><i class="icon-file-text2 mr-1"></i> View</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
