@extends('layouts.master')
@section('page_title', 'New Lesson Plan')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('lesson_plans.index') }}">Lesson Plans</a> &nbsp;/&nbsp; New Lesson Plan
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">New Lesson Plan</h5>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('lesson_plans.store') }}">
                @csrf
                <div class="form-group">
                    <label for="my_class_id">Class</label>
                    <select name="my_class_id" id="my_class_id" class="form-control" required>
                        <option value="">Select class</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ old('my_class_id') == $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="section_id">Section (optional, whole class when left blank)</label>
                    <select name="section_id" id="section_id" class="form-control">
                        <option value="">Whole class</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" {{ old('section_id') == $section->id ? 'selected' : '' }}>{{ optional($section->my_class)->name }} - {{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="subject_id">Subject (only subjects you teach are listed)</label>
                    <select name="subject_id" id="subject_id" class="form-control" required>
                        <option value="">Select subject</option>
                        @foreach($subjects as $subject)
                            <option value="{{ $subject->id }}" {{ old('subject_id') == $subject->id ? 'selected' : '' }}>{{ optional($subject->my_class)->name }} - {{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="week_start">Week starting (any day in the week works, it is stored as that week's Monday)</label>
                    <input type="date" name="week_start" id="week_start" class="form-control" value="{{ old('week_start') }}" required>
                </div>
                <div class="form-group">
                    <label for="topic">Topic</label>
                    <input type="text" name="topic" id="topic" class="form-control" value="{{ old('topic') }}" maxlength="255" required>
                </div>
                <div class="form-group">
                    <label for="notes">Notes (optional)</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3">{{ old('notes') }}</textarea>
                </div>
                <div class="text-right">
                    <a href="{{ route('lesson_plans.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Lesson Plan</button>
                </div>
            </form>
        </div>
    </div>
@endsection
