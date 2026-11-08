@extends('layouts.master')
@section('page_title', 'Leave Requests')
@section('content')
    {{-- Leave queue (owner, 2026-11-06, after the "text wall"
         rejection): the request list was one-glance CARDS — who,
         (Split, owner 2026-11-07 late: BOTH queues are now proper
         hk-std TABLES — the teacher's student queue first, then the
         main admin/principal queue on the owner's scope correction
         the same night. Same blade, two table branches split on
         the summary check; the card markup is retired.)
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

        .lv-alloc-chips { display: flex; flex-wrap: wrap; gap: 6px; }

        /* Detail PANEL row (owner redesign, 2026-11-08 — the
           screenshots of 09:29–09:30 IST): everything that used
           to expand INSIDE the narrow Actions/Reason cell — the
           allocation, the week grid, the cover plan, crushed to
           ~330px with colliding headers — now opens in a
           full-width row directly under its request row, so every
           table inside gets the whole sheet width. The Actions
           cell carries only the small toggle links; the decision
           form sits at the panel's foot, full width. */
        .lv-panel-toggle { display: block; background: none; border: none; padding: 0; margin: 0 0 5px; color: #3478f6; font-size: 12.5px; cursor: pointer; text-align: left; }
        .lv-panel-toggle:last-child { margin-bottom: 0; }
        .lv-panel-toggle .lv-caret { display: inline-block; font-size: 10px; margin-right: 2px; }
        .lv-panel-row > td { background: rgba(128, 148, 171, .07); padding: 14px 16px 16px; }
        html.hk-dark .lv-panel-row > td { background: rgba(255, 255, 255, .03); }
        .lv-panel-sec + .lv-panel-sec { margin-top: 16px; }
        .lv-panel-h { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #5b7a99; margin: 0 0 7px; }
        html.hk-dark .lv-panel-h { color: #93a7bd; }
        .lv-panel-decide-sec { border-top: 1px dashed rgba(128, 148, 171, .4); padding-top: 12px; }
        .lv-panel-sec .lv-req-decide { margin-top: 0; }
        .lv-alloc-table { table-layout: fixed; margin-bottom: 0; }
        .lv-alloc-table thead th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; padding: 6px 8px; border-top: none; white-space: nowrap; }
        .lv-alloc-table tbody td { padding: 6px 8px; vertical-align: top; line-height: 1.45; }
        .lv-alloc-chip { display: inline-block; font-size: 12px; font-weight: 600; color: #33506e; background: rgba(52, 120, 246, .10); border: 1px solid rgba(52, 120, 246, .25); border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
        html.hk-dark .lv-alloc-chip { color: #a9c6ea; background: rgba(52, 120, 246, .16); }

        /* Cover row (coverage pills line inside a panel) */
        .lv-cover-row { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-top: 10px; }
        .lv-cover-row .badge { white-space: nowrap; }

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

        /* Student queue table (owner, 2026-11-07 late): the
           teacher's student-requests surface renders as a proper
           hk-std table — one row per request, the decision form
           compact inside its cell. The staff queue keeps the cards
           above; these rules serve the table branch only. */
        .lv-sq-table { min-width: 900px; }
        .lv-sq-table .lv-decided-line { margin-top: 0; text-align: left; }
        .lv-sq-student { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .lv-sq-decide { display: flex; flex-direction: column; gap: 6px; }
        .lv-sq-decide input { width: 100%; margin: 0 !important; }
        .lv-sq-decide-btns { display: flex; gap: 6px; }
        .lv-sq-decide-btns button { flex: 1 1 0; margin: 0 !important; }

        /* Main queue table (owner scope correction, 2026-11-07
           late): same hk-std family, wider sheet for its 8 columns;
           coverage pills stack in their cell; the decision form in
           the Actions cell clears the expanders above it. */
        .lv-mq-table { min-width: 1080px; }
        .lv-mq-cover { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }

        @media (max-width: 991.98px) {
            /* Phone (panel redesign, 2026-11-08): the panel
               tables stay TABLES — each sits in its
               .table-responsive wrap and scrolls INSIDE it at a
               readable min-width, instead of the old stacked-block
               transformation (whose fixed proportions were what
               collided in the narrow cell in the first place). */
            .lv-panel-row > td { padding: 12px 10px 14px; }
            .lv-plan-wrap { overflow-x: auto; }
            .lv-plan-table { min-width: 680px; }
            .lv-week-table { min-width: 620px; }
            .lv-alloc-table { min-width: 540px; }
        }
        @media (max-width: 575.98px) {
            .lv-req-decide input { flex: 1 1 100%; }
            .lv-req-decide button { flex: 1 1 0; }
            .lv-decided-line, .lv-req-own { text-align: left; }
        }
    </style>
    {{-- Slim module banner (Beauty Wave 1 pattern). Beauty
         Batch 6 (audit E3): the banner no longer repeats the
         counts — the stat tiles and the coverage strip below
         carry every figure once. The teacher's student-requests
         view reuses this blade without a summary. --}}
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-calendar2',
        'hkModTone' => 'orange',
        'hkModSub' => isset($summary) ? 'Decide requests, see the cover plan and spot uncovered periods at a glance.' : 'Student leave requests from your sections — you decide these.',
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
            <h6 class="card-title">Requests — pending first</h6>
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
            @if(!isset($summary))
            {{-- STUDENT QUEUE TABLE (owner, 2026-11-07 late: "yha ve
                 tables bne toh mja aajaehga"). studentRequests()
                 renders this blade WITHOUT a summary — that is the
                 split: the teacher's student surface is a proper
                 hk-std table, one row per request; the STAFF queue
                 (admin/principal, WITH summary) in the else branch
                 below is a table too (owner scope correction,
                 2026-11-07 late).
                 Everything behavioural is shared and unchanged: the
                 controllers' pending-first ordering, the decide POST
                 contract (decision_note + status buttons), the
                 applicant bell inside decide(), and every expander
                 block — full reason, allocation, week grid, cover —
                 opens in a full-width panel row under the request
                 row (panel redesign, 2026-11-08). --}}
            @if($requests->isEmpty())
                @include('partials.patterns.empty_state', ['icon' => 'icon-calendar2', 'message' => 'No leave requests yet.', 'hkEmptyArt' => true])
            @else
            <div class="table-responsive">
                <table class="table hk-std lv-sq-table">
                    <colgroup><col style="width:16%"><col style="width:11%"><col style="width:14%"><col style="width:6%"><col style="width:10%"><col style="width:18%"><col style="width:8%"><col style="width:17%"></colgroup>
                    <thead><tr><th>Student</th><th>Class – Section</th><th>Dates</th><th>Days</th><th>Type</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead>
                    <tbody>
                    @foreach($requests as $r)
                        @php
                            $lvName = (string) (optional($r->user)->name ?? '');
                            $lvParts = preg_split('/\s+/', trim($lvName)) ?: [];
                            $lvInitials = strtoupper(mb_substr($lvParts[0] ?? '', 0, 1) . mb_substr(count($lvParts) > 1 ? (string) end($lvParts) : '', 0, 1));
                            $lvPartial = $r->partialLabel();
                            $lvWin = $lvPartial ? $r->periodWindow() : null;
                            $lvFrac = $lvWin ? ($lvWin[1] - $lvWin[0] + 1) / 8 : null;
                            $lvFracText = $lvFrac !== null ? rtrim(rtrim(number_format($lvFrac, 2), '0'), '.') : null;
                            $lvHasDetails = !empty($r->allocation_lines) || !empty($r->allocation_rows) || !empty($r->week_grid) || strlen((string) $r->reason) > 110 || !empty($r->leave_balance) || !empty($r->overlap_warning);
                            $lvHasPlan = $r->uncovered_periods !== null && (($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) || !empty($r->cover_names) || !empty($r->impact));
                            $lvPlanLabel = ($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) ? 'See the plan (' . $r->plan_rows->count() . ')' : (!empty($r->cover_names) ? 'Cover details' : 'Impact details');
                        @endphp
                        <tr>
                            <td><span class="lv-sq-student"><span class="lv-avatar">{{ $lvInitials !== '' ? $lvInitials : '?' }}</span><span class="lv-who-name">{{ $lvName }}</span></span></td>
                            <td class="hk-nw">{{ $r->allocation_summary ?: '—' }}</td>
                            <td class="hk-nw">{{ $r->start_date_display }} – {{ $r->end_date_display }}</td>
                            <td class="hk-nw">@if($lvFracText !== null){{ $lvFracText }} day @else <b>{{ $r->days }}</b> {{ $r->days === 1 ? 'day' : 'days' }} @endif</td>
                            <td>@if($r->leave_type === 'emergency') <span class="badge badge-danger">Emergency</span> @else <span class="hk-nw">Normal</span> @endif @if($lvPartial) <div class="mt-1"><span class="badge badge-light">{{ $lvPartial }}</span></div> @endif</td>
                            <td>
                                <div class="hk-ellip" title="{{ $r->reason }}">{{ $r->reason }}</div>
                                @if($lvHasDetails || $lvHasPlan)
                                <button type="button" class="lv-panel-toggle" data-lv-toggle="lv-panel-{{ $r->id }}" aria-expanded="false"><span class="lv-caret">▸</span>Details</button>
                                @endif
                            </td>
                            <td><span class="badge badge-{{ $r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($r->status) }}</span></td>
                            <td>
                                @if($r->status === 'pending')
                                    @if((int) $r->user_id !== (int) auth()->id())
                                        <form method="POST" action="{{ route('leave.decide', $r->id) }}" class="lv-sq-decide" data-hk-draft="u{{ Auth::id() }}-leave-decide-{{ $r->id }}">
                                            @csrf
                                            <input type="text" name="decision_note" class="form-control form-control-sm" placeholder="Note (optional)">
                                            <span class="lv-sq-decide-btns"><button type="submit" name="status" value="approved" class="btn btn-success btn-sm">Approve</button><button type="submit" name="status" value="rejected" class="btn btn-danger btn-sm">Reject</button></span>
                                        </form>
                                    @else
                                        <div class="text-muted">Your own request — another admin must decide it.</div>
                                    @endif
                                @else
                                    <div class="lv-decided-line text-muted">{{ ucfirst($r->status) }}@if(optional($r->decidedBy)->name) by {{ $r->decidedBy->name }}@endif @if($r->decided_at) &middot; {{ $r->decided_at->format('d/m/Y') }} @endif @if($r->decision_note) — {{ $r->decision_note }} @endif</div>
                                @endif
                            </td>
                        </tr>
                        @if($lvHasDetails || $lvHasPlan)
                        <tr class="lv-panel-row" id="lv-panel-{{ $r->id }}" hidden>
                            <td colspan="8" class="lv-panel-cell">
                                    <div class="lv-panel-sec">
                                        <div class="lv-panel-h">Details</div>
                                        @if(strlen((string) $r->reason) > 110) <div class="lv-reason">{{ $r->reason }}</div> @endif
                                        @if(!empty($r->leave_balance)) <div class="small text-muted lv-balance">Leave balance {{ $r->leave_balance['year'] }}: @if($r->leave_balance['quota'] > 0) {{ rtrim(rtrim(number_format($r->leave_balance['remaining'], 2), '0'), '.') }} of {{ rtrim(rtrim(number_format($r->leave_balance['quota'], 2), '0'), '.') }} days left @else {{ rtrim(rtrim(number_format($r->leave_balance['used'], 2), '0'), '.') }} days used (no quota set) @endif</div> @endif
                                        @if(!empty($r->overlap_warning)) <div class="lv-overlap">{{ $r->overlap_warning }}</div> @endif
                                        @if(!empty($r->allocation_summary)) <div class="lv-alloc-chips">@foreach(explode(' · ', $r->allocation_summary) as $lvFact)<span class="lv-alloc-chip">{{ $lvFact }}</span>@endforeach</div> @elseif(!empty($r->allocation_lines)) <div class="lv-alloc-chips"><span class="lv-alloc-chip">{{ \Illuminate\Support\Str::limit($r->allocation_lines[0], 60) }}</span></div> @endif
                                        @if(!empty($r->allocation_rows))
                                        <div class="table-responsive lv-plan-wrap mt-2">
                                            <table class="table table-sm lv-alloc-table">
                                                <colgroup><col style="width:18%"><col style="width:28%"><col style="width:30%"><col style="width:24%"></colgroup>
                                                <thead><tr><th>Class</th><th>Sections</th><th>Subject</th><th>Role</th></tr></thead>
                                                <tbody>@foreach($r->allocation_rows as $lvRow)<tr><td>{{ $lvRow['class'] }}</td><td>{{ $lvRow['sections'] }}</td><td>{{ $lvRow['subject'] }}</td><td>{{ $lvRow['role'] }}</td></tr>@endforeach</tbody>
                                            </table>
                                        </div>
                                        @endif
                                        @if(!empty($r->week_grid))
                                        <div class="lv-panel-h mt-3">Week timetable ({{ $r->weekly_periods }} periods)</div>
                                        <div class="table-responsive lv-plan-wrap">
                                            <table class="table table-sm lv-week-table">
                                                <colgroup><col style="width:15%"><col style="width:9%"><col style="width:22%"><col style="width:15%"><col style="width:25%"><col style="width:14%"></colgroup>
                                                <thead><tr><th>Day</th><th>Period</th><th>Class</th><th>Section</th><th>Subject</th><th>Room</th></tr></thead>
                                                <tbody>@foreach($r->week_grid as $g)<tr><td class="lv-week-nowrap">{{ $g['day'] }}</td><td class="lv-week-nowrap">{{ $g['period'] ? 'P' . $g['period'] : '—' }}</td><td>{{ $g['class'] }}</td><td>{{ $g['section'] }}</td><td>{{ $g['subject'] }}</td><td>{{ $g['room'] }}</td></tr>@endforeach</tbody>
                                            </table>
                                        </div>
                                        @endif
                                    </div>
                            </td>
                        </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
            @else
            {{-- MAIN QUEUE TABLE (owner scope correction, 2026-11-07
                 late: "yha ve toh mai proper table type saab bola
                 tha") — the admin/principal queue is a proper hk-std
                 table too, not just the student surface. The filters,
                 summary tiles and coverage banner above are untouched,
                 the pending-first ordering comes from the controller,
                 and every per-request block — balance, overlap,
                 allocation, week grid, cover pills, the plan with its
                 switch forms, the decision form, the decided line —
                 rides along: the heavy blocks open in a full-width
                 panel row under the request row (panel redesign,
                 2026-11-08), on the same decide/switch endpoints;
                 the applicant bell still fires inside decide(). --}}
            @if($requests->isEmpty())
                @include('partials.patterns.empty_state', ['icon' => 'icon-calendar2', 'message' => 'No leave requests yet.', 'hkEmptyArt' => true])
            @else
            <div class="table-responsive">
                <table class="table hk-std lv-sq-table lv-mq-table">
                    <colgroup><col style="width:15%"><col style="width:13%"><col style="width:5%"><col style="width:9%"><col style="width:14%"><col style="width:12%"><col style="width:10%"><col style="width:22%"></colgroup>
                    <thead><tr><th>Applicant</th><th>Dates</th><th>Days</th><th>Type</th><th>Reason</th><th>Coverage</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @foreach($requests as $r)
                        @php
                            $lvName = (string) (optional($r->user)->name ?? '');
                            $lvParts = preg_split('/\s+/', trim($lvName)) ?: [];
                            $lvInitials = strtoupper(mb_substr($lvParts[0] ?? '', 0, 1) . mb_substr(count($lvParts) > 1 ? (string) end($lvParts) : '', 0, 1));
                            $lvPartial = $r->partialLabel();
                            $lvWin = $lvPartial ? $r->periodWindow() : null;
                            $lvFrac = $lvWin ? ($lvWin[1] - $lvWin[0] + 1) / 8 : null;
                            $lvFracText = $lvFrac !== null ? rtrim(rtrim(number_format($lvFrac, 2), '0'), '.') : null;
                            $lvHasDetails = !empty($r->allocation_lines) || !empty($r->allocation_rows) || !empty($r->week_grid) || strlen((string) $r->reason) > 110 || !empty($r->leave_balance) || !empty($r->overlap_warning);
                            $lvHasPlan = $r->uncovered_periods !== null && (($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) || !empty($r->cover_names) || !empty($r->impact));
                            $lvPlanLabel = ($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) ? 'See the plan (' . $r->plan_rows->count() . ')' : (!empty($r->cover_names) ? 'Cover details' : 'Impact details');
                        @endphp
                        <tr>
                            <td><span class="lv-sq-student"><span class="lv-avatar">{{ $lvInitials !== '' ? $lvInitials : '?' }}</span><span><span class="lv-who-name">{{ $lvName }}</span> <span class="lv-role-chip">{{ ucfirst(optional($r->user)->user_type) }}</span></span></span></td>
                            <td class="hk-nw">{{ $r->start_date_display }} – {{ $r->end_date_display }}</td>
                            <td class="hk-nw">@if($lvFracText !== null){{ $lvFracText }} day @else <b>{{ $r->days }}</b> {{ $r->days === 1 ? 'day' : 'days' }} @endif</td>
                            <td>@if($r->leave_type === 'emergency') <span class="badge badge-danger">Emergency</span> @else <span class="hk-nw">Normal</span> @endif @if($lvPartial) <div class="mt-1"><span class="badge badge-light">{{ $lvPartial }}</span></div> @endif</td>
                            <td><div class="hk-ellip" title="{{ $r->reason }}">{{ $r->reason }}</div></td>
                            <td>
                                @if($r->uncovered_periods !== null)
                                <div class="lv-mq-cover">
                                    @if($r->status === 'pending' && !empty($r->planned_periods))
                                        @if($r->uncovered_periods > 0) <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a> @else <a href="{{ route('substitutions.index') }}" class="badge badge-success">All {{ $r->planned_periods }} periods covered &middot; Plan ready</a> @endif
                                    @elseif($r->uncovered_periods > 0)
                                        <a href="{{ route('substitutions.index') }}" class="badge badge-warning">{{ $r->uncovered_periods }} without cover</a>
                                    @else
                                        <span class="badge badge-success">Covered</span>
                                    @endif
                                    @if($r->coverage_state === 'partial') <span class="badge badge-warning">@if(!empty($r->impact)) Partially covered — {{ $r->impact['covered'] }} of {{ $r->impact['affected'] }} @else Partially covered @endif</span> @elseif($r->coverage_state === 'none') <span class="badge badge-danger">Not covered</span> @elseif($r->coverage_state === 'none_affected') <span class="badge badge-light">No teaching periods affected</span> @endif
                                </div>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-{{ $r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($r->status) }}</span>
                                @if($r->status !== 'pending') <div class="lv-decided-line text-muted">{{ ucfirst($r->status) }}@if(optional($r->decidedBy)->name) by {{ $r->decidedBy->name }}@endif @if($r->decided_at) &middot; {{ $r->decided_at->format('d/m/Y') }} @endif @if($r->decision_note) — {{ $r->decision_note }} @endif</div> @endif
                            </td>
                            <td>
                                @if($lvHasDetails)
                                <button type="button" class="lv-panel-toggle" data-lv-toggle="lv-panel-{{ $r->id }}" aria-expanded="false"><span class="lv-caret">▸</span>Details</button>
                                @endif
                                @if($lvHasPlan)
                                <button type="button" class="lv-panel-toggle" data-lv-toggle="lv-panel-{{ $r->id }}" aria-expanded="false"><span class="lv-caret">▸</span>{{ $lvPlanLabel }}</button>
                                @endif
                                @if(!$lvHasDetails && !$lvHasPlan && $r->status === 'pending')
                                <button type="button" class="lv-panel-toggle" data-lv-toggle="lv-panel-{{ $r->id }}" aria-expanded="false"><span class="lv-caret">▸</span>Decide</button>
                                @endif
                            </td>
                        </tr>
                        @if($lvHasDetails || $lvHasPlan || $r->status === 'pending')
                        <tr class="lv-panel-row" id="lv-panel-{{ $r->id }}" hidden>
                            <td colspan="8" class="lv-panel-cell">
                                    @if($lvHasDetails)
                                    <div class="lv-panel-sec">
                                        <div class="lv-panel-h">Details</div>
                                        @if(strlen((string) $r->reason) > 110) <div class="lv-reason">{{ $r->reason }}</div> @endif
                                        @if(!empty($r->leave_balance)) <div class="small text-muted lv-balance">Leave balance {{ $r->leave_balance['year'] }}: @if($r->leave_balance['quota'] > 0) {{ rtrim(rtrim(number_format($r->leave_balance['remaining'], 2), '0'), '.') }} of {{ rtrim(rtrim(number_format($r->leave_balance['quota'], 2), '0'), '.') }} days left @else {{ rtrim(rtrim(number_format($r->leave_balance['used'], 2), '0'), '.') }} days used (no quota set) @endif</div> @endif
                                        @if(!empty($r->overlap_warning)) <div class="lv-overlap">{{ $r->overlap_warning }}</div> @endif
                                        @if(!empty($r->allocation_summary)) <div class="lv-alloc-chips">@foreach(explode(' · ', $r->allocation_summary) as $lvFact)<span class="lv-alloc-chip">{{ $lvFact }}</span>@endforeach</div> @elseif(!empty($r->allocation_lines)) <div class="lv-alloc-chips"><span class="lv-alloc-chip">{{ \Illuminate\Support\Str::limit($r->allocation_lines[0], 60) }}</span></div> @endif
                                        @if(!empty($r->allocation_rows))
                                        <div class="table-responsive lv-plan-wrap mt-2">
                                            <table class="table table-sm lv-alloc-table">
                                                <colgroup><col style="width:18%"><col style="width:28%"><col style="width:30%"><col style="width:24%"></colgroup>
                                                <thead><tr><th>Class</th><th>Sections</th><th>Subject</th><th>Role</th></tr></thead>
                                                <tbody>@foreach($r->allocation_rows as $lvRow)<tr><td>{{ $lvRow['class'] }}</td><td>{{ $lvRow['sections'] }}</td><td>{{ $lvRow['subject'] }}</td><td>{{ $lvRow['role'] }}</td></tr>@endforeach</tbody>
                                            </table>
                                        </div>
                                        @endif
                                        @if(!empty($r->week_grid))
                                        <div class="lv-panel-h mt-3">Week timetable ({{ $r->weekly_periods }} periods)</div>
                                        <div class="table-responsive lv-plan-wrap">
                                            <table class="table table-sm lv-week-table">
                                                <colgroup><col style="width:15%"><col style="width:9%"><col style="width:22%"><col style="width:15%"><col style="width:25%"><col style="width:14%"></colgroup>
                                                <thead><tr><th>Day</th><th>Period</th><th>Class</th><th>Section</th><th>Subject</th><th>Room</th></tr></thead>
                                                <tbody>@foreach($r->week_grid as $g)<tr><td class="lv-week-nowrap">{{ $g['day'] }}</td><td class="lv-week-nowrap">{{ $g['period'] ? 'P' . $g['period'] : '—' }}</td><td>{{ $g['class'] }}</td><td>{{ $g['section'] }}</td><td>{{ $g['subject'] }}</td><td>{{ $g['room'] }}</td></tr>@endforeach</tbody>
                                            </table>
                                        </div>
                                        @endif
                                    </div>
                                    @endif
                                    @if($lvHasPlan)
                                    <div class="lv-panel-sec">
                                        <div class="lv-panel-h">@if($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty()) Cover plan @else {{ $lvPlanLabel }} @endif</div>
                                        @if($r->status === 'pending' && !empty($r->planned_periods)) <div class="small text-muted">@if($r->uncovered_periods > 0) Plan ready for {{ $r->planned_periods }} period(s) — approving this leave makes the plan live. @else The system has arranged all {{ $r->planned_periods }} period(s) — approving this leave makes the plan live. @endif</div> @endif
                                        @if(!empty($r->cover_names)) <div class="small text-muted mt-1">Covered by: {{ implode(', ', $r->cover_names) }}</div> @endif
                                        @if(!empty($r->impact)) <div class="small mt-1"><strong>Leave Impact Analysis</strong><br>Working days: {{ $r->impact['working_days'] }} &middot; Affected classes: {{ $r->impact['classes'] }} &middot; Sections: {{ $r->impact['sections'] }} &middot; Teaching periods: {{ $r->impact['affected'] }} &middot; Periods covered: {{ $r->impact['covered'] }} &middot; Requiring coverage: {{ $r->impact['uncovered'] }}@if($r->impact['labs']) &middot; Lab sessions: {{ $r->impact['labs'] }} @endif @if(!empty($r->impact['subjects']))<br>Subjects: {{ implode(', ', $r->impact['subjects']) }} @endif</div> @endif
                                        @if($r->status === 'pending' && !empty($r->plan_rows) && $r->plan_rows->isNotEmpty())
                                        <div class="table-responsive lv-plan-wrap mt-2">
                                            <table class="table table-sm lv-plan-table">
                                                <colgroup><col style="width:17%"><col style="width:8%"><col style="width:15%"><col style="width:15%"><col style="width:19%"><col style="width:26%"></colgroup>
                                                <thead><tr><th>Date</th><th>Period</th><th>Class – Section</th><th>Subject</th><th>Substitute</th><th>Switch</th></tr></thead>
                                                <tbody>
                                                @foreach($r->plan_rows as $pr)
                                                    <tr>
                                                        <td><span class="lv-plan-when">{{ $pr->date ? $pr->date->format('D, d/m/Y') : '—' }}</span>@if(optional(optional($pr->time_table)->time_slot)->full)<br><span class="text-muted lv-plan-when">{{ $pr->time_table->time_slot->full }}</span>@endif</td>
                                                        <td class="hk-nw">{{ $pr->period_no ? 'P' . $pr->period_no : '—' }}</td>
                                                        <td><span class="lv-plan-class">{{ optional(optional(optional($pr->time_table)->tt_record)->my_class)->name ?: '—' }}@if(optional(optional($pr->time_table)->tt_record)->section) — {{ $pr->time_table->tt_record->section->name }}@endif</span></td>
                                                        <td>{{ optional(optional($pr->time_table)->subject)->name ?: '—' }}</td>
                                                        <td><span class="lv-plan-subname">{{ optional($pr->substitute)->name ?: '—' }}</span></td>
                                                        <td>@php $opts = $r->plan_free_options[$pr->id] ?? collect(); @endphp @if($opts->isEmpty()) <span class="text-muted">No other teacher is free at this time</span> @else <form method="POST" action="{{ route('substitutions.switch', $pr->id) }}" class="form-inline">@csrf<select name="substitute_teacher_id" class="form-control form-control-sm mr-1">@foreach($opts as $opt)<option value="{{ $opt->id }}">{{ $opt->name }} — free</option>@endforeach</select><button type="submit" class="btn btn-sm btn-secondary">Switch</button></form> @endif</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        @endif
                                    </div>
                                    @endif
                                    @if($r->status === 'pending')
                                    <div class="lv-panel-sec lv-panel-decide-sec">
                                        @if((int) $r->user_id !== (int) auth()->id())
                                            <form method="POST" action="{{ route('leave.decide', $r->id) }}" class="lv-req-decide" data-hk-draft="u{{ Auth::id() }}-leave-decide-{{ $r->id }}">
                                                @csrf
                                                <input type="text" name="decision_note" class="form-control form-control-sm" placeholder="Note (optional)">
                                                <button type="submit" name="status" value="approved" class="btn btn-success btn-sm">Approve</button>
                                                <button type="submit" name="status" value="rejected" class="btn btn-danger btn-sm">Reject</button>
                                            </form>
                                        @else
                                            <div class="text-muted">Your own request — another admin must decide it.</div>
                                        @endif
                                    </div>
                                    @endif
                            </td>
                        </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
            @endif
{{ $requests->appends(request()->except('page'))->links() }}
        </div>
    </div>
    <script>
    // Leave queue detail panels (2026-11-08): the toggles in a
    // request row open/close the full-width panel row under it;
    // every toggle pointing at one panel stays in sync.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-lv-toggle]');
        if (!btn) { return; }
        var row = document.getElementById(btn.getAttribute('data-lv-toggle'));
        if (!row) { return; }
        var open = row.hidden;
        row.hidden = !open;
        document.querySelectorAll('[data-lv-toggle="' + btn.getAttribute('data-lv-toggle') + '"]').forEach(function (b) {
            b.setAttribute('aria-expanded', open ? 'true' : 'false');
            var caret = b.querySelector('.lv-caret');
            if (caret) { caret.textContent = open ? '▾' : '▸'; }
        });
    });
    </script>
@endsection
