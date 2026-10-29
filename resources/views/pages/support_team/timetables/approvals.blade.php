@extends('layouts.master')
@section('page_title', 'Timetable Approvals')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; Timetables &nbsp;/&nbsp; Approvals
@endsection
@section('page_subtitle', 'Timetables the Admin has reviewed and sent for your approval.')
@section('content')

    {{-- Timetable approval queue (owner order 2026-10-29): the
         Admin generates and reviews; the Principal approves, and
         only then is a timetable in force for the school. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title"><i class="icon-calendar mr-2"></i>Timetable Approvals</h5>
        </div>
        <div class="card-body">
            @if(count($records) > 0)
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr><th>Timetable</th><th>Class</th><th>Sent by</th><th>Sent on</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach($records as $rec)
                        <tr>
                            <td><strong>{{ $rec->name }}</strong> ({{ $rec->year }})</td>
                            <td>{{ optional($rec->my_class)->name ?? 'Deleted class' }}@if($rec->section) — {{ $rec->section->name }}@endif</td>
                            <td>{{ optional($submitters->get($rec->approval_submitted_by))->name ?? 'Admin' }}</td>
                            <td>{{ $rec->approval_submitted_at ? \Illuminate\Support\Carbon::parse($rec->approval_submitted_at)->format('d M Y, g:i A') : '' }}</td>
                            <td class="text-right" style="white-space: nowrap;">
                                <a href="{{ route('ttr.show', $rec->id) }}" class="btn btn-light btn-sm" target="_blank"><i class="icon-eye mr-1"></i> View</a>
                                <a href="{{ route('ttr.print', $rec->id) }}" class="btn btn-light btn-sm" target="_blank"><i class="icon-printer mr-1"></i> Print</a>
                                <form method="post" action="{{ route('ttr.approve', $rec->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm"><i class="icon-checkmark3 mr-1"></i> Approve</button>
                                </form>
                                <form method="post" action="{{ route('ttr.send_back', $rec->id) }}" style="display:inline;" onsubmit="return confirm('Send this timetable back to the Admin for changes?');">
                                    @csrf
                                    <button type="submit" class="btn btn-warning btn-sm"><i class="icon-undo2 mr-1"></i> Send back</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @else
                <p class="empty-note">No timetables are waiting for approval. When the Admin reviews a generated timetable and sends it, it will appear here.</p>
            @endif
        </div>
    </div>

@endsection
