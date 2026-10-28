@extends('layouts.master')
@section('page_title', 'Fee Structure Builder')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Fee Heads</h5>
            <div>
                @if(Qs::userIsTeamSAWrite())
                    <a href="{{ route('fee_structure.create') }}" class="btn btn-primary">New Fee Head</a>
                    <a href="{{ route('fee_structure.concessions.create') }}" class="btn btn-secondary">New Concession</a>
                @endif
                <a href="{{ route('fee_structure.net.index') }}" class="btn btn-secondary">Per-Student Net View</a>
            </div>
        </div>
        <div class="card-body">
            @if($heads->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Title</th>
                                <th>Amount</th>
                                <th>Session</th>
                                <th>Installments</th>
                                <th>Balance</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($heads as $head)
                                <tr>
                                    <td>{{ optional($head->my_class)->name }}</td>
                                    <td>{{ $head->title }}</td>
                                    <td>{{ number_format($head->amount) }}</td>
                                    <td>{{ $head->year }}</td>
                                    <td>{{ $head->installments->count() }} ({{ number_format($head->installments->sum('amount')) }})</td>
                                    <td>{{ $head->installments->sum('amount') == $head->amount ? 'Balanced' : 'Unbalanced' }}</td>
                                    <td>
                                        <a href="{{ route('fee_structure.show', ['fee_head' => $head->id]) }}" class="btn btn-sm btn-info">View</a>
                                        @if(Qs::userIsTeamSAWrite())
                                            <a href="{{ route('fee_structure.edit', ['fee_head' => $head->id]) }}" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="POST" action="{{ route('fee_structure.apply', ['fee_head' => $head->id]) }}" style="display:inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-success">Apply as Payment</button>
                                            </form>
                                            <form method="POST" action="{{ route('fee_structure.destroy', ['fee_head' => $head->id]) }}" style="display:inline" onsubmit="return confirm('Delete this fee head? Its installments and concessions will also be deleted.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No fee heads defined yet. Create one to start building a fee structure.</p>
            @endif
        </div>
    </div>
@endsection
