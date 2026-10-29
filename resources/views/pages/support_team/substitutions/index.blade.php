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
                        {{ optional($first->absent_teacher)->name }} — {{ $first->date ? $first->date->format('d M Y') : '' }}
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
                                            {{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}
                                            @if(optional(optional($row->time_table)->tt_record)->section)
                                                — {{ $row->time_table->tt_record->section->name }}
                                            @endif
                                            <br>
                                            <span class="text-muted">{{ optional(optional($row->time_table)->subject)->name }} ({{ optional($row->time_table)->day }} {{ optional(optional($row->time_table)->time_slot)->full }})</span>
                                        </td>
                                        <td data-label="Substitute">{{ optional($row->substitute)->name ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Substitutions</h5>
            <p class="text-muted mb-0">Cover arranged for an absent teacher's period on a date. One substitute per period per date.</p>
        </div>
        <div class="card-body">
            @if($substitutions->isEmpty())
                <p class="text-muted mb-0">No substitutions arranged yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Period</th>
                                <th>Absent Teacher</th>
                                <th>Substitute</th>
                                <th>Reason</th>
                                <th>Arranged By</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($substitutions as $sub)
                                <tr>
                                    <td data-label="Date">{{ $sub->date ? $sub->date->format('d M Y') : '—' }}</td>
                                    <td data-label="Period">
                                        {{ optional(optional(optional($sub->time_table)->tt_record)->my_class)->name ?: '—' }}
                                        @if(optional(optional($sub->time_table)->tt_record)->section)
                                            — {{ $sub->time_table->tt_record->section->name }}
                                        @endif
                                        <br>
                                        <span class="text-muted">{{ optional(optional($sub->time_table)->subject)->name }} ({{ optional($sub->time_table)->day }} {{ optional(optional($sub->time_table)->time_slot)->full }})</span>
                                    </td>
                                    <td data-label="Absent Teacher">{{ optional($sub->absent_teacher)->name ?: '—' }}</td>
                                    <td data-label="Substitute">{{ optional($sub->substitute)->name ?: '—' }}
                                        @if($sub->isPending())
                                            <br><span class="badge badge-warning">Pending review</span>
                                        @endif
                                    </td>
                                    <td data-label="Reason">{{ $sub->reason ?: '—' }}</td>
                                    <td data-label="Arranged By">{{ $sub->creator ? $sub->creator->name : 'System (auto)' }}</td>
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
            @endif
        </div>
    </div>
@endsection
