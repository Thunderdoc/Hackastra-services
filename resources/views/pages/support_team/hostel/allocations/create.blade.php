@extends('layouts.master')
@section('page_title', 'Allocate Hostel Room')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Allocate Hostel Room</h5>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
            <form method="post" action="{{ route('hostel.allocations.store') }}">
                @csrf
                <div class="form-group">
                    <label for="student_record_id">Student *</label>
                    <select id="student_record_id" name="student_record_id" required class="form-control">
                        <option value="">Select student</option>
                        @foreach($students as $student)
                            <option value="{{ $student->id }}" {{ old('student_record_id') == $student->id ? 'selected' : '' }}>{{ optional($student->user)->name }} ({{ $student->adm_no }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="dorm_id">Dorm *</label>
                    <select id="dorm_id" name="dorm_id" required class="form-control">
                        <option value="">Select dorm</option>
                        @foreach($dorms as $dorm)
                            <option value="{{ $dorm->id }}" {{ old('dorm_id') == $dorm->id ? 'selected' : '' }}>{{ $dorm->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="room_no">Room *</label>
                    <select id="room_no" name="room_no" required class="form-control">
                        <option value="">Select room</option>
                        @foreach($rooms as $room)
                            <option value="{{ $room->room_no }}" {{ old('room_no') == $room->room_no ? 'selected' : '' }}>{{ optional($room->dorm)->name }} - {{ $room->room_no }} ({{ $room->occupancy() }} / {{ $room->capacity }} occupied)</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">The room must belong to the selected dorm; a full room is refused.</small>
                </div>
                <button type="submit" class="btn btn-primary">Allocate</button>
                <a href="{{ route('hostel.allocations.index') }}" class="btn btn-light">Back</a>
            </form>
        </div>
    </div>
@endsection
