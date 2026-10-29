@extends('layouts.master')
@section('page_title', 'Transport Assignments')
@section('content')
    @component('partials.patterns.list_page')
        @slot('title') Transport Assignments @endslot
        @slot('subtitle') Assign a student to a vehicle and a stop on that vehicle's route. Reassigning replaces the old assignment. @endslot
        @slot('action')
            <a href="{{ route('transport.index') }}" class="btn btn-primary">Back to Transport</a>
        @endslot
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>S/N</th><th>Student</th><th>Class</th><th>Vehicle</th><th>Route</th><th>Stop</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($assignments as $a)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $a->studentRecord && $a->studentRecord->user ? $a->studentRecord->user->name : '—' }}</td>
                    <td>{{ $a->studentRecord && $a->studentRecord->my_class ? $a->studentRecord->my_class->name : '—' }}</td>
                    <td>{{ $a->vehicle ? $a->vehicle->vehicle_no : '—' }}</td>
                    <td>{{ $a->vehicle && $a->vehicle->route ? $a->vehicle->route->name : '—' }}</td>
                    <td>{{ $a->stop ? $a->stop->name : '—' }}</td>
                    <td>
                        @if(Qs::userIsTeamSAWrite())
                        <form method="post" action="{{ route('transport.assignments.destroy', ['transport_assignment' => Qs::hash($a->id)]) }}" style="display:inline">@csrf @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove this assignment?')">Unassign</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">No assignments yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    @endcomponent

    @if(Qs::userIsTeamSAWrite())
    @component('partials.patterns.form_page', ['form_action' => route('transport.assignments.store'), 'submit_label' => 'Assign Student', 'back' => route('transport.index')])
        @slot('title') New Assignment @endslot
        <div class="pat-form-section"><div class="pat-fields">
            <div class="pat-field">
                <label for="student_record_id">Student *</label>
                <select id="student_record_id" name="student_record_id" required class="form-control">
                    <option value="">Select student</option>
                    @foreach($students as $s)
                    <option value="{{ $s->id }}">{{ $s->user ? $s->user->name : 'Student' }}{{ $s->my_class ? ' — '.$s->my_class->name : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label for="transport_vehicle_id">Vehicle *</label>
                <select id="transport_vehicle_id" name="transport_vehicle_id" required class="form-control">
                    <option value="">Select vehicle</option>
                    @foreach($vehicles as $v)
                    <option value="{{ $v->id }}">{{ $v->vehicle_no }}{{ $v->route ? ' — '.$v->route->name : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label for="transport_stop_id">Stop *</label>
                <select id="transport_stop_id" name="transport_stop_id" required class="form-control">
                    <option value="">Select stop</option>
                    @foreach($stops as $s)
                    <option value="{{ $s->id }}">{{ $s->route ? $s->route->name.' — ' : '' }}{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
        </div></div>
    @endcomponent
    @endif
@endsection
