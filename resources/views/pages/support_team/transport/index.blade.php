@extends('layouts.master')
@section('page_title', 'Transport')
@section('content')
    @component('partials.patterns.list_page')
        @slot('title') Transport @endslot
        @slot('subtitle') Bus routes, vehicles and student assignments. @endslot
        @slot('action')
            @if(Qs::userIsTeamSAWrite())
            <a href="{{ route('transport.routes.create') }}" class="btn btn-primary">New Route</a>
            <a href="{{ route('transport.vehicles.create') }}" class="btn btn-primary">New Vehicle</a>
            <a href="{{ route('transport.assignments') }}" class="btn btn-primary">Assignments</a>
            <a href="{{ route('transport.fees') }}" class="btn btn-primary">Fees</a>
            @endif
        @endslot

        <h4>Routes</h4>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>S/N</th><th>Name</th><th>Description</th><th>Stops</th><th>Vehicles</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($routes as $r)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $r->name }}</td>
                    <td>{{ $r->description }}</td>
                    <td>{{ $r->stops_count }}</td>
                    <td>{{ $r->vehicles_count }}</td>
                    <td>
                        @if(Qs::userIsTeamSAWrite())
                        <a href="{{ route('transport.routes.edit', ['transport_route' => Qs::hash($r->id)]) }}" class="btn btn-sm btn-primary">Edit / Stops</a>
                        <form method="post" action="{{ route('transport.routes.destroy', ['transport_route' => Qs::hash($r->id)]) }}" style="display:inline">@csrf @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this route and its stops?')">Delete</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No routes yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>

        <h4>Vehicles</h4>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>S/N</th><th>Vehicle No</th><th>Capacity</th><th>Route</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($vehicles as $v)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $v->vehicle_no }}</td>
                    <td>{{ $v->capacity }}</td>
                    <td>{{ $v->route ? $v->route->name : '—' }}</td>
                    <td>
                        @if(Qs::userIsTeamSAWrite())
                        <a href="{{ route('transport.vehicles.edit', ['transport_vehicle' => Qs::hash($v->id)]) }}" class="btn btn-sm btn-primary">Edit</a>
                        <form method="post" action="{{ route('transport.vehicles.destroy', ['transport_vehicle' => Qs::hash($v->id)]) }}" style="display:inline">@csrf @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this vehicle?')">Delete</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">No vehicles yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>

        <h4>Current Assignments</h4>
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
@endsection
