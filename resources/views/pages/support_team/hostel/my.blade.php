@extends('layouts.master')
@section('page_title', 'My Hostel Allocation')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">My Hostel Allocation</h5>
        </div>
        <div class="card-body">
            @if($records->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Dorm</th>
                                <th>Room No</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr>
                                    <td>{{ optional($record->user)->name }}</td>
                                    <td>{{ optional($record->dorm)->name ?: 'Not allocated' }}</td>
                                    <td>{{ $record->dorm_room_no ?: 'Not allocated' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No hostel allocation found.</p>
            @endif
        </div>
    </div>
@endsection
