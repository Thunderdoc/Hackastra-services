@extends('layouts.master')
@section('page_title', 'Hostel Allocations')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Hostel Allocations</h5>
            <div class="header-elements">
                <a href="{{ route('hostel.dorms.index') }}" class="btn btn-sm btn-primary">Dorms</a>
                <a href="{{ route('hostel.rooms.index') }}" class="btn btn-sm btn-primary">Rooms</a>
                @if(auth()->user() && auth()->user()->user_type === 'hostel')
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
                                        @if(auth()->user() && auth()->user()->user_type === 'hostel')
                                            <form method="post" action="{{ route('hostel.allocations.destroy', ['hostel_record' => $allocation->id]) }}" class="d-inline" data-confirm="Deallocate {{ optional($allocation->user)->name ?: 'this student' }} from the hostel?" data-confirm-title="Deallocate Student" data-confirm-action="Deallocate" data-confirm-danger>
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
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => 'No students are currently allocated to a hostel.',
                ])
            @endif
        </div>
    </div>
@endsection
