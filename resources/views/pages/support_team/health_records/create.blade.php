@extends('layouts.master')
@section('page_title', 'New Health Record')
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
                    <label for="student_id">Student</label>
                    <select name="student_id" id="student_id" class="form-control" required>
                        <option value="">Select student</option>
                        @foreach($students as $studentRecord)
                            <option value="{{ $studentRecord->user_id }}" {{ old('student_id') == $studentRecord->user_id ? 'selected' : '' }}>{{ optional($studentRecord->user)->name }} — {{ optional($studentRecord->my_class)->name }}@if($studentRecord->section) - Section {{ $studentRecord->section->name }}@endif</option>
                        @endforeach
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
