@extends('layouts.master')
@section('page_title', 'Substitutions')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Substitutions
@endsection
@section('content')
    {{-- Board tables (owner, 2026-11-06): a sheet must FORM as a
         table — fixed rational proportions, short fields (time,
         status) on one line, real width for names/subjects, even
         row padding, headers aligned to their columns. On narrow
         screens the .table-responsive wrapper scrolls the sheet
         sideways instead of crushing columns into vertical text. --}}
    <style>
        .sub-table { table-layout: fixed; margin-bottom: 0; }
        .sub-table thead th { font-weight: 600; letter-spacing: .02em; padding: 8px 10px; white-space: nowrap; }
        .sub-table tbody td { padding: 8px 10px; vertical-align: middle; line-height: 1.45; overflow-wrap: break-word; }
        .sub-nowrap { white-space: nowrap; }
        .sub-sheet-table { min-width: 920px; }
        .sub-plan-table { min-width: 640px; }
        @media (max-width: 767.98px) {
            /* The group badge's long sentence wraps inside the
               card on phones instead of clipping at its edge. */
            .sub-group-badge { white-space: normal; text-align: left; line-height: 1.5; }
        }
    </style>
    @if(session('flash_success'))
        <div class="alert alert-success">{{ session('flash_success') }}</div>
    @endif
    @if(session('flash_danger'))
        <div class="alert alert-danger">{{ session('flash_danger') }}</div>
    @endif

    @if(!empty($pending_groups) && $pending_groups->isNotEmpty())
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">System-arranged plans — record</h5>
                <p class="mb-0">The system prepared this cover automatically from a leave request. This page is only the record — manage the plan from <a href="{{ route('leave.index') }}" class="alert-link">Leave Requests</a>: switch a teacher there, and approving the leave makes the plan live (substitutes and students are notified automatically).</p>
            </div>
            <div class="card-body">
                @foreach($pending_groups as $groupKey => $rows)
                    @php $first = $rows->first(); @endphp
                    <h6 class="mb-2">
                        {{ optional($first->absent_teacher)->name }} — {{ $first->date ? $first->date->format('D, d M Y') : '' }}
                        <span class="badge badge-warning sub-group-badge">{{ $rows->count() }} period(s) · Planned — goes live when the leave is approved</span>
                    </h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm sub-table sub-plan-table hk-std hk-std-subplan">
                            <colgroup><col style="width:24%"><col style="width:24%"><col style="width:22%"><col style="width:30%"></colgroup>
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Substitute (system's pick)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr>
                                        <td data-label="Period" class="sub-nowrap">{{ optional(optional($row->time_table)->time_slot)->full ?: '—' }}</td>
                                        <td data-label="Class">
                                            {{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}
                                            @if(optional(optional($row->time_table)->tt_record)->section)
                                                — {{ $row->time_table->tt_record->section->name }}
                                            @else
                                                — All sections
                                            @endif
                                        </td>
                                        <td data-label="Subject">{{ optional(optional($row->time_table)->subject)->name ?: '—' }}</td>
                                        <td data-label="Substitute"><strong>{{ optional($row->substitute)->name ?: '—' }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Owner order 2026-10-30: the board as a proper SHEET —
         grouped by date, this faculty's class → this faculty,
         kept current by the data itself (every switch, approval
         and re-cover shows here the moment it happens). --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Substitution Sheet</h5>
            <p class="text-muted mb-0">Who covered whom, period by period. The sheet updates itself — approvals, switches and re-covers appear here as they happen.</p>
            <p class="mb-0 mt-2"><a href="{{ route('substitutions.uncovered') }}" class="btn btn-sm btn-warning"><i class="icon-alarm mr-1"></i> Uncovered periods</a> <span class="text-muted">Periods still without cover — allot a teacher or cancel the class there.</span></p>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('substitutions.index') }}" class="form-inline mb-3">
                <label for="subSheetDate" class="mr-2">Date</label>
                <input type="date" id="subSheetDate" name="date" class="form-control mr-2" value="{{ $filter_date ?? '' }}">
                <button type="submit" class="btn btn-primary">Show</button>
                @if(!empty($filter_date))
                    <a href="{{ route('substitutions.index') }}" class="btn btn-light ml-2">All dates</a>
                    <span class="ml-3 text-muted">Showing {{ \Illuminate\Support\Carbon::parse($filter_date)->format('l, d M Y') }}</span>
                @else
                    {{-- Beauty Batch 6 (audit E11): blank = the whole
                         record is the settled owner rule (2026-10-30),
                         so the scope is stated rather than the filter
                         defaulting to today — a first glance used to
                         read the empty sheet as one day's. --}}
                    <span class="ml-3 text-muted">Showing all dates</span>
                @endif
            </form>

            @if($substitutions->isEmpty())
                {{-- Round 5 P11: the empty board renders the sheet
                     itself with the pat-empty table-row form (the
                     leave/my precedent) instead of a bare text
                     line, so the empty state carries the standard
                     too. Messages unchanged. --}}
                <div class="table-responsive mb-3">
                    <table class="table table-sm sub-table sub-sheet-table hk-std hk-std-subsheet">
                        <colgroup><col style="width:15%"><col style="width:15%"><col style="width:14%"><col style="width:15%"><col style="width:15%"><col style="width:10%"><col style="width:8%"><col style="width:8%"></colgroup>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Absent Faculty</th>
                                <th>Substitute Faculty</th>
                                <th>Arranged By</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="8">@include('partials.patterns.empty_state', ['icon' => 'icon-calendar2', 'message' => !empty($filter_date) ? 'No substitutions on this date.' : 'No substitutions arranged yet — this sheet covers every date; pick a date above to see a single day.'])</td></tr>
                        </tbody>
                    </table>
                </div>
            @else
                @foreach($substitutions->groupBy(function ($s) { return $s->date ? $s->date->toDateString() : ''; }) as $dateStr => $rows)
                    <h6 class="mb-2 mt-3">
                        {{ $dateStr ? \Illuminate\Support\Carbon::parse($dateStr)->format('l, d M Y') : 'No date' }}
                        <span class="badge badge-light">{{ $rows->count() }} period(s)</span>
                    </h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm sub-table sub-sheet-table hk-std hk-std-subsheet">
                            <colgroup><col style="width:15%"><col style="width:15%"><col style="width:14%"><col style="width:15%"><col style="width:15%"><col style="width:10%"><col style="width:8%"><col style="width:8%"></colgroup>
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Absent Faculty</th>
                                    <th>Substitute Faculty</th>
                                    <th>Arranged By</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $sub)
                                    <tr>
                                        <td data-label="Time" class="sub-nowrap">{{ optional(optional($sub->time_table)->time_slot)->full ?: '—' }}</td>
                                        <td data-label="Class">
                                            {{ optional(optional(optional($sub->time_table)->tt_record)->my_class)->name ?: '—' }}
                                            @if(optional(optional($sub->time_table)->tt_record)->section)
                                                — {{ $sub->time_table->tt_record->section->name }}
                                            @else
                                                — All sections
                                            @endif
                                        </td>
                                        <td data-label="Subject">{{ optional(optional($sub->time_table)->subject)->name ?: '—' }}</td>
                                        <td data-label="Absent Faculty">{{ optional($sub->absent_teacher)->name ?: '—' }}</td>
                                        <td data-label="Substitute Faculty">@if($sub->isCancelled())<span class="text-muted">— Class cancelled —</span>@else<strong>{{ optional($sub->substitute)->name ?: '—' }}</strong>@endif</td>
                                        <td data-label="Arranged By">{{ $sub->creator ? $sub->creator->name : 'System (auto)' }}</td>
                                        <td data-label="Status" class="sub-nowrap">
                                            @if($sub->isCancelled())
                                                <span class="badge badge-secondary">Cancelled</span>
                                            @elseif($sub->isPending())
                                                <span class="badge badge-warning">Planned</span>
                                            @else
                                                <span class="badge badge-success">Live</span>
                                            @endif
                                        </td>
                                        <td data-label="Action">
                                            @if($can_delete($sub))
                                                <form method="POST" action="{{ route('substitutions.destroy', $sub->id) }}" data-confirm="Remove this substitution? This cannot be undone." data-confirm-title="Remove Substitution" data-confirm-action="Remove" data-confirm-danger>
                                                    @csrf
                                                    @method('delete')
                                                    <button type="submit" class="btn btn-sm btn-danger"><i class="icon-trash"></i> Remove</button>
                                                </form>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
@endsection
