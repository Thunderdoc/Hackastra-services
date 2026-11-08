@extends('layouts.master')
@section('page_title', 'Transport')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Transport &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- Beauty Wave 3: the slim module banner (partials/module_banner)
         opens the page; the old toolbar subtitle moves into the
         banner verbatim (one home for the sentence, not two — the
         Wave 2 books precedent), and its stats are the counts of
         the three lists below. Tables and controls are untouched. --}}
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-truck',
        'hkModTone' => 'orange',
        'hkModSub' => 'Bus routes, vehicles and student assignments.',
        'hkModStats' => [
            ['value' => number_format($routes->count()), 'label' => 'Routes'],
            ['value' => number_format($vehicles->count()), 'label' => 'Vehicles'],
            ['value' => number_format($assignments->count()), 'label' => 'Assignments'],
        ],
    ])
    @component('partials.patterns.list_page')
        @slot('title') Transport @endslot
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
        <table class="table hk-std hk-std-720">
            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
            <colgroup><col style="width:6%"><col style="width:22%"><col style="width:30%"><col style="width:9%"><col style="width:9%"><col style="width:24%"></colgroup>
            <thead><tr><th>S/N</th><th>Name</th><th>Description</th><th>Stops</th><th>Vehicles</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($routes as $r)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="hk-ellip">{{ $r->name }}</td>
                    <td class="hk-ellip">{{ $r->description }}</td>
                    <td class="hk-nw">{{ $r->stops_count }}</td>
                    <td class="hk-nw">{{ $r->vehicles_count }}</td>
                    <td>
                        @if(Qs::userIsTeamSAWrite())
                        <a href="{{ route('transport.routes.edit', ['transport_route' => Qs::hash($r->id)]) }}" class="btn btn-sm btn-primary">Edit / Stops</a>
                        <form method="post" action="{{ route('transport.routes.destroy', ['transport_route' => Qs::hash($r->id)]) }}" style="display:inline">@csrf @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete route {{ $r->name }} and its stops? This cannot be undone." data-confirm-title="Delete Route" data-confirm-action="Delete" data-confirm-danger>Delete</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">@include('partials.patterns.empty_state', ['icon' => 'icon-truck', 'message' => 'No routes yet.'])</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>

        <h4>Vehicles</h4>
        <div class="table-responsive">
        <table class="table hk-std hk-std-560">
            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
            <colgroup><col style="width:6%"><col style="width:24%"><col style="width:12%"><col style="width:30%"><col style="width:28%"></colgroup>
            <thead><tr><th>S/N</th><th>Vehicle No</th><th>Capacity</th><th>Route</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($vehicles as $v)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="hk-nw">{{ $v->vehicle_no }}</td>
                    <td class="hk-nw">{{ $v->capacity }}</td>
                    <td class="hk-ellip">{{ $v->route ? $v->route->name : '—' }}</td>
                    <td>
                        @if(Qs::userIsTeamSAWrite())
                        <a href="{{ route('transport.vehicles.edit', ['transport_vehicle' => Qs::hash($v->id)]) }}" class="btn btn-sm btn-primary">Edit</a>
                        <form method="post" action="{{ route('transport.vehicles.destroy', ['transport_vehicle' => Qs::hash($v->id)]) }}" style="display:inline">@csrf @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete vehicle {{ $v->vehicle_no }}? This cannot be undone." data-confirm-title="Delete Vehicle" data-confirm-action="Delete" data-confirm-danger>Delete</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">@include('partials.patterns.empty_state', ['icon' => 'icon-truck', 'message' => 'No vehicles yet.'])</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>

        <h4>Current Assignments</h4>
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
@endsection
