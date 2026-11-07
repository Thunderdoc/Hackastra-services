@extends('layouts.master')
@section('page_title', 'Leave Requests')
@section('content')
    {{-- Leave queue (owner, 2026-11-06, after the "text wall"
         rejection): the request list is one-glance CARDS — who,
         when, how long, why (one line), cover state and the
         decision on one card each; everything heavier (allocation
         facts, the week grid, the cover plan) lives behind
         expanders on the card. What IS tabular on the card — the
         cover-plan rows and the week timetable — renders as a
         proper table: fixed proportions, one-line atomic fields,
         real width for text columns. Cards stack full-width, so
         nothing on this page scrolls sideways at 360px or 320px. --}}
    <style>
        .lv-stat-row .dashboard-stat-card { height: 100%; }
        .lv-cover-strip { display: flex; align-items: center; gap: 8px; }

        /* Request cards */
        .lv-req-list { display: flex; flex-direction: column; gap: 12px; }
        .lv-req-card { border: 1px solid rgba(128, 148, 171, .4); border-radius: 10px; padding: 14px 16px 12px; }
        .lv-req-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .lv-who { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .lv-avatar { flex: 0 0 32px; width: 32px; height: 32px; border-radius: 50%; background: #e9f1ff; color: #2867dc; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; letter-spacing: .02em; }
        html.hk-dark .lv-avatar { background: #35373b; color: #7fb0ff; }
        .lv-who-name { font-weight: 600; font-size: 15px; }
        .lv-role-chip { display: inline-block; font-size: 10.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #5b7a99; background: rgba(128, 148, 171, .16); border-radius: 999px; padding: 1px 7px; margin-left: 4px; vertical-align: 2px; white-space: nowrap; }
        .lv-req-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .lv-req-chips .badge { white-space: nowrap; }
        .lv-meta { display: flex; flex-wrap: wrap; gap: 2px 7px; align-items: baseline; margin-top: 7px; font-size: 13px; color: #5b7a99; }
        html.hk-dark .lv-meta { color: #93a7bd; }
        .lv-meta .lv-nowrap, .lv-days { white-space: nowrap; }
        .lv-days b { font-size: 13.5px; }
        .lv-balance { margin-top: 3px; }
        .lv-overlap { margin-top: 8px; padding: 6px 10px; border-radius: 6px; background: rgba(220, 53, 69, .10); border: 1px solid rgba(220, 53, 69, .35); color: #b02a37; font-size: 12.5px; }
        html.hk-dark .lv-overlap { color: #ff9aa5; }
        .lv-reason { margin-top: 8px; line-height: 1.45; overflow-wrap: break-word; }
        .lv-reason-line { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lv-reason-more summary { cursor: pointer; color: #3478f6; font-size: 12px; }

        /* Collapsed detail expanders on the card */
        .lv-details { margin-top: 8px; }
        .lv-details > summary, .lv-sub-details > summary { cursor: pointer; color: #3478f6; font-size: 12.5px; }
        .lv-details-body { margin-top: 7px; }
        .lv-alloc-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .lv-alloc-chip { display: inline-block; font-size: 12px; font-weight: 600; color: #33506e; background: rgba(52, 120, 246, .10); border: 1px solid rgba(52, 120, 246, .25); border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
        html.hk-dark .lv-alloc-chip { color: #a9c6ea; background: rgba(52, 120, 246, .16); }
        .lv-sub-details { margin-top: 7px; }

        /* Cover row + the plan expander */
        .lv-cover-row { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-top: 10px; }
        .lv-cover-row .badge { white-space: nowrap; }
        .lv-plan-details { margin-top: 7px; }
        .lv-plan-details > summary { cursor: pointer; color: #3478f6; font-size: 12.5px; }

        /* The plan + week tables: proper fixed-proportion sheets —
           short fields on one line, text columns with real width,
           even padding, headers aligned to their columns. */
        .lv-plan-wrap { background: rgba(128, 148, 171, .10); border: 1px solid rgba(128, 148, 171, .35); border-radius: 8px; padding: 4px 10px; overflow-x: visible; }
        .lv-plan-table { table-layout: fixed; margin-bottom: 0; }
        .lv-plan-table thead th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; padding: 6px 8px; border-top: none; white-space: nowrap; }
        .lv-plan-table tbody td { padding: 6px 8px; vertical-align: middle; line-height: 1.45; }
        .lv-plan-when { white-space: nowrap; }
        .lv-plan-class { font-weight: 600; }
        .lv-plan-subname { font-weight: 700; }
        .lv-plan-table select { min-width: 0; }
        .lv-plan-table form { display: flex; gap: 6px; align-items: center; }
        .lv-plan-table form select { flex: 1 1 auto; min-width: 0; margin: 0 !important; }
        .lv-plan-table form button { flex: 0 0 auto; }
        .lv-week-table { table-layout: fixed; margin-bottom: 0; }
        .lv-week-table thead th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; padding: 6px 8px; border-top: none; white-space: nowrap; }
        .lv-week-table tbody td { padding: 6px 8px; vertical-align: top; line-height: 1.45; }
        .lv-week-nowrap { white-space: nowrap; }

        /* Decision row: its own row, right-aligned, never squeezed */
        .lv-req-decide { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; margin-top: 12px; }
        .lv-req-decide input { flex: 1 1 240px; margin: 0 !important; }
        .lv-req-decide button { flex: 0 0 auto; margin: 0 !important; }
        .lv-req-own { margin-top: 12px; text-align: right; font-size: 12.5px; }
        .lv-decided-line { margin-top: 10px; font-size: 12.5px; text-align: right; }

        @media (max-width: 991.98px) {
            /* Phone: the plan and week tables go compact — one
               line each for date + time, class, subject, the
               substitute's name in bold, and the switch select +
               button on one row — instead of squeezing columns. */
            .lv-plan-wrap { overflow-x: visible; }
            .lv-plan-table, .lv-plan-table tbody, .lv-plan-table tr, .lv-plan-table td,
            .lv-week-table, .lv-week-table tbody, .lv-week-table tr, .lv-week-table td { display: block; width: 100%; }
            .lv-plan-table thead, .lv-week-table thead { display: none; }
            .lv-plan-table tbody tr, .lv-week-table tbody tr { border-top: 1px dashed rgba(128, 148, 171, .5); padding: 8px 0 2px; }
            .lv-plan-table td, .lv-week-table td { border: none !important; padding: 2px 0; overflow-wrap: break-word; }
            .lv-plan-table td:nth-child(3)::before { content: "Substitute: "; font-weight: 600; }
            .lv-plan-table td:nth-child(3) .lv-plan-subname { font-size: 14.5px; }
            .lv-plan-table form { display: flex; gap: 6px; margin-top: 4px; }
            .lv-plan-table form select { flex: 1 1 auto; min-width: 0; margin: 0 !important; }
            .lv-plan-table form button { flex: 0 0 auto; }
            .lv-week-table td:nth-child(1) { font-weight: 600; }
            .lv-week-table td:nth-child(3)::before { content: "Class: "; font-weight: 600; }
            .lv-week-table td:nth-child(5)::before { content: "Subject: "; font-weight: 600; }
            .lv-week-table td:nth-child(6)::before { content: "Room: "; font-weight: 600; }
        }
        @media (max-width: 575.98px) {
            .lv-req-decide input { flex: 1 1 100%; }
            .lv-req-decide button { flex: 1 1 0; }
            .lv-decided-line, .lv-req-own { text-align: left; }
        }
    </style>
    {{-- Slim module banner (Beauty Wave 1 pattern): the queue's
         figures ride the banner on the deciders' board; the
         teacher's student-requests view reuses this blade without
         a summary and gets the plain banner. --}}
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-calendar2',
        'hkModTone' => 'orange',
        'hkModTitle' => 'Leave Requests',
        'hkModSub' => isset($summary) ? 'Decide requests, see the cover plan and spot uncovered periods at a glance.' : 'Student leave requests from your sections — you decide these.',
        'hkModStats' => isset($summary) ? [
            ['value' => $summary['pending'], 'label' => 'Pending'],
            ['value' => $summary['approved'], 'label' => 'Approved'],
            ['value' => $summary['total'], 'label' => 'Total'],
        ] : [],
    ])
    @isset($summary)
    {{-- Leave intelligence summary (owner brief 2026-10-30): real
         counts over every request; the strip is the pending
         queue's coverage health. Same figures, presented as the
         shared dashboard stat tiles (2026-11-06). --}}
    <div class="row lv-stat-row">
        <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card dashboard-stat-orange"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-alarm"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Pending Requests</span><strong>{{ $summary['pending'] }}</strong><small>Waiting for a decision</small></span></div></div>
        <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card dashboard-stat-green"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-checkmark3"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Approved Requests</span><strong>{{ $summary['approved'] }}</strong><small>Leave granted</small></span></div></div>
        <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card dashboard-stat-red"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-cross2"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Rejected Requests</span><strong>{{ $summary['rejected'] }}</strong><small>Leave refused</small></span></div></div>
        <div class="col-md-3 col-6 mb-3"><div class="dashboard-stat-card dashboard-stat-blue"><span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-list"></i></span></span><span class="dashboard-stat-copy"><span class="dashboard-stat-label">Total Requests</span><strong>{{ $summary['total'] }}</strong><small>All requests so far</small></span></div></div>
    </div>
    <div class="alert alert-info lv-cover-strip"><i class="icon-checkmark3"></i><span>Pending queue coverage: <strong>{{ $summary['partial'] }}</strong> partially covered &middot; <strong>{{ $summary['uncovered'] }}</strong> period(s) still uncovered &middot; <strong>{{ $summary['substitutes'] }}</strong> substitute(s) assigned</span></div>
    @endisset
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Leave Requests — pending first</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @isset($filters)
            <form method="GET" action="{{ route('leave.index') }}" class="form-inline mb-3 lv-filters" style="gap:6px; flex-wrap:wrap">
                <span class="lv-fld"><label for="lv_q">Search</label><input type="text" id="lv_q" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Search by staff name"></span>
                <span class="lv-fld"><label for="lv_status">Status</label><select id="lv_status" name="status" class="form-control form-control-sm">
                    <option value="">All Status</option>
                    <option value="pending" @if($filters['status'] === 'pending') selected @endif>Pending</option>
                    <option value="approved" @if($filters['status'] === 'approved') selected @endif>Approved</option>
                    <option value="rejected" @if($filters['status'] === 'rejected') selected @endif>Rejected</option>
                </select></span>
                <span class="lv-fld"><label for="lv_leave_type">Type</label><select id="lv_leave_type" name="leave_type" class="form-control form-control-sm">
                    <option value="">All Types</option>
                    <option value="normal" @if($filters['leave_type'] === 'normal') selected @endif>Normal</option>
                    <option value="emergency" @if($filters['leave_type'] === 'emergency') selected @endif>Emergency</option>
                </select></span>
                <span class="lv-fld"><label for="lv_coverage">Coverage</label><select id="lv_coverage" name="coverage" class="form-control form-control-sm">
                    <option value="">All Coverage</option>
                    <option value="covered" @if($filters['coverage'] === 'covered') selected @endif>Covered</option>
                    <option value="partial" @if($filters['coverage'] === 'partial') selected @endif>Partially Covered</option>
                    <option value="none" @if($filters['coverage'] === 'none') selected @endif>Not Covered</option>
                </select></span>
                <span class="lv-fld"><label for="lv_from">From</label><input type="date" id="lv_from" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm"></span>
                <span class="lv-fld"><label for="lv_to">To</label><input type="date" id="lv_to" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm"></span>
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="{{ route('leave.index') }}" class="btn btn-sm btn-light">Reset</a>
            </form>
            @endisset
            <div class="lv-req-list">
            @forelse($requests as $r)
                @php
                    $lvName = (string) (optional($r->user)->name ?? '');
                    $lvParts = preg_split('/\s+/', trim($lvName)) ?: [];
                    $lvInitials = strtoupper(mb_substr($lvParts[0] ?? '', 0, 1) . mb_substr(count($lvParts) > 1 ? (string) end($lvParts) : '', 0, 1));
                    $lvPartial = $r->partialLabel();
                    $lvWin = $lvPartial ? $r->periodWindow() : null;
                    $lvFrac = $lvWin ? ($lvWin[1] - $lvWin[0] + 1) / 8 : null;
                    $lvFracText = $lvFrac !== null ? rtrim(rtrim(number_format($lvFrac, 2), '0'), '.') : null;
                @endphp
                <div class="lv-req-card">
                    {{-- Headline: who + the state chips --}}
                    <div class="lv-req-head">
                        <div class="lv-who"><span class="lv-avatar">{{ $lvInitials !== '' ? $lvInitials : '?' }}</span><span class="lv-who-name">{{ $lvName }}</span><span class="lv-role-chip">{{ ucfirst(optional($r->user)->user_type) }}</span></div>
                        <div class="lv-req-chips">
                            <span class="badge badge-{{ $r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($r->status) }}</span>
                            @if($r->leave_type === 'emergency') <span class="badge badge-danger">Emergency</span> @endif
                            @if($lvPartial) <span class="badge badge-light">{{ $lvPartial }}</span> @endif
                        </div>
                    </div>
                    {{-- Meta: when, how long, what kind — one line --}}
                    <div class="lv-meta">
                        <span class="lv-nowrap">{{ $r->start_date_display }} &rarr; {{ $r->end_date_display }}</span>
                        <span aria-hidden="true">&middot;</span>
                        <span class="lv-days">@if($lvFracText !== null){{ $lvFracText }} day @else <b>{{ $r->days }}</b> {{ $r->days === 1 ? 'day' : 'days' }} @endif</span>
                        <span aria-hidden="true">&middot;</span>
                        <span class="lv-nowrap">{{ $r->leave_type === 'emergency' ? 'Emergency' : 'Normal' }}</span>
                    </div>
                    @if(!empty($r->leave_balance)) <div class="small text-muted lv-balance">Leave balance {{ $r->leave_balance['year'] }}: @if($r->leave_balance['quota'] > 0) {{ rtrim(rtrim(number_format($r->leave_balance['remaining'], 2), '0'), '.') }} of {{ rtrim(rtrim(number_format($r->leave_balance['quota'], 2), '0'), '.') }} days left @else {{ rtrim(rtrim(number_format($r->leave_balance['used'], 2), '0'), '.') }} days used (no quota set) @endif</div> @endif
                    @if(!empty($r->overlap_warning)) <div class="lv-overlap">{{ $r->overlap_warning }}</div> @endif
                    {{-- Reason: one line; the full text opens below --}}
                    <div class="lv-reason">@if(strlen((string) $r->reason) > 110) <div class="lv-reason-line" title="{{ $r->reason }}">{{ \Illuminate\Support\Str::limit((string) $r->reason, 110) }}</div> <details class="lv-reason-more"><summary>Full reason</summary><div class="mt-1">{{ $r->reason }}</div></details> @else {{ $r->reason }} @endif</div>
                    {{-- Allocation facts, behind one expander --}}
                    @if(!empty($r->allocation_lines) || !empty($r->week_grid))
                    <details class="lv-details">
                        <summary>Details</summary>
                        <div class="lv-details-body">
                            @if(!empty($r->allocation_summary)) <div class="lv-alloc-chips">@foreach(explode(' · ', $r->allocation_summary) as $lvFact)<span class="lv-alloc-chip">{{ $lvFact }}</span>@endforeach</div> @elseif(!empty($r->allocation_lines)) <div class="lv-alloc-chips"><span class="lv-alloc-chip">{{ \Illuminate\Support\Str::limit($r->allocation_lines[0], 60) }}</span></div> @endif
                            @if(!empty($r->allocation_lines) && (count($r->allocation_lines) > 1 || $r->allocation_lines[0] !== $r->allocation_summary)) <details class="lv-sub-details"><summary>Full allocation</summary><div class="mt-1">@foreach($r->allocation_lines as $line) {{ $line }} @if(!$loop->last) <br> @endif @endforeach</div></details> @endif
                            @if(!empty($r->week_grid)) <details class="lv-sub-details"><summary>Week timetable ({{ $r->weekly_periods }} periods)</summary><div class="table-responsive lv-plan-wrap mt-1"><table class="table table-sm lv-week-table"><colgroup><col style="width:15%"><col style="width:9%"><col style="width:22%"><col style="width:15%"><col style="width:25%"><col style="width:14%"></colgroup><thead><tr><th>Day</th><th>Period</th><th>Class</th><th>Section</th><th>Subject</th><th>Room</th></tr></thead><tbody>@foreach($r->week_grid as $g)<tr><td class="lv-week-nowrap">{{ $g['day'] }}</td><td class="lv-week-nowrap">{{ $g['period'] ? 'P' . $g['period'] : '—' }}</td><td>{{ $g['class'] }}</td><td>{{ $g['section'] }}</td><td>{{ $g['subject'] }}</td><td>{{ $g['room'] }}</td></tr>@endforeach</tbody></table></div></details> @endif
                        </div>
                    </details>
                    @endif
                    {{-- Cover: one clear state chip + the plan behind its toggle --}}
                    @if($r->uncovered_periods !== null)
                    <div class="lv-cover-row">
                        @if($r->status === 'pending' && !empty($r->planned_periods))
                            @if($r->uncovered_periods > 0) <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a> @else <a href="{{ route('substitutions.index') }}" class="badge badge-success">All {{ $r->planned_periods }} periods covered &middot; Plan ready</a> @endif
                        @elseif($r->uncovered_periods > 0)
                            <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a>
                        @else
                            <span class="badge badge-success">Covered</span>
                        @endif
                        @if($r->coverage_state === 'partial') <span class="badge badge-warning">@if(!empty($r->impact)) Partially covered — {{ $r->impact['covered'] }} of {{ $r->impact['affected'] }} @else Partially covered @endif</span> @elseif($r->coverage_state === 'none') <span class="badge badge-danger">Not covered</span> @elseif($r->coverage_state === 'none_affected') <span class="badge badge-light">No teaching periods affected</span> @endif
                    </div>
                    @if(($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) || !empty($r->cover_names) || !empty($r->impact))
                    <details class="lv-plan-details">
                        <summary>@if($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) See the plan ({{ $r->plan_rows->count() }}) @elseif(!empty($r->cover_names)) Cover details @else Impact details @endif</summary>
                        @if($r->status === 'pending' && !empty($r->planned_periods)) <div class="small text-muted mt-1">@if($r->uncovered_periods > 0) Plan ready for {{ $r->planned_periods }} period(s) — approving this leave makes the plan live. @else The system has arranged all {{ $r->planned_periods }} period(s) — approving this leave makes the plan live. @endif</div> @endif
                        @if(!empty($r->cover_names)) <div class="small text-muted mt-1">Covered by: {{ implode(', ', $r->cover_names) }}</div> @endif
                        @if(!empty($r->impact)) <div class="small mt-1"><strong>Leave Impact Analysis</strong><br>Working days: {{ $r->impact['working_days'] }} &middot; Affected classes: {{ $r->impact['classes'] }} &middot; Sections: {{ $r->impact['sections'] }} &middot; Teaching periods: {{ $r->impact['affected'] }} &middot; Periods covered: {{ $r->impact['covered'] }} &middot; Requiring coverage: {{ $r->impact['uncovered'] }}@if($r->impact['labs']) &middot; Lab sessions: {{ $r->impact['labs'] }} @endif @if(!empty($r->impact['subjects']))<br>Subjects: {{ implode(', ', $r->impact['subjects']) }} @endif</div> @endif
                        @if($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) <div class="table-responsive lv-plan-wrap mt-1"><table class="table table-sm lv-plan-table"><colgroup><col style="width:26%"><col style="width:28%"><col style="width:18%"><col style="width:28%"></colgroup><thead><tr><th>Date</th><th>Period</th><th>Substitute</th><th>Switch</th></tr></thead><tbody>@foreach($r->plan_rows as $pr)<tr><td><span class="lv-plan-when">{{ $pr->date ? $pr->date->format('D, d/m/Y') : '—' }}@if(optional(optional($pr->time_table)->time_slot)->full) <span class="text-muted">&middot; {{ $pr->time_table->time_slot->full }}</span>@endif</span></td><td><span class="lv-plan-class">{{ optional(optional(optional($pr->time_table)->tt_record)->my_class)->name ?: '—' }}@if(optional(optional($pr->time_table)->tt_record)->section) — {{ $pr->time_table->tt_record->section->name }}@endif</span><br><span class="lv-plan-subj text-muted">{{ optional(optional($pr->time_table)->subject)->name }}</span></td><td><span class="lv-plan-subname">{{ optional($pr->substitute)->name ?: '—' }}</span></td><td>@php $opts = $r->plan_free_options[$pr->id] ?? collect(); @endphp @if($opts->isEmpty()) <span class="text-muted">No other teacher is free at this time</span> @else <form method="POST" action="{{ route('substitutions.switch', $pr->id) }}" class="form-inline">@csrf<select name="substitute_teacher_id" class="form-control form-control-sm mr-1">@foreach($opts as $opt)<option value="{{ $opt->id }}">{{ $opt->name }} — free</option>@endforeach</select><button type="submit" class="btn btn-sm btn-secondary">Switch</button></form> @endif</td></tr>@endforeach</tbody></table></div> @endif
                    </details>
                    @endif
                    @endif
                    {{-- Decision: pending gets the form on its own row;
                         decided cards close with one muted line. --}}
                    @if($r->status === 'pending')
                        @if((int) $r->user_id !== (int) auth()->id())
                            <form method="POST" action="{{ route('leave.decide', $r->id) }}" class="form-inline lv-req-decide">
                                @csrf
                                <input type="text" name="decision_note" class="form-control form-control-sm mr-1" placeholder="Note (optional)">
                                <button type="submit" name="status" value="approved" class="btn btn-success btn-sm mr-1">Approve</button>
                                <button type="submit" name="status" value="rejected" class="btn btn-danger btn-sm">Reject</button>
                            </form>
                        @else
                            <div class="lv-req-own text-muted">Your own request — another admin must decide it.</div>
                        @endif
                    @else
                        <div class="lv-decided-line text-muted">{{ ucfirst($r->status) }}@if(optional($r->decidedBy)->name) by {{ $r->decidedBy->name }}@endif @if($r->decided_at) &middot; {{ $r->decided_at->format('d/m/Y') }} @endif @if($r->decision_note) — {{ $r->decision_note }} @endif</div>
                    @endif
                </div>
            @empty
                @include('partials.patterns.empty_state', ['icon' => 'icon-calendar2', 'message' => 'No leave requests yet.', 'hkEmptyArt' => true])
            @endforelse
            </div>
            {{ $requests->appends(request()->except('page'))->links() }}
        </div>
    </div>
@endsection
