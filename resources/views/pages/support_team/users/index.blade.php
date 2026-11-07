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
            <a href="{{ route('users.export', array_filter(['user_type' => $selected_user_type, 'status' => $selected_status, 'q' => ($search_q ?? '') ?: null, 'sort' => $sort ?? 'name', 'dir' => $dir ?? 'asc'])) }}" class="btn btn-light mr-2"><i class="icon-download mr-2"></i> Export CSV</a>
            <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="icon-user-plus mr-2"></i> Create New User</a>
        @endslot

        @slot('filters')
            {{-- Redesign (owner ask 2026-10-25): the two pill rows were
                 unlabeled and stacked with dead space. Now each filter
                 set carries its own label, and both sets sit side by
                 side (divider between them) on desktop, stacking only
                 on narrow screens. Links, values and active states are
                 unchanged. --}}
            <form method="GET" action="{{ route('users.index') }}" class="l106-users-search">
                @if($selected_user_type)<input type="hidden" name="user_type" value="{{ $selected_user_type }}">@endif
                @if($selected_status)<input type="hidden" name="status" value="{{ $selected_status }}">@endif
                @if(($sort ?? 'name') !== 'name')<input type="hidden" name="sort" value="{{ $sort }}">@endif
                @if(($dir ?? 'asc') !== 'asc')<input type="hidden" name="dir" value="{{ $dir }}">@endif
                <div class="input-group">
                    <input type="text" name="q" value="{{ $search_q ?? '' }}" class="form-control" placeholder="Search by name, username or email…">
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-primary"><i class="icon-search4"></i> Search</button>
                        @if(!empty($search_q))<a href="{{ route('users.index', array_filter(['user_type' => $selected_user_type, 'status' => $selected_status])) }}" class="btn btn-light">Clear</a>@endif
                    </div>
                </div>
            </form>
            <div class="l106-users-filters">
                <div class="l106-users-filter-group">
                    <span class="l106-users-filter-label">Role</span>
                    <div class="d-flex flex-wrap" style="gap:8px">
                        <a href="{{ route('users.index') }}" class="btn {{ $selected_user_type ? 'btn-light' : 'btn-primary' }}">All</a>
                        @foreach($user_types as $ut)
                            <a href="{{ route('users.index', ['user_type' => $ut->title]) }}" class="btn {{ $selected_user_type === $ut->title ? 'btn-primary' : 'btn-light' }}">{{ $ut->name }}s</a>
                        @endforeach
                    </div>
                </div>
                <div class="l106-users-filter-group">
                    <span class="l106-users-filter-label">Status</span>
                    <div class="d-flex flex-wrap" style="gap:8px">
                        <a href="{{ route('users.index', array_filter(['user_type' => $selected_user_type])) }}" class="btn {{ $selected_status ? 'btn-light' : 'btn-primary' }}">Any status</a>
                        @foreach(['active' => 'Active', 'suspended' => 'Suspended', 'terminated' => 'Terminated'] as $sv => $sl)
                            <a href="{{ route('users.index', array_filter(['user_type' => $selected_user_type, 'status' => $sv])) }}" class="btn {{ $selected_status === $sv ? 'btn-primary' : 'btn-light' }}">{{ $sl }}</a>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="mt-2">
                @include('partials.patterns.saved_views', ['svList' => 'users'])
            </div>
        @endslot

        @if($users->count())
            @if(Qs::userIsTeamSA())
                {{-- Round 5 Slice 3 (P10): shared bulk bar — Suspend /
                     Activate / Export the ticked rows. The hidden forms
                     live here (one per action, CSRF inside); the bar's
                     JS appends the checked ids[] on submit. Server
                     re-checks every id against the single-row rules
                     and skips + reports ineligible ones. --}}
                <form id="users-bulk-suspend-form" method="post" action="{{ route('users.bulk_status') }}" class="d-none">@csrf<input type="hidden" name="status" value="suspended"></form>
                <form id="users-bulk-activate-form" method="post" action="{{ route('users.bulk_status') }}" class="d-none">@csrf<input type="hidden" name="status" value="active"></form>
                <form id="users-bulk-export-form" method="post" action="{{ route('users.export_selected') }}" class="d-none">@csrf</form>
                @include('partials.patterns.bulk_bar', [
                    'bulk_id' => 'users',
                    'bulk_actions' => [
                        ['label' => 'Suspend selected', 'form' => 'users-bulk-suspend-form', 'class' => 'btn-warning btn-sm', 'icon' => 'icon-user-block', 'confirm' => 'Suspend {count} selected account(s)? They will not be able to sign in. Their records are kept and accounts can be reactivated.', 'confirm_title' => 'Suspend accounts', 'confirm_action' => 'Suspend', 'danger' => true],
                        ['label' => 'Activate selected', 'form' => 'users-bulk-activate-form', 'class' => 'btn-success btn-sm', 'icon' => 'icon-user-check', 'confirm' => 'Reactivate {count} selected account(s)? They will be able to sign in again.', 'confirm_title' => 'Reactivate accounts', 'confirm_action' => 'Reactivate'],
                        ['label' => 'Export selected', 'form' => 'users-bulk-export-form', 'class' => 'btn-light btn-sm', 'icon' => 'icon-download'],
                    ],
                ])
            @endif
            @php
                $uSortBase = array_filter(['user_type' => $selected_user_type, 'status' => $selected_status, 'q' => ($search_q ?? '') ?: null]);
                $uSortUrl = function ($col) use ($uSortBase, $sort, $dir) {
                    $next = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                    return route('users.index', array_merge($uSortBase, ['sort' => $col, 'dir' => $next]));
                };
                $uSortMark = function ($col) use ($sort, $dir) {
                    return $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
                };
            @endphp
            <div class="table-responsive">
            <table class="table hk-std hk-std-users">
                {{-- Beauty Batch 4 (audit D group): fixed layout, widths sum to 100%.
                     P10: the checkbox column (teamSA only) takes 4% from Email. --}}
                @if(Qs::userIsTeamSA())
                    <colgroup><col style="width:4%"><col style="width:5%"><col style="width:7%"><col style="width:19%"><col style="width:14%"><col style="width:13%"><col style="width:20%"><col style="width:12%"><col style="width:6%"></colgroup>
                @else
                    <colgroup><col style="width:5%"><col style="width:7%"><col style="width:19%"><col style="width:14%"><col style="width:13%"><col style="width:24%"><col style="width:12%"><col style="width:6%"></colgroup>
                @endif
                <thead>
                <tr>
                    @if(Qs::userIsTeamSA())<th><span class="sr-only">Select</span></th>@endif
                    <th>S/N</th>
                    <th class="hk-nw">Photo</th>
                    <th><a href="{{ $uSortUrl('name') }}">Name{{ $uSortMark('name') }}</a></th>
                    <th><a href="{{ $uSortUrl('username') }}">Username{{ $uSortMark('username') }}</a></th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th class="hk-nw">Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($users as $u)
                    <tr>
                        @if(Qs::userIsTeamSA())<td><input type="checkbox" class="hk-bulk-check" value="{{ $u->id }}" title="Select {{ $u->name }}"></td>@endif
                        <td class="hk-nw">{{ ($users->firstItem() ?? 1) + $loop->index }}</td>
                        <td>@if(Qs::hasRealPhoto($u->photo))<img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $u->photo }}" alt="photo">@else<span class="hk-ini" style="width:40px;height:40px;font-size:.8rem" aria-hidden="true">{{ Qs::initials($u->name) }}</span>@endif</td>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->username }}</td>
                        <td class="hk-nw">{{ $u->phone }}</td>
                        <td class="hk-ellip" title="{{ $u->email }}">{{ $u->email }}</td>
                        <td class="hk-nw">
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
                                            <button type="button" data-item-name="{{ $u->name }}" onclick="confirmDelete('{{ Qs::hash($u->id) }}', this.dataset.itemName)" class="dropdown-item"><i class="icon-trash"></i> Delete</button>
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
            {{ $users->links() }}
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users',
                'message' => 'No users of this type yet.',
            ])
        @endif
    @endcomponent
  </div>{{-- /.l106-users --}}
@endsection
