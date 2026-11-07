@extends('layouts.master')
@section('page_title', 'My Leave Requests')
@section('content')
    {{-- My Leave UX (2026-11-06, overnight beauty run): slim module
         banner, the balance as stat tiles, the apply form in
         labelled groups (Dates / Leave Details / Reason) with a
         live day-count hint, and the same one-glance rows as the
         queue. Fields, names and the POST contract are unchanged. --}}
    <style>
        .lvm-section { border: 1px solid rgba(128, 148, 171, .28); border-radius: 10px; padding: 14px 16px 4px; margin-bottom: 14px; }
        .lvm-section-head { display: flex; align-items: center; gap: 8px; font-weight: 600; margin-bottom: 12px; }
        .lvm-section-ic { width: 28px; height: 28px; border-radius: 50%; background: #e9f1ff; color: #3478f6; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; flex: 0 0 28px; }
        html.hk-dark .lvm-section-ic { background: #35373b; color: #7fb0ff; }
        .lvm-day-hint { margin-left: auto; font-weight: 500; color: #5b7a99; font-size: 12.5px; text-align: right; }
        .lvm-balance-head { display: flex; align-items: center; gap: 8px; font-weight: 600; margin: 2px 0 10px; }
        .lvm-tiles .dashboard-stat-card { height: 100%; }
        .lvm-days b { font-size: 14px; }
        .lvm-reason-more summary { cursor: pointer; color: #3478f6; font-size: 12px; }
    </style>
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-calendar2',
        'hkModTone' => 'blue',
        'hkModTitle' => 'My Leave',
        'hkModSub' => 'Apply for leave and track your requests in one place.',
    ])
    @if(!empty($leave_balance))
        @php $lvFmt = function ($v) { return rtrim(rtrim(number_format((float) $v, 2), '0'), '.'); }; @endphp
        <div class="lvm-balance-head"><i class="icon-coins text-primary"></i> <span>Your leave balance ({{ $leave_balance['year'] }})</span> <span class="text-muted font-weight-normal">— your paid quota, what you have taken and what is left</span></div>
        <div class="row lvm-tiles">
            @if($leave_balance['quota'] > 0)
                <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card dashboard-stat-blue"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar3"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Paid Leave Quota</span><strong>{{ $lvFmt($leave_balance['quota']) }}</strong><small>Paid days per year</small></span></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card dashboard-stat-purple"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-checkmark3"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Taken</span><strong>{{ $lvFmt($leave_balance['used']) }}</strong><small>Approved leave used</small></span></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card dashboard-stat-green"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-hour-glass"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Remaining</span><strong>{{ $lvFmt($leave_balance['remaining']) }}</strong><small>Paid days left</small></span></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card {{ $leave_balance['extra'] > 0 ? 'dashboard-stat-red' : 'dashboard-stat-green' }}"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-alarm"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Extra (Unpaid)</span><strong>{{ $lvFmt($leave_balance['extra']) }}</strong><small>{{ $leave_balance['extra'] > 0 ? 'Extra days — deducted from salary' : 'No extra days yet' }}</small></span></div></div>
            @else
                <div class="col-md-6 mb-3"><div class="dashboard-stat-card dashboard-stat-purple"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-checkmark3"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Leave Taken</span><strong>{{ $lvFmt($leave_balance['used']) }}</strong><small>Approved days in {{ $leave_balance['year'] }}</small></span></div></div>
                <div class="col-md-6 mb-3"><div class="dashboard-stat-card dashboard-stat-orange"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar3"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Paid Leave Quota</span><strong>Not set</strong><small>The school has not set a quota — leave is counted, nothing is deducted</small></span></div></div>
            @endif
        </div>
    @endif
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
                <div class="lvm-section">
                    <div class="lvm-section-head"><span class="lvm-section-ic"><i class="icon-calendar3"></i></span><span>Dates</span><span class="lvm-day-hint" id="lv_day_hint"></span></div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="start_date">From <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="start_date" value="{{ old('start_date') }}" required class="form-control @error('start_date') is-invalid @enderror">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="end_date">To <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" id="end_date" value="{{ old('end_date') }}" required class="form-control @error('end_date') is-invalid @enderror">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="lvm-section">
                    <div class="lvm-section-head"><span class="lvm-section-ic"><i class="icon-list"></i></span><span>Leave Details</span></div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="leave_type">Type <span class="text-danger">*</span></label>
                                <select name="leave_type" id="leave_type" required class="form-control @error('leave_type') is-invalid @enderror">
                                    <option value="normal" {{ old('leave_type', 'normal') === 'normal' ? 'selected' : '' }}>Normal</option>
                                    <option value="emergency" {{ old('leave_type') === 'emergency' ? 'selected' : '' }}>Emergency</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="day_part">Duration</label>
                                <select name="day_part" id="day_part" class="form-control @error('day_part') is-invalid @enderror">
                                    <option value="" {{ old('day_part') ? '' : 'selected' }}>Full day(s)</option>
                                    <option value="first_half" {{ old('day_part') === 'first_half' ? 'selected' : '' }}>Half day — first half (periods 1-4)</option>
                                    <option value="second_half" {{ old('day_part') === 'second_half' ? 'selected' : '' }}>Half day — second half (periods 5-8)</option>
                                    <option value="periods" {{ old('day_part') === 'periods' ? 'selected' : '' }}>Some periods only</option>
                                </select>
                                <small class="form-text text-muted">Half-day and period leave apply to a single day — the To date follows the From date.</small>
                            </div>
                        </div>
                        <div class="col-md-4" id="period_range_row" style="display:none">
                            <div class="form-group">
                                <label>Periods (from — to)</label>
                                <div class="d-flex">
                                    <input type="number" name="period_from" id="period_from" value="{{ old('period_from') }}" min="1" max="12" class="form-control mr-2" placeholder="From">
                                    <input type="number" name="period_to" id="period_to" value="{{ old('period_to') }}" min="1" max="12" class="form-control" placeholder="To">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="lvm-section">
                    <div class="lvm-section-head"><span class="lvm-section-ic"><i class="icon-pencil7"></i></span><span>Reason</span></div>
                    <div class="form-group">
                        <label for="reason">Why do you need this leave? <span class="text-danger">*</span></label>
                        <textarea name="reason" id="reason" rows="2" required maxlength="1000" class="form-control @error('reason') is-invalid @enderror" placeholder="Reason for leave">{{ old('reason') }}</textarea>
                    </div>
                </div>
                <script>
                    (function () {
                        var part = document.getElementById('day_part');
                        var row = document.getElementById('period_range_row');
                        var start = document.getElementById('start_date');
                        var end = document.getElementById('end_date');
                        var pFrom = document.getElementById('period_from');
                        var pTo = document.getElementById('period_to');
                        var hint = document.getElementById('lv_day_hint');
                        if (!part || !row) { return; }
                        var sync = function () { row.style.display = part.value === 'periods' ? '' : 'none'; };
                        var fmt = function (n) { return String(Math.round(n * 100) / 100); };
                        // Live day-count hint, derived only from the
                        // fields already on this form — the server
                        // still counts and validates as before.
                        var hintSync = function () {
                            if (!hint) { return; }
                            var msg = '';
                            var s = start && start.value ? new Date(start.value + 'T00:00:00') : null;
                            var e = end && end.value ? new Date(end.value + 'T00:00:00') : null;
                            if (part.value === 'first_half' || part.value === 'second_half') {
                                msg = 'Half day — counts as 0.5 day' + (s ? ' on ' + s.toLocaleDateString('en-GB') : '');
                            } else if (part.value === 'periods') {
                                var f = parseInt(pFrom && pFrom.value, 10);
                                var t = parseInt(pTo && pTo.value, 10);
                                if (f && t && t >= f) {
                                    msg = 'Periods ' + f + '–' + t + ' — counts as ' + fmt((t - f + 1) / 8) + ' day on one day';
                                } else {
                                    msg = 'Pick the period range — a single day only';
                                }
                            } else if (s && e) {
                                var days = Math.round((e - s) / 86400000) + 1;
                                if (days > 0) {
                                    msg = days + (days === 1 ? ' day' : ' days') + ' — ' + s.toLocaleDateString('en-GB') + ' to ' + e.toLocaleDateString('en-GB');
                                } else {
                                    msg = 'The To date is before the From date';
                                }
                            }
                            hint.textContent = msg;
                        };
                        part.addEventListener('change', function () { sync(); hintSync(); });
                        [start, end, pFrom, pTo].forEach(function (el) {
                            if (el) { el.addEventListener('change', hintSync); el.addEventListener('input', hintSync); }
                        });
                        sync();
                        hintSync();
                    })();
                </script>
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
                            <td><span class="lvm-days"><b>{{ $r->days }}</b> <span class="text-muted">{{ $r->days === 1 ? 'day' : 'days' }}</span></span>@if($r->partialLabel()) @php $lvWin = $r->periodWindow(); $lvFrac = $lvWin ? ($lvWin[1] - $lvWin[0] + 1) / 8 : null; @endphp <div><span class="badge badge-light">{{ $r->partialLabel() }}@if($lvFrac !== null) &middot; {{ rtrim(rtrim(number_format($lvFrac, 2), '0'), '.') }} day @endif</span></div> @endif</td>
                            <td>@if($r->leave_type === 'emergency')<span class="badge badge-danger">Emergency</span>@else<span class="badge badge-secondary">Normal</span>@endif</td>
                            <td>@if(strlen((string) $r->reason) > 110) <span title="{{ $r->reason }}">{{ \Illuminate\Support\Str::limit((string) $r->reason, 110) }}</span> <details class="lvm-reason-more"><summary>Full reason</summary><div class="mt-1">{{ $r->reason }}</div></details> @else {{ $r->reason }} @endif</td>
                            <td><span class="badge badge-{{ $r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($r->status) }}</span></td>
                            <td>{{ $r->decision_note ?? '—' }}@if($r->decided_at) <div class="small text-muted mt-1">Decided {{ $r->decided_at->format('d/m/Y') }}</div> @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">@include('partials.patterns.empty_state', ['icon' => 'icon-calendar2', 'message' => 'No leave requests yet.'])</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $requests->links() }}
        </div>
    </div>
@endsection
