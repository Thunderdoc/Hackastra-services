@extends('layouts.master')
@section('page_title', 'New Health Record')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Health &nbsp;/&nbsp; <a href="{{ route('health_records.index') }}">Health Records</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">New Health Record</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Enter only what the student or clinic provides. Leave a field blank when it is unknown — nothing is pre-filled.</p>
            <form method="post" action="{{ route('health_records.store') }}">
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
                    <label for="student_id">Student</label>
                    <select name="student_id" id="student_id" class="form-control hk-cascade-student" required data-value-key="user_id" data-student-search="remote" data-search-placeholder="Type a name or admission no…"
                            @if($preselected) data-preselect-class="{{ $preselected->my_class_id }}" data-preselect-section="{{ $preselected->section_id }}" data-preselect-value="{{ $preselected->user_id }}" data-preselect-label="{{ optional($preselected->user)->name }} ({{ $preselected->adm_no }})" @endif>
                        <option value="">Choose a section, then type to search</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="visit_date">Visit date</label>
                    <input type="date" name="visit_date" id="visit_date" class="form-control" value="{{ old('visit_date') }}" required>
                </div>
                <div class="form-group">
                    <label for="complaint">Complaint</label>
                    <textarea name="complaint" id="complaint" class="form-control" rows="3" required>{{ old('complaint') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="treatment">Treatment (optional)</label>
                    <textarea name="treatment" id="treatment" class="form-control" rows="3">{{ old('treatment') }}</textarea>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_referred" value="1" {{ old('is_referred') ? 'checked' : '' }}> Referred elsewhere
                    </label>
                </div>
                <div class="form-group">
                    <label for="referred_to">Referred to (optional)</label>
                    <input type="text" name="referred_to" id="referred_to" class="form-control" value="{{ old('referred_to') }}" maxlength="255">
                </div>
                <div class="text-right">
                    <a href="{{ route('health_records.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Health Record</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    @include('partials.student_cascade_js')
@endsection
