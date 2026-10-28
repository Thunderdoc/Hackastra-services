@extends('layouts.master')
@section('page_title', 'Edit Room - '.$room->room_no)
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Edit Room</h5>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
            <form method="post" action="{{ route('hostel.rooms.update', ['hostel_room' => $room->id]) }}">
                @csrf @method('put')
                <div class="form-group">
                    <label for="dorm_id">Dorm *</label>
                    <select id="dorm_id" name="dorm_id" required class="form-control">
                        @foreach($dorms as $dorm)
                            <option value="{{ $dorm->id }}" {{ old('dorm_id', $room->dorm_id) == $dorm->id ? 'selected' : '' }}>{{ $dorm->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="room_no">Room No *</label>
                    <input id="room_no" name="room_no" value="{{ old('room_no', $room->room_no) }}" required type="text" class="form-control">
                </div>
                <div class="form-group">
                    <label for="capacity">Capacity *</label>
                    <input id="capacity" name="capacity" value="{{ old('capacity', $room->capacity) }}" required type="number" min="1" class="form-control">
                    <small class="form-text text-muted">Currently occupied by {{ $room->occupancy() }} student(s).</small>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('hostel.rooms.index') }}" class="btn btn-light">Back</a>
            </form>
        </div>
    </div>
@endsection
