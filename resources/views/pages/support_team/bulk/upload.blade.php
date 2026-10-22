@extends('layouts.master')
@section('page_title', $flow_title)
@section('content')

    {{-- One upload form for every bulk flow: the hub card decides
         module + mode; the file is staged and validated into a
         preview — uploading never writes records directly. --}}
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">{{ $flow_title }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p>{{ $flow_intro }}</p>
            <p>
                <a href="{{ $template_url }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download the CSV template</a>
                <a href="{{ route('bulk.index') }}" class="btn btn-light btn-sm">Back to Bulk Management</a>
            </p>

            <form method="POST" action="{{ route('bulk.upload') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="module" value="{{ $module }}">
                <input type="hidden" name="mode" value="{{ $mode }}">
                <div class="form-group">
                    <label for="bulk_file">CSV file <span class="text-danger">*</span></label>
                    <input type="file" name="bulk_file" id="bulk_file" class="form-control" accept=".csv" required>
                    <span class="form-text text-muted">CSV only, up to 10 MB and {{ \App\Support\Bulk\BulkImportService::MAX_ROWS }} rows. Names are matched by name, not database ids — Class, Section, State and LGA must be spelled as they appear in the app.</span>
                </div>
                <button type="submit" class="btn btn-success"><i class="icon-upload mr-1"></i> Upload &amp; Preview</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Columns for this flow</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                    <tr><th>Column</th><th>Required in the file</th></tr>
                    </thead>
                    <tbody>
                    @foreach($columns as $col)
                        <tr>
                            <td><code>{{ $col }}</code></td>
                            <td>
                                @if(in_array($col, $required, true))
                                    <span class="badge badge-danger">Required</span>
                                @else
                                    <span class="text-muted">Optional{{ $mode === 'update' ? ' — blank means no change' : '' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($mode === 'update')
                <p class="text-muted mb-0"><strong>Never editable in bulk:</strong> admission number (it is the match key), username, password, account status and role. A row whose Admission Number matches no student is skipped — bulk edit never creates students.</p>
            @endif
            @if($module === 'staff')
                <p class="text-muted mb-0"><strong>Generated for you:</strong> each account's username / staff code and its first password (the role name, e.g. <code>teacher</code>) — staff should change it at first login. Email is the identity key for staff; an email that already has an account is refused as a duplicate.</p>
            @endif
        </div>
    </div>

@endsection
