@extends('layouts.master')
@section('page_title', 'Import Students (CSV)')
@section('content')

    {{-- Import report from the last upload --}}
    @if(session('import_report'))
        @php($report = session('import_report'))
        <div class="card">
            <div class="card-header header-elements-inline">
                <h6 class="card-title">Import Report</h6>
                {!! Qs::getPanelOptions() !!}
            </div>
            <div class="card-body">
                <p class="mb-2">
                    <span class="badge badge-success">{{ $report['imported'] }} imported</span>
                    <span class="badge badge-danger">{{ count($report['errors']) }} skipped</span>
                </p>
                @if(count($report['errors']))
                    <p class="text-muted">Fix the rows below in your spreadsheet and upload the file again — students that were already imported are detected by email / admission number, so re-uploading the whole file is safe.</p>
                    <table class="table">
                        <thead>
                        <tr>
                            <th>CSV Row</th>
                            <th>Name</th>
                            <th>Problem(s)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($report['errors'] as $err)
                            <tr>
                                <td>{{ $err['row'] }}</td>
                                <td>{{ $err['name'] ?: '—' }}</td>
                                <td>
                                    @foreach($err['messages'] as $msg)
                                        <div>{{ $msg }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif

    <div class="card pat-form-card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Admit Many Students At Once — Upload a CSV File</h6>
            <div>
                <a href="{{ route('students.import.template') }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Download Template</a>
                <a href="{{ route('students.index') }}" class="btn btn-light btn-sm"><i class="icon-users mr-1"></i> Students List</a>
            </div>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body p-0">
            <div class="pat-form-section">
                <div class="pat-form-section-title">How it works</div>
                    <ol class="pl-3 mb-0">
                        <li>Download the template and fill one row per student (keep the first row — the column names — exactly as it is).</li>
                        <li>Class, Section, State, LGA, Nationality etc. are written by <strong>name</strong>, exactly as they appear in the system — see the reference lists on this page.</li>
                        <li>Upload the file. Each row is checked on its own: good rows are admitted immediately, bad rows are skipped and listed in a report with the row number and the reason.</li>
                        <li>Imported students log in with the usual default student password, and get an admission number in the same format as students admitted one by one.</li>
                    </ol>
            </div>

            <div class="pat-form-section">
                <div class="pat-form-section-title">Upload CSV</div>
                    <form method="post" enctype="multipart/form-data" action="{{ route('students.import.store') }}">
                        @csrf
                        <div class="pat-fields">
                            <div class="pat-field pat-field-wide">
                                <label for="import_file">CSV File <span class="text-danger">*</span></label>
                                <input type="file" name="import_file" id="import_file" accept=".csv" required class="form-input-styled @error('import_file') is-invalid @enderror" data-fouc>
                                @include('partials.errors.field', ['field' => 'import_file'])
                                <span class="form-text text-muted">.csv only, max 10MB. Rows with problems are skipped, never half-saved.</span>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success mt-2"><i class="icon-upload mr-1"></i> Import Students</button>
                    </form>
            </div>

            <div class="pat-form-section">
                <div class="pat-form-section-title">Columns</div>
                    <table class="table table-bordered">
                        <thead>
                        <tr>
                            <th>Column</th>
                            <th>Required</th>
                            <th>Notes</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr><td>name</td><td><span class="text-danger">*</span></td><td>Full name, 6–150 characters.</td></tr>
                        <tr><td>email</td><td></td><td>Must be unique; leave empty if the student has none.</td></tr>
                        <tr><td>gender</td><td><span class="text-danger">*</span></td><td>Male or Female.</td></tr>
                        <tr><td>phone / phone2</td><td></td><td>6–20 characters.</td></tr>
                        <tr><td>dob</td><td></td><td>Date of birth, YYYY-MM-DD.</td></tr>
                        <tr><td>address</td><td><span class="text-danger">*</span></td><td>6–120 characters.</td></tr>
                        <tr><td>nationality</td><td><span class="text-danger">*</span></td><td>Exact name, e.g. Nigerian.</td></tr>
                        <tr><td>state</td><td><span class="text-danger">*</span></td><td>Exact state name.</td></tr>
                        <tr><td>lga</td><td><span class="text-danger">*</span></td><td>Exact LGA name — must belong to the state on the same row.</td></tr>
                        <tr><td>blood_group</td><td></td><td>e.g. O+, A-, AB+.</td></tr>
                        <tr><td>class</td><td><span class="text-danger">*</span></td><td>Exact class name, e.g. JSS 1.</td></tr>
                        <tr><td>section</td><td><span class="text-danger">*</span></td><td>Exact section name in that class, e.g. A.</td></tr>
                        <tr><td>parent_email</td><td></td><td>Email of an existing parent account, to link the student.</td></tr>
                        <tr><td>year_admitted</td><td><span class="text-danger">*</span></td><td>4-digit year, e.g. {{ date('Y') }}.</td></tr>
                        <tr><td>dorm / dorm_room_no</td><td></td><td>Dormitory name (exact) and room number.</td></tr>
                        <tr><td>house</td><td></td><td>Sports house.</td></tr>
                        <tr><td>adm_no</td><td></td><td>Your own admission number suffix (letters/numbers). Leave empty to auto-generate.</td></tr>
                        </tbody>
                    </table>
            </div>

            <div class="pat-form-section">
                <div class="pat-form-section-title">Valid names in this school</div>
                    <p class="text-muted mb-1"><strong>Classes &amp; Sections</strong></p>
                    <ul class="pl-3">
                        @foreach($my_classes as $c)
                            <li>{{ $c->name }} —
                                {{ $sections->where('my_class_id', $c->id)->pluck('name')->implode(', ') ?: 'no sections' }}
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-muted mb-1"><strong>Nationalities</strong></p>
                    <p>{{ $nationals->pluck('name')->implode(', ') }}</p>
                    <p class="text-muted mb-1"><strong>States</strong></p>
                    <p>{{ $states->pluck('name')->implode(', ') }}</p>
                    <p class="text-muted mb-0">LGA names must match the LGAs registered under each state in <em>Settings → Locations</em>.</p>
            </div>
        </div>
    </div>

@endsection
