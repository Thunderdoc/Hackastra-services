@extends('layouts.master')
@section('page_title', 'Settings Unlock Requests')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Settings Unlock Requests</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">
                The school settings are currently <strong>{{ $locked ? 'locked' : 'open for editing' }}</strong>.
                An unlock needs two approvals in order: the <strong>Principal</strong> first, then the <strong>Super Admin</strong>.
                When the Super Admin approves, the settings open and both the Admin and the Principal are notified.
            </p>

            @if($requests->isEmpty())
                <p class="text-muted mb-0">No unlock requests yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Requested</th><th>By</th><th>Changes requested</th><th>Status</th><th>Decision</th></tr></thead>
                        <tbody>
                        @foreach($requests as $r)
                            <tr>
                                <td>{{ $r->created_at->format('d M Y, g:i A') }}</td>
                                <td>{{ optional($r->requester)->name ?: '—' }}</td>
                                <td style="max-width:420px">{{ $r->changes }}</td>
                                <td>
                                    @if($r->status === 'approved')<span class="badge badge-success">{{ $r->statusLabel() }}</span>
                                    @elseif($r->status === 'rejected')<span class="badge badge-danger">{{ $r->statusLabel() }}</span>
                                    @else<span class="badge badge-warning">{{ $r->statusLabel() }}</span>@endif
                                </td>
                                <td>
                                    @if($r->status === 'pending_principal' && $role === 'principal')
                                        <form method="post" action="{{ route('settings_unlock.decide', $r->id) }}" style="display:inline">
                                            @csrf
                                            <button name="decision" value="approve" class="btn btn-success btn-sm">Approve — send to Super Admin</button>
                                        </form>
                                        <form method="post" action="{{ route('settings_unlock.decide', $r->id) }}" style="display:inline" data-confirm="Reject this unlock request? The settings stay locked." data-confirm-title="Reject Request" data-confirm-action="Reject" data-confirm-danger>
                                            @csrf
                                            <button name="decision" value="reject" class="btn btn-light btn-sm text-danger">Reject</button>
                                        </form>
                                    @elseif($r->status === 'pending_super_admin' && $role === 'super_admin')
                                        <form method="post" action="{{ route('settings_unlock.decide', $r->id) }}" style="display:inline">
                                            @csrf
                                            <button name="decision" value="approve" class="btn btn-success btn-sm">Approve — unlock the settings</button>
                                        </form>
                                        <form method="post" action="{{ route('settings_unlock.decide', $r->id) }}" style="display:inline" data-confirm="Reject this unlock request? The settings stay locked." data-confirm-title="Reject Request" data-confirm-action="Reject" data-confirm-danger>
                                            @csrf
                                            <button name="decision" value="reject" class="btn btn-light btn-sm text-danger">Reject</button>
                                        </form>
                                    @elseif($r->isOpen())
                                        <span class="text-muted">Waiting — not your stage</span>
                                    @else
                                        <span class="text-muted">Decided</span>
                                    @endif
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
