@extends('layouts.master')
@section('page_title', 'New Discipline Record')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">New Discipline Record</h5>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('discipline_records.store') }}">
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
                    <select name="student_id" id="student_id" class="form-control hk-cascade-student" required data-value-key="user_id"
                            @if($preselected) data-preselect-class="{{ $preselected->my_class_id }}" data-preselect-section="{{ $preselected->section_id }}" data-preselect-value="{{ $preselected->user_id }}" data-preselect-label="{{ optional($preselected->user)->name }} ({{ $preselected->adm_no }})" @endif>
                        <option value="">Choose a student…</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="incident_date">Date</label>
                    <input type="date" name="incident_date" id="incident_date" class="form-control" value="{{ old('incident_date') }}" required>
                </div>
                <div class="form-group">
                    <label for="type">Type</label>
                    <select name="type" id="type" class="form-control" required>
                        <option value="">Select type</option>
                        @foreach($types as $val => $label)
                            <option value="{{ $val }}" {{ old('type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="4" maxlength="2000" required>{{ old('description') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="action_taken">Action Taken (optional)</label>
                    <textarea name="action_taken" id="action_taken" class="form-control" rows="3" maxlength="2000">{{ old('action_taken') }}</textarea>
                </div>
                <div class="text-right">
                    <a href="{{ route('discipline_records.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Record</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    @include('partials.student_cascade_js')
@endsection
