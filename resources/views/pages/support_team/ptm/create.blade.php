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
            @if($errors->any())
                <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
            <form method="POST" action="{{ route('ptm.store') }}">
                @csrf
                <div class="form-group">
                    <label for="date">Date</label>
                    <input id="date" type="date" name="date" class="form-control" required value="{{ old('date') }}">
                </div>
                <div class="form-group">
                    <label for="start_time">Start time</label>
                    <input id="start_time" type="time" name="start_time" class="form-control" required value="{{ old('start_time') }}">
                </div>
                <div class="form-group">
                    <label for="end_time">End time</label>
                    <input id="end_time" type="time" name="end_time" class="form-control" required value="{{ old('end_time') }}">
                </div>
                <div class="form-group">
                    <label for="teacher_id">Teacher</label>
                    <select id="teacher_id" name="teacher_id" class="form-control" required>
                        <option value="">Select a teacher</option>
                        @foreach($teachers as $teacher)
                            <option value="{{ $teacher->id }}" {{ (string) old('teacher_id') === (string) $teacher->id ? 'selected' : '' }}>{{ $teacher->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="my_class_id">Class</label>
                    <select id="my_class_id" name="my_class_id" class="form-control" required>
                        <option value="">Select a class</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ (string) old('my_class_id') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="section_id">Section (optional)</label>
                    <select id="section_id" name="section_id" class="form-control">
                        <option value="">All sections</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" {{ (string) old('section_id') === (string) $section->id ? 'selected' : '' }}>{{ $section->name }} ({{ $section->my_class->name ?? '' }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Create Slot</button>
            </form>
        </div>
    </div>
@endsection
