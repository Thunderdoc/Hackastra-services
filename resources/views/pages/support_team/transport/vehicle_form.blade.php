@extends('layouts.master')
@section('page_title', $vehicle ? 'Edit Vehicle' : 'New Vehicle')
@section('content')
    @component('partials.patterns.form_page', [
        'form_action' => $vehicle ? route('transport.vehicles.update', ['transport_vehicle' => Qs::hash($vehicle->id)]) : route('transport.vehicles.store'),
        'form_method' => $vehicle ? 'PUT' : 'POST',
        'submit_label' => 'Save Vehicle',
    ])
        @slot('title') {{ $vehicle ? 'Edit Vehicle' : 'New Vehicle' }} @endslot
        <div class="pat-form-section"><div class="pat-fields">
            <div class="pat-field">
                <label for="vehicle_no">Vehicle No <span class="text-danger">*</span></label>
                <input id="vehicle_no" name="vehicle_no" value="{{ old('vehicle_no', $vehicle ? $vehicle->vehicle_no : '') }}" required type="text" class="form-control" placeholder="e.g. TN-59-AB-1234">
            </div>
            <div class="pat-field">
                <label for="capacity">Capacity <span class="text-danger">*</span></label>
                <input id="capacity" name="capacity" value="{{ old('capacity', $vehicle ? $vehicle->capacity : '') }}" required type="number" min="1" class="form-control">
            </div>
            <div class="pat-field">
                <label for="transport_route_id">Route (optional)</label>
                <select id="transport_route_id" name="transport_route_id" class="form-control">
                    <option value="">— No route —</option>
                    @foreach($routes as $r)
                    <option value="{{ $r->id }}" {{ (string) old('transport_route_id', $vehicle ? $vehicle->transport_route_id : '') === (string) $r->id ? 'selected' : '' }}>{{ $r->name }}</option>
                    @endforeach
                </select>
            </div>
        </div></div>
    @endcomponent
@endsection
