@extends('layouts.master')
@section('page_title', 'Hostel Allocations')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Hostel Allocations</h5>
            <div class="header-elements">
                <a href="{{ route('hostel.dorms.index') }}" class="btn btn-sm btn-primary">Dorms</a>
                <a href="{{ route('hostel.rooms.index') }}" class="btn btn-sm btn-primary">Rooms</a>
                @if(Qs::userIsTeamSAWrite())
                    <a href="{{ route('hostel.allocations.create') }}" class="btn btn-sm btn-success">Allocate Student</a>
                @endif
            </div>
        </div>
        <div class="card-body">
            @if($allocations->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Dorm</th>
                                <th>Room No</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($allocations as $allocation)
                                <tr>
                                    <td>{{ optional($allocation->user)->name }}</td>
                                    <td>{{ optional($allocation->dorm)->name }}</td>
                                    <td>{{ $allocation->dorm_room_no }}</td>
                                    <td>
                                        @if(Qs::userIsTeamSAWrite())
                                            <form method="post" action="{{ route('hostel.allocations.destroy', ['hostel_record' => $allocation->id]) }}" class="d-inline" onsubmit="return confirm('Deallocate this student from the hostel?')">
                                                @csrf @method('delete')
                                                <button type="submit" class="btn btn-sm btn-danger">Deallocate</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No students are currently allocated to a hostel.</p>
            @endif
        </div>
    </div>
@endsection
