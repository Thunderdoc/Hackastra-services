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
                    <table class="table hk-std hk-std-880">
                        {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                        <colgroup><col style="width:13%"><col style="width:22%"><col style="width:11%"><col style="width:10%"><col style="width:16%"><col style="width:11%"><col style="width:17%"></colgroup>
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
                                    <td class="hk-ellip">{{ $head->title }}</td>
                                    <td class="hk-nw">{{ number_format($head->amount) }}</td>
                                    <td class="hk-nw">{{ $head->year }}</td>
                                    <td class="hk-nw">{{ $head->installments->count() }} ({{ number_format($head->installments->sum('amount')) }})</td>
                                    <td>{{ $head->installments->sum('amount') == $head->amount ? 'Balanced' : 'Unbalanced' }}</td>
                                    <td>
                                        <a href="{{ route('fee_structure.show', ['fee_head' => $head->id]) }}" class="btn btn-sm btn-info">View</a>
                                        @if(Qs::userIsTeamSAWrite())
                                            <a href="{{ route('fee_structure.edit', ['fee_head' => $head->id]) }}" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="POST" action="{{ route('fee_structure.apply', ['fee_head' => $head->id]) }}" style="display:inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-success">Apply as Payment</button>
                                            </form>
                                            <form method="POST" action="{{ route('fee_structure.destroy', ['fee_head' => $head->id]) }}" style="display:inline" data-confirm="Delete the fee head {{ $head->title }}? Its installments and concessions will also be deleted." data-confirm-title="Delete Fee Head" data-confirm-action="Delete" data-confirm-danger>
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
                @include('partials.patterns.empty_state', ['icon' => 'icon-cash2', 'message' => 'No fee heads defined yet. Create one to start building a fee structure.'])
            @endif
        </div>
    </div>
@endsection
