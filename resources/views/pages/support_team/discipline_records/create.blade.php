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
                    <label for="student_id">Student</label>
                    <select name="student_id" id="student_id" class="form-control" required>
                        <option value="">Select student</option>
                        @foreach($students as $student)
                            <option value="{{ $student->id }}" {{ old('student_id') == $student->id ? 'selected' : '' }}>{{ $student->name }}</option>
                        @endforeach
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
