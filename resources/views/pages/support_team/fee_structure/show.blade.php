@extends('layouts.master')
@section('page_title', 'Fee Head Detail')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder &nbsp;/&nbsp; {{ $head->title }}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">{{ $head->title }} — {{ optional($head->my_class)->name }} ({{ $head->year }})</h5>
            <div>
                @if(Qs::userIsTeamSAWrite())
                    <a href="{{ route('fee_structure.edit', ['fee_head' => $head->id]) }}" class="btn btn-primary">Edit</a>
                    <form method="POST" action="{{ route('fee_structure.apply', ['fee_head' => $head->id]) }}" style="display:inline">
                        @csrf
                        <button type="submit" class="btn btn-success">Apply as Payment</button>
                    </form>
                @endif
                <a href="{{ route('fee_structure.index') }}" class="btn btn-secondary">Back to Fee Heads</a>
            </div>
        </div>
        <div class="card-body">
            <p>Head amount: <strong>{{ number_format($head->amount) }}</strong></p>
            <p>Installments total: <strong>{{ number_format($installments_total) }}</strong> — {{ $balanced ? 'Balanced' : 'Unbalanced: installments do not sum to the head amount.' }}</p>
            <p>Applied as Payment: <strong>{{ $applied ? 'Yes' : 'No' }}</strong></p>

            <h6>Installments</h6>
            @if($head->installments->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>No.</th><th>Due Date</th><th>Amount</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            @foreach($head->installments as $inst)
                                <tr>
                                    <td>{{ $inst->installment_no }}</td>
                                    <td>{{ optional($inst->due_date)->format('d M Y') }}</td>
                                    <td>{{ number_format($inst->amount) }}</td>
                                    <td>
                                        @if(Qs::userIsTeamSAWrite())
                                            <form method="POST" action="{{ route('fee_structure.installments.destroy', ['fee_head' => $head->id, 'installment' => $inst->id]) }}" style="display:inline" onsubmit="return confirm('Delete this installment?')">
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
                <p>No installments yet.</p>
            @endif

            @if(Qs::userIsTeamSAWrite())
                <h6>Add Installment</h6>
                <p>Adding an installment must not push the installment total over the head amount.</p>
                <form method="POST" action="{{ route('fee_structure.installments.store', ['fee_head' => $head->id]) }}">
                    @csrf
                    <div class="form-group">
                        <label>No.</label>
                        <input type="number" name="installment_no" class="form-control" required min="1" step="1">
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Amount</label>
                        <input type="number" name="amount" class="form-control" required min="0" step="1">
                    </div>
                    <button type="submit" class="btn btn-primary">Add Installment</button>
                </form>
            @endif

            <h6 class="mt-3">Concessions on this head</h6>
            @if($head->concessions->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Student</th><th>Type</th><th>Value</th><th>Reason</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            @foreach($head->concessions as $c)
                                <tr>
                                    <td>{{ optional(optional($c->student_record)->user)->name }}</td>
                                    <td>{{ $c->type }}</td>
                                    <td>{{ $c->type === 'percent' ? $c->value . '%' : number_format($c->value) }}</td>
                                    <td>{{ $c->reason }}</td>
                                    <td>
                                        @if(Qs::userIsTeamSAWrite())
                                            <a href="{{ route('fee_structure.concessions.edit', ['concession' => $c->id]) }}" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="POST" action="{{ route('fee_structure.concessions.destroy', ['concession' => $c->id]) }}" style="display:inline" onsubmit="return confirm('Delete this concession?')">
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
                <p>No concessions on this head.</p>
            @endif
            @if(Qs::userIsTeamSAWrite())
                <a href="{{ route('fee_structure.concessions.create', ['fee_head' => $head->id]) }}" class="btn btn-secondary">New Concession for this head</a>
            @endif
        </div>
    </div>
@endsection
