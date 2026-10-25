@extends('layouts.master')
@section('page_title', 'Bulk Job #' . $job->id . ' — ' . $job->title())
@section('content')

    {{-- Preview / progress / result for one bulk job. The confirm
         form carries the job's single-use token; once burned, this
         page can never apply the job again (audit §6.2). --}}
    <div class="card l111-card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Job #{{ $job->id }} — {{ $job->title() }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="mb-2">
                <strong>File:</strong> {{ $job->original_filename }} &middot;
                <strong>Uploaded by:</strong> {{ $job->user ? $job->user->name : '—' }} &middot;
                <strong>When:</strong> {{ $job->created_at ? $job->created_at->format('d M Y, H:i') : '' }} &middot;
                <strong>Status:</strong>
                @if($job->status === 'completed') <span class="badge badge-success">Completed</span>
                @elseif($job->status === 'rolled_back') <span class="badge badge-dark">Rolled Back</span>
                @elseif($job->status === 'applying') <span class="badge badge-warning">Applying…</span>
                @elseif($job->status === 'validated') <span class="badge badge-primary">Awaiting Confirmation</span>
                @elseif($job->status === 'discarded') <span class="badge badge-secondary">Discarded</span>
                @elseif($job->status === 'failed') <span class="badge badge-danger">Failed</span>
                @else <span class="badge badge-light">{{ ucfirst($job->status) }}</span> @endif
            </p>
            <p class="mb-0 l111-counts">
                <span class="badge badge-light">{{ $job->total_rows }} rows in file</span>
                <span class="badge badge-success">{{ $job->create_count }} to create</span>
                <span class="badge badge-info">{{ $job->update_count }} to update</span>
                <span class="badge badge-secondary">{{ $job->skip_count }} skipped</span>
                <span class="badge badge-danger">{{ $job->error_count }} with errors</span>
                @if($job->status === 'completed' || $job->status === 'applying')
                    &middot; <span class="badge badge-success">{{ $job->applied_create_count }} created so far</span>
                    <span class="badge badge-info">{{ $job->applied_update_count }} updated so far</span>
                    @if($job->failed_count) <span class="badge badge-danger">{{ $job->failed_count }} failed</span> @endif
                @endif
                @if($rolled_back_count) &middot; <span class="badge badge-dark">{{ $rolled_back_count }} rolled back</span> @endif
            </p>
        </div>
    </div>

    @if($job->status === 'completed')
        <div class="card l111-card">
            <div class="card-body">
                <p class="mb-2"><strong>Undo this job?</strong> Rollback deletes the accounts this job created and restores the values its edits changed. A row is skipped — never force-deleted — when its account has since gained marks, payments, attendance or teaching responsibility, or when its old values were never recorded.</p>
                @if($can_write)
                    <form method="POST" action="{{ route('bulk.jobs.rollback', $job->id) }}" onsubmit="return confirm('Roll back this job? Created accounts will be deleted and edited values restored where recorded. This cannot be undone.');">
                        @csrf
                        <button type="submit" class="btn btn-warning"><i class="icon-undo2 mr-1"></i> Roll Back This Job</button>
                    </form>
                @else
                    <p class="text-muted mb-0">Only the school Admin can roll back a bulk job.</p>
                @endif
            </div>
        </div>
    @endif

    @if($job->status === 'validated')
        <div class="card l111-card">
            <div class="card-body">
                @if($job->mode === 'update')
                    <p><strong>Before you confirm:</strong> rows that change a student's class also delete that student's marks and exam records for the previous class — exactly what the single Edit Student form does. The old → new table below is the full list of what will change; blank cells in your file changed nothing.</p>
                @else
                    <p><strong>Nothing has been saved yet.</strong> Review the rows below — problems and duplicates are listed in full — then confirm to apply the {{ $job->create_count + $job->update_count }} good row(s). Skipped and problem rows are never applied.</p>
                @endif
                @if($can_write && ($job->create_count + $job->update_count) > 0)
                    <form method="POST" action="{{ route('bulk.jobs.confirm', $job->id) }}" onsubmit="return confirm('Apply this bulk job now? You can roll it back from the job page afterwards.');">
                        @csrf
                        <input type="hidden" name="confirm_token" value="{{ $job->confirm_token }}">
                        <button type="submit" class="btn btn-success btn-lg"><i class="icon-checkmark-circle2 mr-1"></i> Confirm &amp; Apply {{ $job->create_count + $job->update_count }} Row(s)</button>
                    </form>
                @elseif($can_write)
                    <p class="text-muted mb-0">There is nothing to apply in this job — every row was skipped or has errors.</p>
                @else
                    <p class="text-muted mb-0">Only the school Admin can confirm and apply a bulk job.</p>
                @endif
                @if($can_write)
                    <form method="POST" action="{{ route('bulk.jobs.discard', $job->id) }}" class="mt-2" onsubmit="return confirm('Discard this job? Its rows will never be applied.');">
                        @csrf
                        <button type="submit" class="btn btn-light btn-sm"><i class="icon-trash mr-1"></i> Discard Job</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if($job->status === 'applying')
        <div class="card l111-card">
            <div class="card-body">
                <p><strong>Applying is in progress</strong> — {{ $applied_rows_count }} row(s) applied, {{ $pending_count }} still pending. Progress is saved row by row: if this page was closed, resume here.</p>
                @if($can_write)
                    <form id="bulk-continue-form" method="POST" action="{{ route('bulk.jobs.apply', $job->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-success"><i class="icon-checkmark-circle2 mr-1"></i> Continue Applying</button>
                    </form>
                    <form method="POST" action="{{ route('bulk.jobs.discard', $job->id) }}" class="mt-2" onsubmit="return confirm('Stop and discard the rest of this job? Rows already applied stay applied.');">
                        @csrf
                        <button type="submit" class="btn btn-light btn-sm"><i class="icon-trash mr-1"></i> Stop &amp; Discard the Rest</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if($job->mode === 'update' && $changed_rows->isNotEmpty())
        <div class="card l111-card">
            <div class="card-header header-elements-inline">
                <h6 class="card-title">Changes — Old → New</h6>
                {!! Qs::getPanelOptions() !!}
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table l111-table l108-stack">
                        <thead><tr><th>CSV Row</th><th>{{ $job->module === 'staff' ? 'Staff Member' : 'Student' }}</th><th>Field</th><th>Old</th><th></th><th>New</th></tr></thead>
                        <tbody>
                        @foreach($changed_rows as $r)
                            @foreach($r->changes as $field => $pair)
                                <tr>
                                    <td data-label="CSV Row">{{ $r->row_number }}</td>
                                    <td data-label="{{ $job->module === 'staff' ? 'Staff Member' : 'Student' }}">{{ $r->raw['name'] ?? '' }} <span class="text-muted">({{ $job->module === 'staff' ? ($r->raw['email'] ?? '') : ($r->raw['adm_no'] ?? '') }})</span></td>
                                    <td data-label="Field"><code>{{ $field }}</code></td>
                                    <td data-label="Old">{{ $pair['old'] !== '' && $pair['old'] !== null ? $pair['old'] : '—' }}</td>
                                    <td data-label="">→</td>
                                    <td data-label="New">{{ $pair['new'] !== '' && $pair['new'] !== null ? $pair['new'] : '—' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($changed_rows->count() >= 200)
                    <p class="text-muted mb-0">Showing the first 200 changed rows — the apply uses the full validated set.</p>
                @endif
            </div>
        </div>
    @endif

    @if($invalid_rows->isNotEmpty())
        <div class="card l111-card">
            <div class="card-header header-elements-inline">
                <h6 class="card-title">Rows With Errors — Not Applied</h6>
                {!! Qs::getPanelOptions() !!}
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table l111-table l108-stack">
                        <thead><tr><th>CSV Row</th><th>Field</th><th>You Supplied</th><th>Problem</th></tr></thead>
                        <tbody>
                        @foreach($invalid_rows as $r)
                            @foreach($r->errors ?? [] as $e)
                                <tr>
                                    <td data-label="CSV Row">{{ $r->row_number }}</td>
                                    <td data-label="Field"><code>{{ $e['field'] ?? '' }}</code></td>
                                    <td data-label="You Supplied">{{ $e['value'] ?? '' }}</td>
                                    <td data-label="Problem">{{ $e['message'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mb-0"><a href="{{ route('bulk.jobs.errors', $job->id) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download the full report (CSV)</a> <span class="text-muted">Fix the rows in your spreadsheet and upload a new file — rows that already succeeded are detected and skipped.</span></p>
            </div>
        </div>
    @endif

    @if($problem_rows->isNotEmpty())
        <div class="card l111-card">
            <div class="card-header header-elements-inline">
                <h6 class="card-title">Skipped &amp; Failed Rows</h6>
                {!! Qs::getPanelOptions() !!}
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table l111-table l108-stack">
                        <thead><tr><th>CSV Row</th><th>Outcome</th><th>Why</th></tr></thead>
                        <tbody>
                        @foreach($problem_rows as $r)
                            <tr>
                                <td data-label="CSV Row">{{ $r->row_number }}</td>
                                <td data-label="Outcome">
                                    @if($r->verdict === 'duplicate') <span class="badge badge-secondary">Duplicate — skipped</span>
                                    @elseif($r->verdict === 'unmatched') <span class="badge badge-secondary">No match — skipped</span>
                                    @else <span class="badge badge-danger">Failed</span> @endif
                                </td>
                                <td data-label="Why">
                                    @if($r->verdict === 'failed')
                                        {{ $r->failure }}
                                    @elseif($r->errors)
                                        {{ $r->errors[0]['message'] ?? '' }}@if(!empty($r->errors[0]['value'])) <span class="text-muted">({{ $r->errors[0]['field'] ?? '' }}: {{ $r->errors[0]['value'] }})</span>@endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($invalid_rows->isEmpty())
                    <p class="mb-0"><a href="{{ route('bulk.jobs.errors', $job->id) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download the full report (CSV)</a></p>
                @endif
            </div>
        </div>
    @endif

    <p><a href="{{ route('bulk.index') }}" class="btn btn-light">Back to Bulk Management</a></p>

@endsection

@if($job->status === 'applying' && $can_write)
@section('scripts')
    <script>
        // Auto-continue the chunked apply; the button stays as the
        // manual fallback if JavaScript is unavailable.
        setTimeout(function () {
            var f = document.getElementById('bulk-continue-form');
            if (f) { f.submit(); }
        }, 900);
    </script>
@endsection
@endif
