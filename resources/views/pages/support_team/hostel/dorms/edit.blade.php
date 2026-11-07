@extends('layouts.master')
@section('page_title', 'Edit Dorm - '.$dorm->name)
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Edit Dorm</h5>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('hostel.dorms.update', ['hostel_dorm' => $dorm->id]) }}">
                @csrf @method('put')
                <div class="form-group">
                    <label for="name">Name *</label>
                    <input id="name" name="name" value="{{ old('name', $dorm->name) }}" required type="text" class="form-control @error('name') is-invalid @enderror">@include('partials.errors.field', ['field' => 'name'])
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <input id="description" name="description" value="{{ old('description', $dorm->description) }}" type="text" class="form-control @error('description') is-invalid @enderror">@include('partials.errors.field', ['field' => 'description'])
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('hostel.dorms.index') }}" class="btn btn-light">Back</a>
            </form>
        </div>
    </div>
@endsection
