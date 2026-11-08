@extends('layouts.master')
@section('page_title', 'Upload Student Document')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Documents &nbsp;/&nbsp; <a href="{{ route('documents.index') }}">Student Documents</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
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
                    <label>Class</label>
                    <select class="form-control hk-cascade-class">
                        <option value="">Choose a class…</option>
                        @foreach($classes as $hkClass)
                            <option value="{{ $hkClass->id }}">{{ $hkClass->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <select class="form-control hk-cascade-section">
                        <option value="">Choose a class first</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="student_record_id">Student <span class="text-danger">*</span></label>
                    <select name="student_record_id" id="student_record_id" class="form-control hk-cascade-student @error('student_record_id') is-invalid @enderror" required data-value-key="id"
                            @if($preselected) data-preselect-class="{{ $preselected->my_class_id }}" data-preselect-section="{{ $preselected->section_id }}" data-preselect-value="{{ $preselected->id }}" data-preselect-label="{{ optional($preselected->user)->name }} ({{ $preselected->adm_no }})" @endif>
                        <option value="">Choose a student…</option>
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

@section('scripts')
    @include('partials.student_cascade_js')
@endsection
