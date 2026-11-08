@extends('layouts.master')
@section('page_title', 'Income-Expenditure Report')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Finance &nbsp;/&nbsp; <a href="{{ route('expenses.index') }}">Expenses</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
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
                <table class="table table-striped hk-std hk-std-760">
                    <colgroup><col style="width:34%"><col style="width:22%"><col style="width:22%"><col style="width:22%"></colgroup>
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
                            {{-- Day anchored to 01: createFromFormat('Y-m', …) fills the
                                 missing day from TODAY, so on the 31st a 30-day month
                                 overflowed into the next month's name (Apr → "May"). --}}
                            <td class="hk-nw">{{ \Carbon\Carbon::createFromFormat('Y-m-d', $row['month'] . '-01')->format('F Y') }}</td>
                            <td class="hk-nw">{{ \App\Helpers\Money::inr($row['income']) }}</td>
                            <td class="hk-nw">{{ \App\Helpers\Money::inr($row['expenses']) }}</td>
                            <td class="hk-nw">{{ \App\Helpers\Money::inr($row['net']) }}</td>
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
