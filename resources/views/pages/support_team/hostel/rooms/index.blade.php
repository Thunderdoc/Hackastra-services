@extends('layouts.master')
@section('page_title', 'Hostel Rooms')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Hostel Rooms</h5>
            <div class="header-elements">
                <a href="{{ route('hostel.dorms.index') }}" class="btn btn-sm btn-primary">Dorms</a>
                <a href="{{ route('hostel.allocations.index') }}" class="btn btn-sm btn-primary">Allocations</a>
            </div>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
            @if($rooms->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Dorm</th>
                                <th>Room No</th>
                                <th>Capacity</th>
                                <th>Occupied</th>
                                <th>Available</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rooms as $room)
                                <tr>
                                    <td>{{ optional($room->dorm)->name }}</td>
                                    <td>{{ $room->room_no }}</td>
                                    <td>{{ $room->capacity }}</td>
                                    <td>{{ $room->occupancy() }} / {{ $room->capacity }}</td>
                                    <td>{{ $room->available() }}</td>
                                    <td>
                                        @if(Qs::userIsTeamSAWrite())
                                            <a href="{{ route('hostel.rooms.edit', ['hostel_room' => $room->id]) }}" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="post" action="{{ route('hostel.rooms.destroy', ['hostel_room' => $room->id]) }}" class="d-inline" onsubmit="return confirm('Delete this room? Occupied rooms cannot be deleted.')">
                                                @csrf @method('delete')
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No rooms found yet. Create the first room using the form below.</p>
            @endif
            @if(Qs::userIsTeamSAWrite())
                <hr>
                <h6>Create Room</h6>
                <form method="post" action="{{ route('hostel.rooms.store') }}">
                    @csrf
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
                        <label for="room_no">Room No *</label>
                        <input id="room_no" name="room_no" value="{{ old('room_no') }}" required type="text" class="form-control" placeholder="e.g. A-101">
                    </div>
                    <div class="form-group">
                        <label for="capacity">Capacity *</label>
                        <input id="capacity" name="capacity" value="{{ old('capacity') }}" required type="number" min="1" class="form-control" placeholder="Number of beds">
                    </div>
                    <button type="submit" class="btn btn-primary">Create Room</button>
                </form>
            @endif
        </div>
    </div>
@endsection
