@extends('layouts.master')
@section('page_title', 'Edit Room - '.$room->room_no)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Hostel &nbsp;/&nbsp; <a href="{{ route('hostel.rooms.index') }}">Hostel Rooms</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Edit Room</h5>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('hostel.rooms.update', ['hostel_room' => $room->id]) }}">
                @csrf @method('put')
                <div class="form-group">
                    <label for="dorm_id">Dorm *</label>
                    <select id="dorm_id" name="dorm_id" required class="form-control @error('dorm_id') is-invalid @enderror">
                        @foreach($dorms as $dorm)
                            <option value="{{ $dorm->id }}" {{ old('dorm_id', $room->dorm_id) == $dorm->id ? 'selected' : '' }}>{{ $dorm->name }}</option>
                        @endforeach
                    </select>@include('partials.errors.field', ['field' => 'dorm_id'])
                </div>
                <div class="form-group">
                    <label for="room_no">Room No *</label>
                    <input id="room_no" name="room_no" value="{{ old('room_no', $room->room_no) }}" required type="text" class="form-control @error('room_no') is-invalid @enderror">@include('partials.errors.field', ['field' => 'room_no'])
                </div>
                <div class="form-group">
                    <label for="capacity">Capacity *</label>
                    <input id="capacity" name="capacity" value="{{ old('capacity', $room->capacity) }}" required type="number" min="1" class="form-control @error('capacity') is-invalid @enderror">@include('partials.errors.field', ['field' => 'capacity'])
                    <small class="form-text text-muted">Currently occupied by {{ $room->occupancy() }} student(s).</small>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('hostel.rooms.index') }}" class="btn btn-light">Back</a>
            </form>
        </div>
    </div>
@endsection
