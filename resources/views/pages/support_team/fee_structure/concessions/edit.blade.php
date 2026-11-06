@extends('layouts.master')
@section('page_title', 'Edit Concession')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder &nbsp;/&nbsp; Edit Concession
@endsection
@section('content')
    <div class="card">
        <div class="card-header"><h5 class="card-title">Edit Concession</h5></div>
        <div class="card-body">
            <form method="POST" action="{{ route('fee_structure.concessions.update', ['concession' => $concession->id]) }}">
                @csrf
                @method('PUT')
                <div class="form-group">
                    <label>Fee Head</label>
                    <select name="fee_head_id" class="form-control" required>
                        @foreach($heads as $h)
                            <option value="{{ $h->id }}" {{ old('fee_head_id', $concession->fee_head_id) == $h->id ? 'selected' : '' }}>{{ $h->title }} — {{ optional($h->my_class)->name }} ({{ $h->year }}, {{ number_format($h->amount) }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <select class="form-control hk-cascade-class">
                        <option value="">Choose a class…</option>
                        @foreach($classes as $hkClass)
                            <option value="{{ $hkClass->id }}">{{ $hkClass->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <select class="form-control hk-cascade-section">
                        <option value="">Choose a class first</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Student</label>
                    <select name="student_record_id" class="form-control hk-cascade-student" required data-value-key="id"
                            @if($preselected) data-preselect-class="{{ $preselected->my_class_id }}" data-preselect-section="{{ $preselected->section_id }}" data-preselect-value="{{ $preselected->id }}" data-preselect-label="{{ optional($preselected->user)->name }} ({{ $preselected->adm_no }})" @endif>
                        <option value="">Choose a student…</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" class="form-control" required>
                        <option value="amount" {{ old('type', $concession->type) == 'amount' ? 'selected' : '' }}>Fixed amount</option>
                        <option value="percent" {{ old('type', $concession->type) == 'percent' ? 'selected' : '' }}>Percent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value</label>
                    <input type="number" name="value" class="form-control" value="{{ old('value', $concession->value) }}" required min="0" step="0.01">
                    <small>Fixed-amount value must not exceed the head amount; percent value must not exceed 100.</small>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" name="reason" class="form-control" value="{{ old('reason', $concession->reason) }}" maxlength="255">
                </div>
                <button type="submit" class="btn btn-primary">Update Concession</button>
                <a href="{{ route('fee_structure.index') }}" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    @include('partials.student_cascade_js')
@endsection
