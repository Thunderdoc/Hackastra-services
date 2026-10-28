@extends('layouts.master')
@section('page_title', 'New Grant')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">New Permission Grant</h5>
            <p class="text-muted mb-0">Admin only. Only the three whitelisted Wave 3 actions can be granted — grant management itself can never be granted.</p>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif
            <form method="POST" action="{{ route('role_permissions.store') }}">
                @csrf
                <div class="form-group">
                    <label for="grant_user">User <span class="text-danger">*</span></label>
                    <select name="user_id" id="grant_user" class="form-control" required>
                        <option value="">Select a user</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ (string) old('user_id') === (string) $user->id ? 'selected' : '' }}>{{ $user->name }} ({{ $user->user_type }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="grant_permission">Permission <span class="text-danger">*</span></label>
                    <select name="permission" id="grant_permission" class="form-control" required>
                        <option value="">Select a permission</option>
                        @foreach($permissions as $permission)
                            <option value="{{ $permission }}" {{ old('permission') === $permission ? 'selected' : '' }}>{{ $permission }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Add Grant</button>
                <a href="{{ route('role_permissions.index') }}" class="btn btn-light">Back to Grants</a>
            </form>
        </div>
    </div>
@endsection
