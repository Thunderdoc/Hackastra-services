@extends('layouts.master')
@section('page_title', 'Upload Student Document')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Upload Student Document</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">Admin-only upload. Allowed types: PDF, Word (doc/docx), JPG, PNG — max 10 MB. The file is stored under a random name and downloads are ownership-checked.</p>
            <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="form-group">
                    <label for="student_record_id">Student <span class="text-danger">*</span></label>
                    <select name="student_record_id" id="student_record_id" class="form-control @error('student_record_id') is-invalid @enderror" required>
                        <option value="">Choose a student…</option>
                        @foreach($students as $st)
                            <option value="{{ $st->id }}" {{ old('student_record_id') == $st->id ? 'selected' : '' }}>{{ $st->user->name }} ({{ $st->adm_no }}) — {{ optional($st->my_class)->name }} {{ optional($st->section)->name }}</option>
                        @endforeach
                    </select>
                    @error('student_record_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label for="title">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="title" maxlength="255" value="{{ old('title') }}" class="form-control @error('title') is-invalid @enderror" placeholder="e.g. Birth certificate" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label for="document">File <span class="text-danger">*</span></label>
                    <input type="file" name="document" id="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="form-control @error('document') is-invalid @enderror" required>
                    @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-success">Upload</button>
                <a href="{{ route('documents.index') }}" class="btn btn-light">Back to Documents</a>
            </form>
        </div>
    </div>
@endsection
