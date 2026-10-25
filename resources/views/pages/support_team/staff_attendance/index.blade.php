@extends('layouts.master')
@section('page_title', 'Staff Attendance')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Staff Attendance — {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('staff_attendance.index') }}" class="form-inline mb-3">
                <label class="mr-2" for="date">Date</label>
                <input type="date" name="date" id="date" value="{{ $date }}" class="form-control mr-2" required>
                <button type="submit" class="btn btn-light btn-sm">Show Register</button>
            </form>

            @if($locked)
                <div class="alert alert-info">This date is already marked and cannot be changed — the same lock rule as the student register.</div>
            @endif

            <form method="POST" action="{{ route('staff_attendance.store') }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Name</th><th>Role</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach($staff as $s)
                            <tr>
                                <td>{{ $s->name }}</td>
                                <td>{{ ucfirst($s->user_type) }}</td>
                                <td>
                                    @if($locked)
                                        <span class="badge badge-{{ ($marks[$s->id]->status ?? '') === 'present' ? 'success' : ((($marks[$s->id]->status ?? '') === 'late') ? 'warning' : 'danger') }}">{{ ucfirst($marks[$s->id]->status ?? '—') }}</span>
                                    @else
                                        <select name="status[{{ $s->id }}]" class="form-control form-control-sm" required>
                                            <option value="present" selected>Present</option>
                                            <option value="late">Late</option>
                                            <option value="absent">Absent</option>
                                        </select>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if(!$locked)
                    <button type="submit" class="btn btn-success">Save Attendance</button>
                @endif
            </form>
        </div>
    </div>
@endsection
