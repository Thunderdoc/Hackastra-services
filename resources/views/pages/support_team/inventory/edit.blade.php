@extends('layouts.master')
@section('page_title', 'Edit Inventory Item')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Edit Inventory Item</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.update', ['inventory_item' => $item->id]) }}">
                @csrf
                @method('PUT')
                @include('pages.support_team.inventory._form')
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('inventory.index') }}" class="btn btn-light">Back</a>
            </form>
            <form method="POST" action="{{ route('inventory.destroy', ['inventory_item' => $item->id]) }}" class="mt-3" onsubmit="return confirm('Delete this item? Items with stock movements cannot be deleted.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger btn-sm">Delete Item</button>
            </form>
        </div>
    </div>
@endsection
