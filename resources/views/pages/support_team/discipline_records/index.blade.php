@extends('layouts.master')
@section('page_title', 'Discipline Records')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Discipline Records</h5>
            @if($canWrite)
                <a href="{{ route('discipline_records.create') }}" class="btn btn-primary btn-sm">New Record</a>
            @endif
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('discipline_records.index') }}">
                <div class="form-group">
                    <label for="dr-type">Type</label>
                    <select id="dr-type" name="type" class="form-control" onchange="this.form.submit()">
                        <option value="">All types</option>
                        @foreach($types as $val => $label)
                            <option value="{{ $val }}" {{ $type === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            @if($records->count() === 0)
                <p>No discipline records yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Action Taken</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr>
                                    <td>{{ $record->incident_date->format('Y-m-d') }}</td>
                                    <td>{{ optional($record->student)->name }}</td>
                                    <td>{{ $record->typeLabel() }}</td>
                                    <td><a href="{{ route('discipline_records.show', $record->id) }}">{{ \Illuminate\Support\Str::limit($record->description, 80) }}</a></td>
                                    <td>{{ $record->action_taken ?: '—' }}</td>
                                    <td>{{ optional($record->recorder)->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $records->links() }}
            @endif
        </div>
    </div>
@endsection
