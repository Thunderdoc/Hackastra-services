@extends('layouts.master')
@section('page_title', 'Hostel Rooms')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Hostel &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
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
            @if($rooms->count())
                <div class="table-responsive">
                    <table class="table hk-std hk-std-720">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:24%"><col style="width:13%"><col style="width:12%"><col style="width:13%"><col style="width:12%"><col style="width:26%"></colgroup>
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
                                    <td class="hk-nw">{{ $room->room_no }}</td>
                                    <td class="hk-nw">{{ $room->capacity }}</td>
                                    <td class="hk-nw">{{ $room->occupancy() }} / {{ $room->capacity }}</td>
                                    <td class="hk-nw">{{ $room->available() }}</td>
                                    <td>
                                        @if(auth()->user() && auth()->user()->user_type === 'hostel')
                                            <a href="{{ route('hostel.rooms.edit', ['hostel_room' => $room->id]) }}" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="post" action="{{ route('hostel.rooms.destroy', ['hostel_room' => $room->id]) }}" class="d-inline" data-confirm="Delete room {{ $room->room_no }}? Occupied rooms cannot be deleted." data-confirm-title="Delete Room" data-confirm-action="Delete" data-confirm-danger>
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
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-home9',
                    'message' => 'No rooms found yet. Create the first room using the form below.',
                ])
            @endif
            @if(auth()->user() && auth()->user()->user_type === 'hostel')
                <hr>
                <h6>Create Room</h6>
                <form method="post" action="{{ route('hostel.rooms.store') }}">
                    @csrf
                    <div class="form-group">
                        <label for="dorm_id">Dorm *</label>
                        <select id="dorm_id" name="dorm_id" required class="form-control @error('dorm_id') is-invalid @enderror">
                            <option value="">Select dorm</option>
                            @foreach($dorms as $dorm)
                                <option value="{{ $dorm->id }}" {{ old('dorm_id') == $dorm->id ? 'selected' : '' }}>{{ $dorm->name }}</option>
                            @endforeach
                        </select>@include('partials.errors.field', ['field' => 'dorm_id'])
                    </div>
                    <div class="form-group">
                        <label for="room_no">Room No *</label>
                        <input id="room_no" name="room_no" value="{{ old('room_no') }}" required type="text" class="form-control @error('room_no') is-invalid @enderror" placeholder="e.g. A-101">@include('partials.errors.field', ['field' => 'room_no'])
                    </div>
                    <div class="form-group">
                        <label for="capacity">Capacity *</label>
                        <input id="capacity" name="capacity" value="{{ old('capacity') }}" required type="number" min="1" class="form-control @error('capacity') is-invalid @enderror" placeholder="Number of beds">@include('partials.errors.field', ['field' => 'capacity'])
                    </div>
                    <button type="submit" class="btn btn-primary">Create Room</button>
                </form>
            @endif
        </div>
    </div>
@endsection
