@extends('layouts.master')
@section('page_title', 'Leave Requests')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Leave Requests — pending first</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Staff</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead>
                    <tbody>
                    @forelse($requests as $r)
                        <tr>
                            <td>{{ optional($r->user)->name }} <span class="text-muted">({{ ucfirst(optional($r->user)->user_type) }})</span></td>
                            <td>{{ $r->start_date }}</td>
                            <td>{{ $r->end_date }}</td>
                            <td>{{ $r->days }}</td>
                            <td>{{ $r->reason }}</td>
                            <td><span class="badge badge-{{ $r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($r->status) }}</span></td>
                            <td>
                                @if($r->status === 'pending' && (int) $r->user_id !== (int) auth()->id())
                                    <form method="POST" action="{{ route('leave.decide', $r->id) }}" class="form-inline">
                                        @csrf
                                        <input type="text" name="decision_note" class="form-control form-control-sm mr-1" placeholder="Note (optional)">
                                        <button type="submit" name="status" value="approved" class="btn btn-success btn-sm mr-1">Approve</button>
                                        <button type="submit" name="status" value="rejected" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                @elseif($r->status === 'pending')
                                    <span class="text-muted">Your own request — another admin must decide it.</span>
                                @else
                                    <span class="text-muted">{{ $r->decision_note ?? '—' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No leave requests yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $requests->links() }}
        </div>
    </div>
@endsection
