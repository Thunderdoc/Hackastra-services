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
                                    <td data-label="Substitute">{{ optional($sub->substitute)->name ?: '—' }}</td>
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
