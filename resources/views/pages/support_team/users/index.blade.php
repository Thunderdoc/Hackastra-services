@extends('layouts.master')
@section('page_title', 'Manage Users')
@section('content')
    {{-- Batch 5A: rebuilt on the shared list_page pattern (Loop 28).
         Was: one 125KB page with the create form + one stacked table
         per user type (5 tables, ~16-24 dead anchors). Now: a role
         filter (pills, server-side ?user_type=) + ONE table; the
         create form lives on its own page (users.create). Row action
         endpoints, the teamSA-only Reset/Delete block and the
         confirmDelete mechanics are unchanged.
         Loop 106: premium design scope (.l106-users) — pill filter
         chips, card table, status pills; same for both roles, the
         DATA stays scoped by viewer in UserController@index. --}}
  <div class="l106 l106-users">
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-users mr-2"></i> Manage Users
        @endslot
        @slot('subtitle')
            {{ $users->count() }} {{ $selected_user_type ? (($user_types->firstWhere('title', $selected_user_type)->name ?? $selected_user_type) . 's') : 'users' }} shown
        @endslot
        @slot('action')
            <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="icon-user-plus mr-2"></i> Create New User</a>
        @endslot

        @slot('filters')
            <div class="d-flex flex-wrap" style="gap:8px">
                <a href="{{ route('users.index') }}" class="btn {{ $selected_user_type ? 'btn-light' : 'btn-primary' }}">All</a>
                @foreach($user_types as $ut)
                    <a href="{{ route('users.index', ['user_type' => $ut->title]) }}" class="btn {{ $selected_user_type === $ut->title ? 'btn-primary' : 'btn-light' }}">{{ $ut->name }}s</a>
                @endforeach
            </div>
            {{-- Account lifecycle filter (Workstream DF) --}}
            <div class="d-flex flex-wrap mt-2" style="gap:8px">
                <a href="{{ route('users.index', array_filter(['user_type' => $selected_user_type])) }}" class="btn {{ $selected_status ? 'btn-light' : 'btn-primary' }}">Any status</a>
                @foreach(['active' => 'Active', 'suspended' => 'Suspended', 'terminated' => 'Terminated'] as $sv => $sl)
                    <a href="{{ route('users.index', array_filter(['user_type' => $selected_user_type, 'status' => $sv])) }}" class="btn {{ $selected_status === $sv ? 'btn-primary' : 'btn-light' }}">{{ $sl }}</a>
                @endforeach
            </div>
        @endslot

        @if($users->count())
            <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Photo</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($users as $u)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>@if(Qs::hasRealPhoto($u->photo))<img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $u->photo }}" alt="photo">@else<span class="hk-ini" style="width:40px;height:40px;font-size:.8rem" aria-hidden="true">{{ Qs::initials($u->name) }}</span>@endif</td>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->username }}</td>
                        <td>{{ $u->phone }}</td>
                        <td>{{ $u->email }}</td>
                        <td>
                            @php($acct_status = $u->getAttribute('status') ?: 'active')
                            @if($acct_status === 'suspended')
                                <span class="badge badge-warning">Suspended</span>
                            @elseif($acct_status === 'terminated')
                                <span class="badge badge-danger">Terminated</span>
                            @else
                                <span class="badge badge-success">Active</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <div class="list-icons">
                                <div class="dropdown">
                                    <button type="button" class="list-icons-item btn btn-link p-0" data-toggle="dropdown">
                                        <i class="icon-menu9"></i>
                                    </button>

                                    <div class="dropdown-menu dropdown-menu-left">
                                        {{--View Profile--}}
                                        <a href="{{ route('users.show', Qs::hash($u->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
                                        {{--Edit — target-aware (Workstream CM): an account row
                                             offers write actions only to an actor allowed to
                                             write that account's domain. --}}
                                        @if(((Qs::userIsSuperAdmin() && in_array($u->user_type, ['super_admin', 'admin'], true)) || (Qs::userIsAdmin() && $u->user_type !== 'super_admin')))
                                        <a href="{{ route('users.edit', Qs::hash($u->id)) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                        @endif
                                    @if(((Qs::userIsSuperAdmin() && in_array($u->user_type, ['super_admin', 'admin'], true)) || (Qs::userIsAdmin() && $u->user_type !== 'super_admin')))

                                            <form method="post" action="{{ route('users.reset_pass', Qs::hash($u->id)) }}" class="m-0" data-confirm="Reset this user's password to the default? They will need the default password to sign in again." data-confirm-title="Reset password" data-confirm-action="Reset password">@csrf<button type="submit" class="dropdown-item"><i class="icon-lock"></i> Reset password</button></form>
                                            {{--Account lifecycle (Workstream DF) — state changes, records preserved.
                                                 Never offered on the actor's own row or the head Super Admin's:
                                                 the server refuses both, so the buttons would be dead. --}}
                                            @if((int) $u->id !== (int) Auth::id() && !Qs::headSA((int) $u->id))
                                            @if($acct_status === 'active')
                                                <form method="post" action="{{ route('users.suspend', Qs::hash($u->id)) }}" class="m-0" data-confirm="Suspend this account? The user will not be able to sign in, and any open session ends. Their records are kept and the account can be reactivated." data-confirm-title="Suspend account" data-confirm-action="Suspend">@csrf<button type="submit" class="dropdown-item"><i class="icon-user-block"></i> Suspend</button></form>
                                            @else
                                                <form method="post" action="{{ route('users.activate', Qs::hash($u->id)) }}" class="m-0" data-confirm="Reactivate this account? The user will be able to sign in again." data-confirm-title="Reactivate account" data-confirm-action="Reactivate">@csrf<button type="submit" class="dropdown-item"><i class="icon-user-check"></i> Reactivate</button></form>
                                            @endif
                                            @if($acct_status !== 'terminated')
                                                <form method="post" action="{{ route('users.terminate', Qs::hash($u->id)) }}" class="m-0" data-confirm="Terminate this account? Sign-in is permanently blocked (until reactivated by an administrator) but the person's records are preserved — nothing is deleted." data-confirm-title="Terminate account" data-confirm-action="Terminate">@csrf<button type="submit" class="dropdown-item text-danger"><i class="icon-user-cancel"></i> Terminate</button></form>
                                            @endif
                                            @endif
                                            {{--Delete--}}
                                            <button type="button" onclick="confirmDelete('{{ Qs::hash($u->id) }}')" class="dropdown-item"><i class="icon-trash"></i> Delete</button>
                                            <form method="post" id="item-delete-{{ Qs::hash($u->id) }}" action="{{ route('users.destroy', Qs::hash($u->id)) }}" class="hidden">@csrf @method('delete')</form>
                                    @endif

                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users',
                'message' => 'No users of this type yet.',
            ])
        @endif
    @endcomponent
  </div>{{-- /.l106-users --}}
@endsection
