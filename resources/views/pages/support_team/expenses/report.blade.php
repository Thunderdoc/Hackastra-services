@extends('layouts.master')
@section('page_title', 'Income-Expenditure Report')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Income-Expenditure Report</h5>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <a href="{{ route('expenses.index') }}" class="btn btn-light btn-sm">Back to Expenses</a>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                Income is fee money actually received (paid fee records only). Expenses are school outlays recorded here.
                Staff salaries are not counted on either side — those live under Payroll. Net = income minus expenses.
            </p>

            <form method="GET" action="{{ route('expenses.report') }}" class="mb-3">
                <div class="row">
                    <div class="col-md-4">
                        <select name="year" class="form-control" onchange="this.form.submit()">
                            <option value="">All years</option>
                            @foreach($years as $reportYear)
                                <option value="{{ $reportYear }}" {{ $year === $reportYear ? 'selected' : '' }}>{{ $reportYear }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Month</th>
                        <th>Income</th>
                        <th>Expenses</th>
                        <th>Net</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ \Carbon\Carbon::createFromFormat('Y-m', $row['month'])->format('F Y') }}</td>
                            <td>{{ \App\Helpers\Money::inr($row['income']) }}</td>
                            <td>{{ \App\Helpers\Money::inr($row['expenses']) }}</td>
                            <td>{{ \App\Helpers\Money::inr($row['net']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No income or expense rows for this period.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot>
                    <tr>
                        <th>Overall</th>
                        <th>{{ \App\Helpers\Money::inr($totals['income']) }}</th>
                        <th>{{ \App\Helpers\Money::inr($totals['expenses']) }}</th>
                        <th>{{ \App\Helpers\Money::inr($totals['net']) }}</th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
@endsection
