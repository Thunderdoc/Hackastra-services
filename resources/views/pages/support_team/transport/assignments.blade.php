@extends('layouts.master')
@section('page_title', 'Transport Assignments')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Transport &nbsp;/&nbsp; <a href="{{ route('transport.index') }}">Transport</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    @component('partials.patterns.list_page')
        @slot('title') Transport Assignments @endslot
        @slot('subtitle') Assign a student to a vehicle and a stop on that vehicle's route. Reassigning replaces the old assignment. @endslot
        @slot('action')
            <a href="{{ route('transport.index') }}" class="btn btn-primary">Back to Transport</a>
        @endslot
        <div class="table-responsive">
        <table class="table hk-std hk-std-720">
            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
            <colgroup><col style="width:6%"><col style="width:24%"><col style="width:12%"><col style="width:14%"><col style="width:18%"><col style="width:14%"><col style="width:12%"></colgroup>
            <thead><tr><th>S/N</th><th>Student</th><th>Class</th><th>Vehicle</th><th>Route</th><th>Stop</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($assignments as $a)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="hk-ellip">{{ $a->studentRecord && $a->studentRecord->user ? $a->studentRecord->user->name : '—' }}</td>
                    <td>{{ $a->studentRecord && $a->studentRecord->my_class ? $a->studentRecord->my_class->name : '—' }}</td>
                    <td class="hk-nw">{{ $a->vehicle ? $a->vehicle->vehicle_no : '—' }}</td>
                    <td>{{ $a->vehicle && $a->vehicle->route ? $a->vehicle->route->name : '—' }}</td>
                    <td>{{ $a->stop ? $a->stop->name : '—' }}</td>
                    <td>
                        @if(Qs::userIsTeamSAWrite())
                        <form method="post" action="{{ route('transport.assignments.destroy', ['transport_assignment' => Qs::hash($a->id)]) }}" style="display:inline">@csrf @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove the transport assignment for {{ $a->studentRecord && $a->studentRecord->user ? $a->studentRecord->user->name : 'this student' }}?" data-confirm-title="Remove Assignment" data-confirm-action="Unassign" data-confirm-danger>Unassign</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">@include('partials.patterns.empty_state', ['icon' => 'icon-users', 'message' => 'No assignments yet.'])</td></tr>
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
                <label>Class</label>
                <select class="form-control hk-cascade-class">
                    <option value="">Choose a class…</option>
                    @foreach($classes as $hkClass)
                    <option value="{{ $hkClass->id }}">{{ $hkClass->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label>Section</label>
                <select class="form-control hk-cascade-section">
                    <option value="">Choose a class first</option>
                </select>
            </div>
            <div class="pat-field">
                <label for="student_record_id">Student *</label>
                <select id="student_record_id" name="student_record_id" required class="form-control hk-cascade-student" data-value-key="id">
                    <option value="">Choose a student…</option>
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

@section('scripts')
    @include('partials.student_cascade_js')
@endsection
