@extends('layouts.master')
@section('page_title', 'My Leave Requests')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Apply for Leave</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if(!empty($approver_name))
                <p class="text-muted">Your class teacher ({{ $approver_name }}) will approve or reject this request.</p>
            @endif
            <form method="POST" action="{{ route('leave.store') }}">
                @csrf
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="start_date">From <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="start_date" value="{{ old('start_date') }}" required class="form-control @error('start_date') is-invalid @enderror">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="end_date">To <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="end_date" value="{{ old('end_date') }}" required class="form-control @error('end_date') is-invalid @enderror">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="reason">Reason <span class="text-danger">*</span></label>
                            <input type="text" name="reason" id="reason" value="{{ old('reason') }}" required maxlength="1000" class="form-control @error('reason') is-invalid @enderror" placeholder="Reason for leave">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="leave_type">Type <span class="text-danger">*</span></label>
                            <select name="leave_type" id="leave_type" required class="form-control @error('leave_type') is-invalid @enderror">
                                <option value="normal" {{ old('leave_type', 'normal') === 'normal' ? 'selected' : '' }}>Normal</option>
                                <option value="emergency" {{ old('leave_type') === 'emergency' ? 'selected' : '' }}>Emergency</option>
                            </select>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-success">Submit Request</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">My Requests</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>From</th><th>To</th><th>Days</th><th>Type</th><th>Reason</th><th>Status</th><th>Decision Note</th></tr></thead>
                    <tbody>
                    @forelse($requests as $r)
                        <tr>
                            <td>{{ $r->start_date_display }}</td>
                            <td>{{ $r->end_date_display }}</td>
                            <td>{{ $r->days }}</td>
                            <td>@if($r->leave_type === 'emergency')<span class="badge badge-danger">Emergency</span>@else<span class="badge badge-secondary">Normal</span>@endif</td>
                            <td>{{ $r->reason }}</td>
                            <td><span class="badge badge-{{ $r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($r->status) }}</span></td>
                            <td>{{ $r->decision_note ?? '—' }}</td>
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
