@extends('layouts.master')
@section('page_title', $route ? 'Edit Transport Route' : 'New Transport Route')
@section('content')
    @component('partials.patterns.form_page', [
        'form_action' => $route ? route('transport.routes.update', ['transport_route' => Qs::hash($route->id)]) : route('transport.routes.store'),
        'form_method' => $route ? 'PUT' : 'POST',
        'submit_label' => 'Save Route',
    ])
        @slot('title') {{ $route ? 'Edit Route' : 'New Route' }} @endslot
        <div class="pat-form-section"><div class="pat-fields">
            <div class="pat-field">
                <label for="name">Name <span class="text-danger">*</span></label>
                <input id="name" name="name" value="{{ old('name', $route ? $route->name : '') }}" required type="text" class="form-control" placeholder="Route name">
            </div>
            <div class="pat-field">
                <label for="description">Description</label>
                <input id="description" name="description" value="{{ old('description', $route ? $route->description : '') }}" type="text" class="form-control" placeholder="Description">
            </div>
        </div></div>
    @endcomponent

    @if($route)
    <div class="card"><div class="card-body">
        <h4>Stops on this route</h4>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Seq</th><th>Stop</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($route->stops as $s)
                <tr>
                    <td>{{ $s->sequence }}</td>
                    <td>{{ $s->name }}</td>
                    <td>
                        <form method="post" action="{{ route('transport.stops.destroy', ['transport_stop' => Qs::hash($s->id)]) }}" style="display:inline">@csrf @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this stop?')">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">No stops yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @if(Qs::userIsTeamSAWrite())
        <form method="post" action="{{ route('transport.stops.store', ['transport_route' => Qs::hash($route->id)]) }}">
            @csrf
            <div class="form-group"><label for="stop_name">Stop name *</label>
                <input id="stop_name" name="name" required type="text" class="form-control" placeholder="Stop name"></div>
            <div class="form-group"><label for="sequence">Sequence</label>
                <input id="sequence" name="sequence" type="number" min="0" value="0" class="form-control"></div>
            <button type="submit" class="btn btn-primary">Add Stop</button>
        </form>
        @endif
    </div></div>
    @endif
@endsection
