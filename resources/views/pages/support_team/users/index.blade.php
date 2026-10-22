@extends('layouts.master')
@section('page_title', 'Manage Users')
@section('content')
    {{-- Batch 5A: rebuilt on the shared list_page pattern (Loop 28).
         Was: one 125KB page with the create form + one stacked table
         per user type (5 tables, ~16-24 dead anchors). Now: a role
         filter (pills, server-side ?user_type=) + ONE table; the
         create form lives on its own page (users.create). Row action
         endpoints, the teamSA-only Reset/Delete block and the
         confirmDelete mechanics are unchanged. --}}
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
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($users as $u)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $u->photo }}" alt="photo"></td>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->username }}</td>
                        <td>{{ $u->phone }}</td>
                        <td>{{ $u->email }}</td>
                        <td class="text-center">
                            <div class="list-icons">
                                <div class="dropdown">
                                    <button type="button" class="list-icons-item btn btn-link p-0" data-toggle="dropdown">
                                        <i class="icon-menu9"></i>
                                    </button>

                                    <div class="dropdown-menu dropdown-menu-left">
                                        {{--View Profile--}}
                                        <a href="{{ route('users.show', Qs::hash($u->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
                                        {{--Edit--}}
                                        <a href="{{ route('users.edit', Qs::hash($u->id)) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                    @if(Qs::userIsTeamSA())

                                            <form method="post" action="{{ route('users.reset_pass', Qs::hash($u->id)) }}" class="m-0" data-confirm="Reset this user's password to the default? They will need the default password to sign in again." data-confirm-title="Reset password" data-confirm-action="Reset password">@csrf<button type="submit" class="dropdown-item"><i class="icon-lock"></i> Reset password</button></form>
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
@endsection
