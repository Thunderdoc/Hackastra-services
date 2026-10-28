@extends('layouts.master')
@section('page_title', 'Inventory')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Inventory / Store</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif
            <p class="text-muted">Stock is computed from the movement log: stock-in minus stock-out. Items at or below their threshold carry a Low Stock badge.</p>
            <form method="GET" action="{{ route('inventory.index') }}" class="form-inline mb-3">
                <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm mr-2 mb-2" placeholder="Search name or unit">
                <select name="category" class="form-control form-control-sm mr-2 mb-2">
                    <option value="all" {{ $category === 'all' ? 'selected' : '' }}>All categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}" {{ $category === $cat ? 'selected' : '' }}>{{ ucfirst($cat) }}</option>
                    @endforeach
                </select>
                <label class="mr-2 mb-2"><input type="checkbox" name="low_stock" value="1" {{ $low_stock ? 'checked' : '' }}> Low-stock only ({{ $low_stock_count }})</label>
                <button type="submit" class="btn btn-sm btn-primary mb-2">Filter</button>
                @if(Qs::userIsTeamSAWrite())
                    <a href="{{ route('inventory.create') }}" class="btn btn-success btn-sm ml-auto mb-2">Add Item</a>
                @endif
            </form>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Name</th><th>SKU</th><th>Category</th><th>Unit</th><th>Stock</th><th>Threshold</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td><strong>{{ $item->name }}</strong></td>
                            <td>{{ $item->sku ?: '—' }}</td>
                            <td>{{ ucfirst($item->category) }}</td>
                            <td>{{ $item->unit }}</td>
                            <td>{{ $item->computed_stock }}</td>
                            <td>{{ $item->low_stock_threshold }}</td>
                            <td>
                                @if($item->low_stock)
                                    <span class="badge badge-danger">Low Stock</span>
                                @else
                                    <span class="badge badge-success">In Stock</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('inventory.show', ['inventory_item' => $item->id]) }}" class="btn btn-light btn-sm">View</a>
                                @if(Qs::userIsTeamSAWrite())
                                    <a href="{{ route('inventory.edit', ['inventory_item' => $item->id]) }}" class="btn btn-light btn-sm">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No inventory items yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $items->appends(['q' => $q, 'category' => $category, 'low_stock' => $low_stock ? '1' : null])->links() }}
        </div>
    </div>
@endsection
