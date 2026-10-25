@extends('layouts.master')
@section('page_title', 'Issue Certificate')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Issue Certificate</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">The serial is minted by the system (BON/TC + year + sequence) and the student's details are snapshotted — the certificate never changes afterwards.</p>
            <form method="POST" action="{{ route('certificates.store') }}">
                @csrf
                <div class="form-group">
                    <label for="student_record_id">Student <span class="text-danger">*</span></label>
                    <select name="student_record_id" id="student_record_id" class="form-control @error('student_record_id') is-invalid @enderror" required>
                        <option value="">Choose a student…</option>
                        @foreach($students as $st)
                            <option value="{{ $st->id }}" {{ (old('student_record_id', $preselected->id ?? '') == $st->id) ? 'selected' : '' }}>{{ $st->user->name }} ({{ $st->adm_no }}) — {{ optional($st->my_class)->name }} {{ optional($st->section)->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="type">Type <span class="text-danger">*</span></label>
                    <select name="type" id="type" class="form-control @error('type') is-invalid @enderror" required>
                        <option value="bonafide" {{ old('type', $type) === 'bonafide' ? 'selected' : '' }}>Bonafide Certificate</option>
                        <option value="transfer" {{ old('type', $type) === 'transfer' ? 'selected' : '' }}>Transfer Certificate</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reason">Reason <span class="text-muted">(required for Transfer)</span></label>
                    <textarea name="reason" id="reason" rows="2" maxlength="1000" class="form-control @error('reason') is-invalid @enderror" placeholder="e.g. Parent transferred to another city">{{ old('reason') }}</textarea>
                </div>
                <button type="submit" class="btn btn-success">Issue &amp; Print</button>
                <a href="{{ route('certificates.index') }}" class="btn btn-light">Back to Register</a>
            </form>
        </div>
    </div>
@endsection
