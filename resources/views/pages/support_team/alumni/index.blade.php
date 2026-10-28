@extends('layouts.master')
@section('page_title', 'Alumni Directory')
@section('content')
    <div class="panel panel-flat">
        <div class="panel-heading">
            <h5 class="panel-title">Alumni Directory</h5>
            <p class="text-muted" style="margin: 6px 0 0;">
                Read-only directory of graduated students (StudentRecord rows with grad = 1).
                No records can be created, edited or deleted here, and graduated records are never modified.
                Graduation is shown from grad_date where set, otherwise from the session.
            </p>
        </div>
        <div class="panel-body">
            <form method="GET" action="{{ route('alumni.index') }}" class="form-inline" style="margin-bottom: 15px;">
                <div class="form-group" style="margin-right: 10px;">
                    <label for="class_id" style="margin-right: 5px;">Class</label>
                    <select name="class_id" id="class_id" class="form-control">
                        <option value="">All classes</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ (string) ($filters['class_id'] ?? '') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-right: 10px;">
                    <label for="session" style="margin-right: 5px;">Session</label>
                    <select name="session" id="session" class="form-control">
                        <option value="">All sessions</option>
                        @foreach($sessions as $session)
                            <option value="{{ $session }}" {{ ($filters['session'] ?? '') === $session ? 'selected' : '' }}>{{ $session }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-right: 10px;">
                    <label for="q" style="margin-right: 5px;">Search</label>
                    <input type="text" name="q" id="q" class="form-control" placeholder="Name or admission no." value="{{ $filters['q'] ?? '' }}">
                </div>
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('alumni.index') }}" class="btn btn-default">Clear</a>
            </form>

            @if($alumni->isEmpty())
                <p class="text-muted">No graduated students found. Try clearing the filters, or check back after students graduate.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Admission No.</th>
                                <th>Session</th>
                                <th>Graduation</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($alumni as $row)
                                <tr>
                                    <td data-label="Student">{{ optional($row->user)->name ?? '—' }}</td>
                                    <td data-label="Class">{{ optional($row->my_class)->name ?? '—' }}</td>
                                    <td data-label="Section">{{ optional($row->section)->name ?? '—' }}</td>
                                    <td data-label="Admission No.">{{ $row->adm_no ?? '—' }}</td>
                                    <td data-label="Session">{{ $row->session }}</td>
                                    <td data-label="Graduation">{{ $row->grad_date ?: $row->session }}</td>
                                    <td><a href="{{ route('alumni.show', $row->id) }}">View</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $alumni->links() }}
            @endif
        </div>
    </div>
@endsection
