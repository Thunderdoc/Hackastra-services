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
                    <thead><tr><th>Staff</th><th>Allocated Classes</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Uncovered Periods</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead>
                    <tbody>
                    @forelse($requests as $r)
                        <tr>
                            <td>{{ optional($r->user)->name }} <span class="text-muted">({{ ucfirst(optional($r->user)->user_type) }})</span></td>
                            <td>@if(!empty($r->allocation_lines)) <div>{{ \Illuminate\Support\Str::limit($r->allocation_lines[0], 60) }}</div> @if(count($r->allocation_lines) > 1) <details><summary style="cursor:pointer" class="text-primary">Full allocation</summary><div class="mt-1">@foreach($r->allocation_lines as $line) {{ $line }} @if(!$loop->last) <br> @endif @endforeach</div></details> @endif @else <span class="text-muted">—</span> @endif</td>
                            <td>@if($r->leave_type === 'emergency')<span class="badge badge-danger">Emergency</span>@else<span class="badge badge-secondary">Normal</span>@endif</td>
                            <td>{{ $r->start_date_display }}</td>
                            <td>{{ $r->end_date_display }}</td>
                            <td>@if($r->partialLabel()){{ $r->partialLabel() }}@else{{ $r->days }}@endif</td>
                            <td>@if($r->uncovered_periods !== null) @if($r->status === 'pending' && !empty($r->planned_periods)) @if($r->uncovered_periods > 0) <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a> <div class="small text-muted mt-1">Plan ready for {{ $r->planned_periods }} period(s) — approving this leave makes the plan live.</div> @else <a href="{{ route('substitutions.index') }}" class="badge badge-info">Plan ready</a> <div class="small text-muted mt-1">The system has arranged all {{ $r->planned_periods }} period(s) — approving this leave makes the plan live.</div> @endif @elseif($r->uncovered_periods > 0) <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a> @else <span class="badge badge-success">Covered</span> @endif @if(!empty($r->cover_names)) <div class="small text-muted mt-1">By: {{ implode(', ', array_slice($r->cover_names, 0, 2)) }}@if(count($r->cover_names) > 2) +{{ count($r->cover_names) - 2 }} more @endif</div> @endif @if($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) <details class="mt-1"><summary style="cursor:pointer" class="text-primary">See the plan ({{ $r->plan_rows->count() }})</summary><div class="table-responsive mt-1"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Period</th><th>Substitute</th><th>Switch</th></tr></thead><tbody>@foreach($r->plan_rows as $pr)<tr><td>{{ $pr->date ? $pr->date->format('d/m/Y') : '—' }}</td><td>{{ optional(optional(optional($pr->time_table)->tt_record)->my_class)->name ?: '—' }}@if(optional(optional($pr->time_table)->tt_record)->section) — {{ $pr->time_table->tt_record->section->name }}@endif<br><span class="text-muted">{{ optional(optional($pr->time_table)->subject)->name }} ({{ optional($pr->time_table)->day }} {{ optional(optional($pr->time_table)->time_slot)->full }})</span></td><td>{{ optional($pr->substitute)->name ?: '—' }}</td><td>@php $opts = $r->plan_free_options[$pr->id] ?? collect(); @endphp @if($opts->isEmpty()) <span class="text-muted">No other teacher is free at this time</span> @else <form method="POST" action="{{ route('substitutions.switch', $pr->id) }}" class="form-inline">@csrf<select name="substitute_teacher_id" class="form-control form-control-sm mr-1">@foreach($opts as $opt)<option value="{{ $opt->id }}">{{ $opt->name }} — free</option>@endforeach</select><button type="submit" class="btn btn-sm btn-secondary">Switch</button></form> @endif</td></tr>@endforeach</tbody></table></div><div class="small text-muted mt-1">Approving this leave makes the plan live — substitutes and students are notified automatically.</div></details> @endif @else <span class="text-muted">—</span> @endif</td>
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
                        <tr><td colspan="10" class="text-center text-muted">No leave requests yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $requests->links() }}
        </div>
    </div>
@endsection
