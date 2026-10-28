@extends('layouts.master')
@section('page_title', 'Discipline Record')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Discipline Record</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <tbody>
                        <tr><th>Student</th><td>{{ optional($record->student)->name }}</td></tr>
                        <tr><th>Date</th><td>{{ $record->incident_date->format('Y-m-d') }}</td></tr>
                        <tr><th>Type</th><td>{{ $record->typeLabel() }}</td></tr>
                        <tr><th>Description</th><td>{{ $record->description }}</td></tr>
                        <tr><th>Action Taken</th><td>{{ $record->action_taken ?: '—' }}</td></tr>
                        <tr><th>Recorded By</th><td>{{ optional($record->recorder)->name }}</td></tr>
                        <tr><th>Recorded On</th><td>{{ $record->created_at }}</td></tr>
                    </tbody>
                </table>
            </div>
            <a href="{{ route('discipline_records.index') }}" class="btn btn-light">Back to Records</a>
        </div>
    </div>
@endsection
