@extends('layouts.master')
@section('page_title', 'Role Permissions')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Administration &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Custom Permission Grants</h5>
            <p class="text-muted mb-0">Admin only. A grant adds one whitelisted action to one user — it never changes menus, user types, or base rules. Deleting a grant restores the base rule.</p>
            <a href="{{ route('role_permissions.create') }}" class="btn btn-primary btn-sm float-right">New Grant</a>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif
            @if ($grants->isEmpty())
                @include('partials.patterns.empty_state', ['icon' => 'icon-lock', 'message' => 'No grants yet.'])
            @else
                <div class="table-responsive">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <table class="table table-striped hk-std hk-std-720">
                        <colgroup><col style="width:20%"><col style="width:12%"><col style="width:24%"><col style="width:18%"><col style="width:16%"><col style="width:10%"></colgroup>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Permission</th>
                                <th>Granted By</th>
                                <th>Granted At</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grants as $grant)
                                <tr>
                                    <td>{{ optional($grant->user)->name ?: '-' }}</td>
                                    <td>{{ optional($grant->user)->user_type ?: '-' }}</td>
                                    <td>{{ $grant->permission }}</td>
                                    <td>{{ optional($grant->granter)->name ?: '-' }}</td>
                                    <td class="hk-nw">{{ $grant->created_at }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('role_permissions.destroy', $grant->id) }}" data-confirm="Revoke the {{ $grant->permission }} grant for {{ optional($grant->user)->name ?: 'this user' }}? The base rule will apply again." data-confirm-title="Revoke Grant" data-confirm-action="Revoke" data-confirm-danger>
                                            @csrf
                                            @method('delete')
                                            <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
