@extends('layouts.master')
@section('page_title', 'Substitutions')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Substitutions
@endsection
@section('page_actions')
    @if(Qs::userIsAdmin())
        <a href="{{ route('substitutions.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> Arrange Substitution</a>
    @endif
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
                <h5 class="card-title mb-0">System-arranged plans — waiting for your review</h5>
                <p class="mb-0">The system arranged this cover automatically from approved leave. Nothing here is live yet: substitutes get access and their bells only when you submit a plan. Switch any teacher (only teachers free at that time are listed), then submit.</p>
            </div>
            <div class="card-body">
                @foreach($pending_groups as $groupKey => $rows)
                    @php $first = $rows->first(); @endphp
                    <h6 class="mb-2">
                        {{ optional($first->absent_teacher)->name }} — {{ $first->date ? $first->date->format('d M Y') : '' }}
                        <span class="badge badge-warning">{{ $rows->count() }} period(s) · Not submitted yet</span>
                    </h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Substitute (system's pick)</th>
                                    @if($is_admin)<th>Switch to a free teacher</th>@endif
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
                                        @if($is_admin)
                                            <td data-label="Switch">
                                                @php $opts = $free_options[$row->id] ?? collect(); @endphp
                                                @if($opts->isEmpty())
                                                    <span class="text-muted">No other teacher is free at this time</span>
                                                @else
                                                    <form method="POST" action="{{ route('substitutions.switch', $row->id) }}" class="form-inline">
                                                        @csrf
                                                        <select name="substitute_teacher_id" class="form-control form-control-sm mr-2">
                                                            @foreach($opts as $opt)
                                                                <option value="{{ $opt->id }}">{{ $opt->name }} — free</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-secondary">Switch</button>
                                                    </form>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($is_admin)
                        <form method="POST" action="{{ route('substitutions.confirm') }}" class="mb-4" onsubmit="return confirm('Submit this plan? Substitutes get access and bells, and students are notified.')">
                            @csrf
                            <input type="hidden" name="absent_teacher_id" value="{{ $first->absent_teacher_id }}">
                            <input type="hidden" name="date" value="{{ $first->date ? $first->date->format('Y-m-d') : '' }}">
                            <button type="submit" class="btn btn-success"><i class="icon-checkmark3 mr-1"></i> Submit this plan</button>
                        </form>
                    @endif
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
