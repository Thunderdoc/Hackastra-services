@extends('layouts.master')
@section('page_title', 'Substitutions')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Substitutions
@endsection
@section('content')
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
                        <span class="badge badge-warning">{{ $rows->count() }} period(s) · Planned — goes live when the leave is approved</span>
                    </h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Substitute (system's pick)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr>
                                        <td data-label="Period">
                                            {{ optional(optional($row->time_table)->time_slot)->full ?: '' }}
                                            {{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}
                                            @if(optional(optional($row->time_table)->tt_record)->section)
                                                — {{ $row->time_table->tt_record->section->name }}
                                            @endif
                                            · {{ optional(optional($row->time_table)->subject)->name }}
                                        </td>
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
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('substitutions.index') }}" class="form-inline mb-3">
                <label for="subSheetDate" class="mr-2">Date</label>
                <input type="date" id="subSheetDate" name="date" class="form-control mr-2" value="{{ $filter_date ?? '' }}">
                <button type="submit" class="btn btn-primary">Show</button>
                @if(!empty($filter_date))
                    <a href="{{ route('substitutions.index') }}" class="btn btn-light ml-2">All dates</a>
                    <span class="ml-3 text-muted">Showing {{ \Illuminate\Support\Carbon::parse($filter_date)->format('l, d M Y') }}</span>
                @endif
            </form>

            @if($substitutions->isEmpty())
                <p class="text-muted mb-0">{{ !empty($filter_date) ? 'No substitutions on this date.' : 'No substitutions arranged yet.' }}</p>
            @else
                @foreach($substitutions->groupBy(function ($s) { return $s->date ? $s->date->toDateString() : ''; }) as $dateStr => $rows)
                    <h6 class="mb-2 mt-3">
                        {{ $dateStr ? \Illuminate\Support\Carbon::parse($dateStr)->format('l, d M Y') : 'No date' }}
                        <span class="badge badge-light">{{ $rows->count() }} period(s)</span>
                    </h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm">
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
                                        <td data-label="Time">{{ optional(optional($sub->time_table)->time_slot)->full ?: '—' }}</td>
                                        <td data-label="Class">
                                            {{ optional(optional(optional($sub->time_table)->tt_record)->my_class)->name ?: '—' }}
                                            @if(optional(optional($sub->time_table)->tt_record)->section)
                                                — {{ $sub->time_table->tt_record->section->name }}
                                            @endif
                                        </td>
                                        <td data-label="Subject">{{ optional(optional($sub->time_table)->subject)->name ?: '—' }}</td>
                                        <td data-label="Absent Faculty">{{ optional($sub->absent_teacher)->name ?: '—' }}</td>
                                        <td data-label="Substitute Faculty"><strong>{{ optional($sub->substitute)->name ?: '—' }}</strong></td>
                                        <td data-label="Arranged By">{{ $sub->creator ? $sub->creator->name : 'System (auto)' }}</td>
                                        <td data-label="Status">
                                            @if($sub->isPending())
                                                <span class="badge badge-warning">Planned</span>
                                            @else
                                                <span class="badge badge-success">Live</span>
                                            @endif
                                        </td>
                                        <td data-label="Action">
                                            @if($can_delete($sub))
                                                <form method="POST" action="{{ route('substitutions.destroy', $sub->id) }}" onsubmit="return confirm('Remove this substitution?')">
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
