@extends('layouts.master')
@section('page_title', 'Discipline Record')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Discipline &nbsp;/&nbsp; <a href="{{ route('discipline_records.index') }}">Discipline Records</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Discipline Record</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table hk-std hk-std-560">
                {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                <colgroup><col style="width:25%"><col style="width:75%"></colgroup>
                    <tbody>
                        <tr><th>Student</th><td>{{ optional($record->student)->name }}</td></tr>
                        <tr><th>Date</th><td class="hk-nw">{{ $record->incident_date->format('Y-m-d') }}</td></tr>
                        <tr><th>Type</th><td>{{ $record->typeLabel() }}</td></tr>
                        <tr><th>Description</th><td>{{ $record->description }}</td></tr>
                        <tr><th>Action Taken</th><td>{{ $record->action_taken ?: '—' }}</td></tr>
                        <tr><th>Recorded By</th><td>{{ optional($record->recorder)->name }}</td></tr>
                        <tr><th>Recorded On</th><td>{{ $record->created_at }}</td></tr>
                    </tbody>
                </table>
            </div>
            <a href="{{ route('discipline_records.index') }}" class="btn btn-light">Back to Records</a>
        </div>
    </div>
@endsection
