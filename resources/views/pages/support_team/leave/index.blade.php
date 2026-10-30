@extends('layouts.master')
@section('page_title', 'Leave Requests')
@section('content')
    {{-- Leave queue, phone layout (owner screenshot, 2026-10-30):
         the 10-column table cannot fit a phone — at desktop widths
         it stays a table; below 992px every request stacks into a
         card (same single DOM, same forms, presentation only) and
         the plan rows go compact: one line for date + time, one
         for class, one for subject, the substitute's name in
         bold, and the switch select + button on one row. Nothing
         on this page may scroll sideways at 360px or 320px. --}}
    <style>
        /* Desktop table (owner, 2026-10-30: the columns and rows
           looked bad on desktop too): fixed proportions from the
           colgroup, a navy header band in the app's document
           language, even padding, top-aligned cells, zebra rows,
           and the plan as a compact inset panel instead of a
           nested raw table. */
        .lv-table { table-layout: fixed; margin-bottom: 0; }
        .lv-table thead th { background: #10263b; color: #fff; font-weight: 600; letter-spacing: .02em; padding: 10px 12px; border: none; }
        .lv-table tbody td { padding: 10px 12px; vertical-align: top; line-height: 1.45; overflow-wrap: break-word; }
        .lv-table tbody tr:nth-child(even) { background: rgba(128, 148, 171, .07); }
        .lv-table .lv-decide { display: flex; flex-wrap: wrap; gap: 6px; }
        .lv-table .lv-decide input { flex: 1 1 100%; margin: 0 !important; }
        .lv-table .lv-decide button { flex: 1 1 0; margin: 0 !important; }
        .lv-plan-wrap { background: rgba(128, 148, 171, .10); border: 1px solid rgba(128, 148, 171, .35); border-radius: 8px; padding: 4px 10px; overflow-x: visible; }
        .lv-plan-table { table-layout: fixed; }
        .lv-plan-table thead th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; padding: 6px 8px; border-top: none; }
        .lv-plan-table tbody td { padding: 6px 8px; vertical-align: middle; }
        .lv-plan-table colgroup col:nth-child(1) { width: 24%; }
        .lv-plan-class { font-weight: 600; }
        .lv-plan-subname { font-weight: 700; }
        .lv-plan-table select { min-width: 0; }
        .lv-plan-table form { display: flex; gap: 6px; align-items: center; }
        .lv-plan-table form select { flex: 1 1 auto; min-width: 0; margin: 0 !important; }
        .lv-plan-table form button { flex: 0 0 auto; }
        @media (max-width: 991.98px) {
            .lv-wrap, .lv-plan-wrap { overflow-x: visible; }
            .lv-table, .lv-table tbody, .lv-table tr, .lv-table td { display: block; width: 100%; }
            .lv-table thead { display: none; }
            .lv-table tbody tr { position: relative; border: 1px solid rgba(128, 148, 171, .45); border-radius: 10px; margin-bottom: 14px; padding: 12px 14px; }
            .lv-table td { border: none !important; padding: 2px 0; overflow-wrap: break-word; }
            .lv-table td:nth-child(1) { font-size: 15px; font-weight: 600; padding-right: 92px; }
            .lv-table td:nth-child(2)::before { content: "Classes: "; font-weight: 600; }
            .lv-table td:nth-child(4)::before { content: "From: "; font-weight: 600; }
            .lv-table td:nth-child(5)::before { content: "To: "; font-weight: 600; }
            .lv-table td:nth-child(6)::before { content: "Days: "; font-weight: 600; }
            .lv-table td:nth-child(7)::before { content: "Cover: "; font-weight: 600; display: block; margin-bottom: 2px; }
            .lv-table td:nth-child(8)::before { content: "Reason: "; font-weight: 600; }
            .lv-table td:nth-child(9) { position: absolute; top: 12px; right: 14px; width: auto; padding: 0; }
            .lv-table .lv-decide { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
            .lv-table .lv-decide input { flex: 1 1 100%; margin: 0 !important; }
            .lv-table .lv-decide button { flex: 1 1 0; margin: 0 !important; }
            .lv-plan-table, .lv-plan-table tbody, .lv-plan-table tr, .lv-plan-table td { display: block; width: 100%; }
            .lv-plan-table thead { display: none; }
            .lv-plan-table tbody tr { border-top: 1px dashed rgba(128, 148, 171, .5); padding: 8px 0 2px; }
            .lv-plan-table td { border: none !important; padding: 2px 0; overflow-wrap: break-word; }
            .lv-plan-table td:nth-child(3)::before { content: "Substitute: "; font-weight: 600; }
            .lv-plan-table td:nth-child(3) .lv-plan-subname { font-size: 14.5px; }
            .lv-plan-table form { display: flex; gap: 6px; margin-top: 4px; }
            .lv-plan-table form select { flex: 1 1 auto; min-width: 0; margin: 0 !important; }
            .lv-plan-table form button { flex: 0 0 auto; }
        }
    </style>
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Leave Requests — pending first</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <div class="table-responsive lv-wrap">
                <table class="table table-sm lv-table">
                    <colgroup>
                        <col style="width:13%"><col style="width:13%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:4%"><col style="width:23%"><col style="width:9%"><col style="width:6%"><col style="width:12%">
                    </colgroup>
                    <thead><tr><th>Staff</th><th>Allocated Classes</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Uncovered Periods</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead>
                    <tbody>
                    @forelse($requests as $r)
                        <tr>
                            <td>{{ optional($r->user)->name }} <span class="text-muted">({{ ucfirst(optional($r->user)->user_type) }})</span></td>
                            <td>@if(!empty($r->allocation_summary)) <div>{{ $r->allocation_summary }}</div> @if(!empty($r->allocation_lines) && (count($r->allocation_lines) > 1 || $r->allocation_lines[0] !== $r->allocation_summary)) <details><summary style="cursor:pointer" class="text-primary">Full allocation</summary><div class="mt-1">@foreach($r->allocation_lines as $line) {{ $line }} @if(!$loop->last) <br> @endif @endforeach</div></details> @endif @elseif(!empty($r->allocation_lines)) <div>{{ \Illuminate\Support\Str::limit($r->allocation_lines[0], 60) }}</div> @if(count($r->allocation_lines) > 1) <details><summary style="cursor:pointer" class="text-primary">Full allocation</summary><div class="mt-1">@foreach($r->allocation_lines as $line) {{ $line }} @if(!$loop->last) <br> @endif @endforeach</div></details> @endif @else <span class="text-muted">—</span> @endif</td>
                            <td>@if($r->leave_type === 'emergency')<span class="badge badge-danger">Emergency</span>@else<span class="badge badge-secondary">Normal</span>@endif</td>
                            <td>{{ $r->start_date_display }}</td>
                            <td>{{ $r->end_date_display }}</td>
                            <td>@if($r->partialLabel()){{ $r->partialLabel() }}@else{{ $r->days }}@endif</td>
                            <td>@if($r->uncovered_periods !== null) @if($r->status === 'pending' && !empty($r->planned_periods)) @if($r->uncovered_periods > 0) <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a> @else <a href="{{ route('substitutions.index') }}" class="badge badge-info">Plan ready</a> @endif @elseif($r->uncovered_periods > 0) <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a> @else <span class="badge badge-success">Covered</span> @endif @if(($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) || !empty($r->cover_names)) <details class="mt-1"><summary style="cursor:pointer" class="text-primary">@if($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) See the plan ({{ $r->plan_rows->count() }}) @else Cover details @endif</summary>@if($r->status === 'pending' && !empty($r->planned_periods)) <div class="small text-muted mt-1">@if($r->uncovered_periods > 0) Plan ready for {{ $r->planned_periods }} period(s) — approving this leave makes the plan live. @else The system has arranged all {{ $r->planned_periods }} period(s) — approving this leave makes the plan live. @endif</div> @endif @if(!empty($r->cover_names)) <div class="small text-muted mt-1">Covered by: {{ implode(', ', $r->cover_names) }}</div> @endif @if($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) <div class="table-responsive lv-plan-wrap mt-1"><table class="table table-sm mb-0 lv-plan-table"><colgroup><col style="width:24%"><col style="width:30%"><col style="width:18%"><col style="width:28%"></colgroup><thead><tr><th>Date</th><th>Period</th><th>Substitute</th><th>Switch</th></tr></thead><tbody>@foreach($r->plan_rows as $pr)<tr><td><span class="lv-plan-when">{{ $pr->date ? $pr->date->format('D, d/m/Y') : '—' }}@if(optional(optional($pr->time_table)->time_slot)->full) <span class="text-muted">&middot; {{ $pr->time_table->time_slot->full }}</span>@endif</span></td><td><span class="lv-plan-class">{{ optional(optional(optional($pr->time_table)->tt_record)->my_class)->name ?: '—' }}@if(optional(optional($pr->time_table)->tt_record)->section) — {{ $pr->time_table->tt_record->section->name }}@endif</span><br><span class="lv-plan-subj text-muted">{{ optional(optional($pr->time_table)->subject)->name }}</span></td><td><span class="lv-plan-subname">{{ optional($pr->substitute)->name ?: '—' }}</span></td><td>@php $opts = $r->plan_free_options[$pr->id] ?? collect(); @endphp @if($opts->isEmpty()) <span class="text-muted">No other teacher is free at this time</span> @else <form method="POST" action="{{ route('substitutions.switch', $pr->id) }}" class="form-inline">@csrf<select name="substitute_teacher_id" class="form-control form-control-sm mr-1">@foreach($opts as $opt)<option value="{{ $opt->id }}">{{ $opt->name }} — free</option>@endforeach</select><button type="submit" class="btn btn-sm btn-secondary">Switch</button></form> @endif</td></tr>@endforeach</tbody></table></div> @endif </details> @endif @else <span class="text-muted">—</span> @endif</td>
                            <td>{{ $r->reason }}</td>
                            <td><span class="badge badge-{{ $r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($r->status) }}</span></td>
                            <td>
                                @if($r->status === 'pending' && (int) $r->user_id !== (int) auth()->id())
                                    <form method="POST" action="{{ route('leave.decide', $r->id) }}" class="form-inline lv-decide">
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
                        <tr><td colspan="10" class="text-center text-muted">No leave requests yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $requests->links() }}
        </div>
    </div>
@endsection
