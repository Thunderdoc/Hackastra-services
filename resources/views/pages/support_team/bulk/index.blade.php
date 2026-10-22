@extends('layouts.master')
@section('page_title', 'Bulk Management')
@section('content')

    {{-- Bulk Management hub (Bulk System Phase A): one staged pipeline
         for CSV bulk work — upload → validate → preview → confirm →
         apply in chunks. Nothing is written before the preview is
         confirmed. The legacy one-pass import stays available and is
         linked below. --}}

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Bulk Management</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="mb-0">Admit or update whole classes from a spreadsheet — safely. Every file is <strong>checked and previewed first</strong>: you see exactly what will be created, updated, skipped or rejected, and nothing is saved until you confirm. If a file is uploaded twice, rows it already created are detected and skipped.</p>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title"><i class="icon-users4 mr-2"></i>Bulk Add Students</h6>
                    <p class="text-muted">Admit many new students from one CSV — same records as the Admit Student form and the classic import.</p>
                    <a href="{{ route('students.import.template') }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Template</a>
                    @if(Qs::userIsAdmin())
                        <a href="{{ route('bulk.students.add') }}" class="btn btn-success btn-sm"><i class="icon-upload mr-1"></i> Start</a>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title"><i class="icon-file-text2 mr-2"></i>Bulk Edit Students</h6>
                    <p class="text-muted">Update existing students matched by Admission Number. Blank cells never overwrite data; the preview shows old → new first.</p>
                    <a href="{{ route('bulk.templates.students_edit') }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Template</a>
                    <a href="{{ route('bulk.exports.students') }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Current Records</a>
                    @if(Qs::userIsAdmin())
                        <a href="{{ route('bulk.students.edit') }}" class="btn btn-success btn-sm"><i class="icon-upload mr-1"></i> Start</a>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title"><i class="icon-users4 mr-2"></i>Bulk Add Staff</h6>
                    <p class="text-muted">Create teacher, accountant, librarian and admin accounts from one CSV. Staff codes and passwords are generated — never taken from the file.</p>
                    <a href="{{ route('bulk.templates.staff_add') }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Template</a>
                    @if(Qs::userIsAdmin())
                        <a href="{{ route('bulk.staff.add') }}" class="btn btn-success btn-sm"><i class="icon-upload mr-1"></i> Start</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Import History</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if($jobs->isEmpty())
                <p class="text-muted mb-0">No bulk jobs yet. Start one above — every upload is recorded here with its counts, whether it was applied, discarded, or is still waiting for confirmation.</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Job</th>
                            <th>Flow</th>
                            <th>File</th>
                            <th>By</th>
                            <th>Rows</th>
                            <th>Outcome</th>
                            <th>Status</th>
                            <th>When</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($jobs as $job)
                            <tr>
                                <td>#{{ $job->id }}</td>
                                <td>{{ $job->title() }}</td>
                                <td>{{ $job->original_filename }}</td>
                                <td>{{ $job->user ? $job->user->name : '—' }}</td>
                                <td>{{ $job->total_rows }}</td>
                                <td>
                                    <span class="badge badge-success">{{ $job->applied_create_count }} created</span>
                                    <span class="badge badge-info">{{ $job->applied_update_count }} updated</span>
                                    <span class="badge badge-secondary">{{ $job->skip_count }} skipped</span>
                                    @if($job->error_count + $job->failed_count > 0)
                                        <span class="badge badge-danger">{{ $job->error_count + $job->failed_count }} problems</span>
                                    @endif
                                </td>
                                <td>
                                    @if($job->status === 'completed') <span class="badge badge-success">Completed</span>
                                    @elseif($job->status === 'applying') <span class="badge badge-warning">Applying…</span>
                                    @elseif($job->status === 'validated') <span class="badge badge-primary">Awaiting Confirmation</span>
                                    @elseif($job->status === 'discarded') <span class="badge badge-secondary">Discarded</span>
                                    @elseif($job->status === 'failed') <span class="badge badge-danger">Failed</span>
                                    @else <span class="badge badge-light">{{ ucfirst($job->status) }}</span> @endif
                                </td>
                                <td>{{ $job->created_at ? $job->created_at->format('d M Y, H:i') : '' }}</td>
                                <td><a href="{{ route('bulk.jobs.show', $job->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="text-muted mt-3 mb-0">Looking for the classic one-pass student import? It is still available under <a href="{{ route('students.import') }}">Students → Import CSV</a>. New work is safer here: preview first, confirm second.</p>
        </div>
    </div>

@endsection
