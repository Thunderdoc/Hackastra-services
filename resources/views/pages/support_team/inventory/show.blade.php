@extends('layouts.master')
@section('page_title', 'Inventory Item')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">{{ $item->name }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif
            <p>
                Category: <strong>{{ ucfirst($item->category) }}</strong> &nbsp;|&nbsp;
                Unit: <strong>{{ $item->unit }}</strong> &nbsp;|&nbsp;
                Current stock: <strong>{{ $item->computed_stock }} {{ $item->unit }}</strong> &nbsp;|&nbsp;
                Threshold: <strong>{{ $item->low_stock_threshold }}</strong> &nbsp;|&nbsp;
                @if($item->low_stock)
                    <span class="badge badge-danger">Low Stock</span>
                @else
                    <span class="badge badge-success">In Stock</span>
                @endif
            </p>
            @if(Qs::userIsTeamSAWrite())
                <form method="POST" action="{{ route('inventory.movements.store', ['inventory_item' => $item->id]) }}" class="form-inline mb-3">
                    @csrf
                    <select name="type" class="form-control form-control-sm mr-2 mb-2 @error('type') is-invalid @enderror" required>
                        <option value="in">Stock In</option>
                        <option value="out">Stock Out</option>
                    </select>@include('partials.errors.field', ['field' => 'type'])
                    <input type="number" name="quantity" class="form-control form-control-sm mr-2 mb-2 @error('quantity') is-invalid @enderror" min="1" placeholder="Quantity" required>@include('partials.errors.field', ['field' => 'quantity'])
                    <input type="date" name="movement_date" class="form-control form-control-sm mr-2 mb-2 @error('movement_date') is-invalid @enderror">@include('partials.errors.field', ['field' => 'movement_date'])
                    <input type="text" name="note" class="form-control form-control-sm mr-2 mb-2 @error('note') is-invalid @enderror" placeholder="Note (optional)">@include('partials.errors.field', ['field' => 'note'])
                    <button type="submit" class="btn btn-sm btn-primary mb-2">Record Movement</button>
                </form>
            @endif
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Note</th><th>Recorded By</th></tr></thead>
                    <tbody>
                    @forelse($movements as $movement)
                        <tr>
                            <td>{{ $movement->movement_date ? $movement->movement_date->format('d M Y') : '—' }}</td>
                            <td>{{ $movement->type === 'in' ? 'Stock In' : 'Stock Out' }}</td>
                            <td>{{ $movement->type === 'in' ? '+' : '−' }}{{ $movement->quantity }}</td>
                            <td>{{ $movement->note ?? '—' }}</td>
                            <td>{{ $movement->recorder ? $movement->recorder->name : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">@include('partials.patterns.empty_state', ['icon' => 'icon-box', 'message' => 'No stock movements yet.'])</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $movements->links() }}
            <a href="{{ route('inventory.index') }}" class="btn btn-light btn-sm mt-2">Back to Inventory</a>
        </div>
    </div>
@endsection
