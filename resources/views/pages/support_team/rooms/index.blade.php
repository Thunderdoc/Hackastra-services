@extends('layouts.master')
@section('page_title', 'Rooms & Labs')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Rooms &amp; Labs</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">The school's teaching spaces. When a timetable is generated, rooms are assigned automatically — one room hosts one period at a time, subjects marked <strong>Needs a lab room</strong> only ever land in labs, and the smallest room that seats the class is preferred. If a class is bigger than its room, the generation result says so.</p>

            @if(Qs::userIsTeamSAWrite())
            <form method="POST" action="{{ route('rooms.store') }}" class="form-inline mb-3" style="gap:8px;flex-wrap:wrap">
                @csrf
                <input type="text" name="name" class="form-control" maxlength="60" required placeholder="Room name (e.g. Room 12, Physics Lab)">
                <select name="kind" class="form-control">
                    @foreach($kinds as $k => $label)
                        <option value="{{ $k }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input type="number" name="capacity" class="form-control" min="0" max="500" required placeholder="Seats" style="width:110px">
                <button class="btn btn-primary">Add room</button>
            </form>
            @endif

            @if($rooms->isEmpty())
                <p class="text-muted mb-0">No rooms yet. Add the school's classrooms and labs above — generation works without rooms too, it simply leaves periods room-less and says which subjects still need one.</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Room</th><th>Type</th><th>Seats</th><th>In use</th>@if(Qs::userIsTeamSAWrite())<th style="width:340px">Edit</th>@endif</tr></thead>
                        <tbody>
                        @foreach($rooms as $room)
                            <tr>
                                <td>{{ $room->name }}</td>
                                <td>{{ $kinds[$room->kind] ?? $room->kind }}</td>
                                <td>{{ $room->capacity }}</td>
                                <td>{{ in_array($room->id, $used_room_ids) ? 'Yes' : '—' }}</td>
                                @if(Qs::userIsTeamSAWrite())
                                <td>
                                    <form method="POST" action="{{ route('rooms.update', $room->id) }}" class="form-inline" style="gap:6px">
                                        @csrf @method('put')
                                        <input type="text" name="name" class="form-control form-control-sm" value="{{ $room->name }}" maxlength="60" required style="width:130px">
                                        <select name="kind" class="form-control form-control-sm">
                                            @foreach($kinds as $k => $label)
                                                <option value="{{ $k }}" {{ $room->kind === $k ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input type="number" name="capacity" class="form-control form-control-sm" value="{{ $room->capacity }}" min="0" max="500" required style="width:80px">
                                        <button class="btn btn-light btn-sm">Save</button>
                                    </form>
                                    <form method="post" action="{{ route('rooms.destroy', $room->id) }}" style="display:inline" data-confirm="Delete this room?" data-confirm-title="Delete Room" data-confirm-action="Delete" data-confirm-danger>@csrf @method('delete')<button class="btn btn-light btn-sm text-danger">Delete</button></form>
                                </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
