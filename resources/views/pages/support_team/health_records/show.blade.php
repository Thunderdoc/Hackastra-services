@extends('layouts.master')
@section('page_title', 'Health Record')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Health Record — {{ optional($record->student)->name }}</h5>
        </div>
        <div class="card-body">
            <p><strong>Student:</strong> {{ optional($record->student)->name }}@if($student_record) — {{ optional($student_record->my_class)->name }}@if($student_record->section) - Section {{ $student_record->section->name }}@endif @endif</p>
            <p><strong>Visit date:</strong> {{ optional($record->visit_date)->format('d M Y') }}</p>
            <p><strong>Complaint:</strong> {{ $record->complaint }}</p>
            <p><strong>Treatment:</strong> {{ $record->treatment ?: 'Not recorded' }}</p>
            <p><strong>Referred:</strong> @if($record->is_referred) Yes @if($record->referred_to) — {{ $record->referred_to }} @endif @else No @endif</p>
            <p><strong>Recorded by:</strong> {{ optional($record->recorder)->name }}</p>
            <p><a href="{{ route('health_records.index') }}" class="btn btn-light btn-sm">Back to Health Records</a></p>
        </div>
    </div>
@endsection
