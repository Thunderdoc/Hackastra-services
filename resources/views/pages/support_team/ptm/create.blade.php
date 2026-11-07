@extends('layouts.master')
@section('page_title', 'New PTM Slot')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">New PTM Slot</h5>
            <div class="header-elements">
                <a href="{{ route('ptm.index') }}" class="btn btn-light btn-sm">Back to Slots</a>
            </div>
        </div>
        <div class="card-body">
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif
            <form method="POST" action="{{ route('ptm.store') }}">
                @csrf
                <div class="form-group">
                    <label for="date">Date</label>
                    <input id="date" type="date" name="date" class="form-control @error('date') is-invalid @enderror" required value="{{ old('date') }}">@include('partials.errors.field', ['field' => 'date'])
                </div>
                <div class="form-group">
                    <label for="start_time">Start time</label>
                    <input id="start_time" type="time" name="start_time" class="form-control @error('start_time') is-invalid @enderror" required value="{{ old('start_time') }}">@include('partials.errors.field', ['field' => 'start_time'])
                </div>
                <div class="form-group">
                    <label for="end_time">End time</label>
                    <input id="end_time" type="time" name="end_time" class="form-control @error('end_time') is-invalid @enderror" required value="{{ old('end_time') }}">@include('partials.errors.field', ['field' => 'end_time'])
                </div>
                <div class="form-group">
                    <label for="teacher_id">Teacher</label>
                    <select id="teacher_id" name="teacher_id" class="form-control @error('teacher_id') is-invalid @enderror" required>
                        <option value="">Select a teacher</option>
                        @foreach($teachers as $teacher)
                            <option value="{{ $teacher->id }}" {{ (string) old('teacher_id') === (string) $teacher->id ? 'selected' : '' }}>{{ $teacher->name }}</option>
                        @endforeach
                    </select>@include('partials.errors.field', ['field' => 'teacher_id'])
                </div>
                <div class="form-group">
                    <label for="my_class_id">Class</label>
                    <select id="my_class_id" name="my_class_id" class="form-control @error('my_class_id') is-invalid @enderror" required>
                        <option value="">Select a class</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ (string) old('my_class_id') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>@include('partials.errors.field', ['field' => 'my_class_id'])
                </div>
                <div class="form-group">
                    <label for="section_id">Section (optional)</label>
                    <select id="section_id" name="section_id" class="form-control @error('section_id') is-invalid @enderror">
                        <option value="">All sections</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" {{ (string) old('section_id') === (string) $section->id ? 'selected' : '' }}>{{ $section->name }} ({{ $section->my_class->name ?? '' }})</option>
                        @endforeach
                    </select>@include('partials.errors.field', ['field' => 'section_id'])
                </div>
                <button type="submit" class="btn btn-primary">Create Slot</button>
            </form>
        </div>
    </div>
@endsection
