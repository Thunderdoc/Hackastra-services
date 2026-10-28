@extends('layouts.master')
@section('page_title', 'Health Records')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Health Records</h5>
            @if($can_write)
                <a href="{{ route('health_records.create') }}" class="btn btn-primary btn-sm">New Health Record</a>
            @endif
        </div>
        <div class="card-body">
            <p class="text-muted">Clinic visit log. Only the records you are allowed to see are listed here; complaint and treatment details are shown on the individual record page only.</p>
            @if($records->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Referred</th>
                                <th>Recorded By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr>
                                    <td>{{ optional($record->visit_date)->format('d M Y') }}</td>
                                    <td>{{ optional($record->student)->name }}</td>
                                    <td>
                                        @if($record->is_referred)<span class="badge badge-warning">Referred</span>
                                        @else<span class="badge badge-secondary">No</span>@endif
                                    </td>
                                    <td>{{ optional($record->recorder)->name }}</td>
                                    <td><a href="{{ route('health_records.show', $record->id) }}" class="btn btn-light btn-sm">View</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted mb-0">No health records yet.</p>
            @endif
        </div>
    </div>
@endsection
