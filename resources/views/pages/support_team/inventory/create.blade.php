@extends('layouts.master')
@section('page_title', 'Add Inventory Item')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Add Inventory Item</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.store') }}">
                @csrf
                @include('pages.support_team.inventory._form')
                <button type="submit" class="btn btn-primary">Add Item</button>
                <a href="{{ route('inventory.index') }}" class="btn btn-light">Back</a>
            </form>
        </div>
    </div>
@endsection
