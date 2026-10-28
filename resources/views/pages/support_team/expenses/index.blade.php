@extends('layouts.master')
@section('page_title', 'Expenses')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Expenses</h5>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <a href="{{ route('expenses.report') }}" class="btn btn-light btn-sm mr-2">Income-Expenditure Report</a>
                @if(Qs::userIsTeamAccountWrite())
                <a href="{{ route('expenses.create') }}" class="btn btn-primary btn-sm">Record Expense</a>
                @endif
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                School outlays recorded by the accounts office. Staff salaries are not listed here — those live under Payroll.
                Total shown: {{ \App\Helpers\Money::inr($total) }}
            </p>

            <form method="GET" action="{{ route('expenses.index') }}" class="mb-3">
                <div class="row">
                    <div class="col-md-4">
                        <select name="category" class="form-control" onchange="this.form.submit()">
                            <option value="">All categories</option>
                            @foreach($categories as $category)
                                <option value="{{ $category }}" {{ $selected_category === $category ? 'selected' : '' }}>{{ $category }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Recorded By</th>
                        <th>Note</th>
                        @if(Qs::userIsTeamAccountWrite())
                        <th>Actions</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($expenses as $expense)
                        <tr>
                            <td>{{ $expense->title }}</td>
                            <td>{{ $expense->category }}</td>
                            <td>{{ $expense->expense_date->format('d M Y') }}</td>
                            <td>{{ \App\Helpers\Money::inr($expense->amount) }}</td>
                            <td>{{ optional($expense->recorder)->name ?: '-' }}</td>
                            <td>{{ $expense->note ?: '-' }}</td>
                            @if(Qs::userIsTeamAccountWrite())
                            <td>
                                <a href="{{ route('expenses.edit', $expense) }}" class="btn btn-light btn-sm">Edit</a>
                                <form method="POST" action="{{ route('expenses.destroy', $expense) }}" style="display:inline;" onsubmit="return confirm('Delete this expense?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-light btn-sm">Delete</button>
                                </form>
                            </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ Qs::userIsTeamAccountWrite() ? 7 : 6 }}">No expenses recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="3">Total</th>
                        <th>{{ \App\Helpers\Money::inr($total) }}</th>
                        <th colspan="{{ Qs::userIsTeamAccountWrite() ? 3 : 2 }}"></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
@endsection
