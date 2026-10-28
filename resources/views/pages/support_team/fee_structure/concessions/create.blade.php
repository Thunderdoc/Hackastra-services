@extends('layouts.master')
@section('page_title', 'New Concession')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder &nbsp;/&nbsp; New Concession
@endsection
@section('content')
    <div class="card">
        <div class="card-header"><h5 class="card-title">New Concession</h5></div>
        <div class="card-body">
            <form method="POST" action="{{ route('fee_structure.concessions.store') }}">
                @csrf
                <div class="form-group">
                    <label>Fee Head</label>
                    <select name="fee_head_id" class="form-control" required>
                        <option value="">Select fee head</option>
                        @foreach($heads as $h)
                            <option value="{{ $h->id }}" {{ old('fee_head_id', optional($selected_head)->id) == $h->id ? 'selected' : '' }}>{{ $h->title }} — {{ optional($h->my_class)->name }} ({{ $h->year }}, {{ number_format($h->amount) }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Student</label>
                    <select name="student_record_id" class="form-control" required>
                        <option value="">Select student</option>
                        @foreach($students as $sr)
                            <option value="{{ $sr->id }}" {{ old('student_record_id') == $sr->id ? 'selected' : '' }}>{{ optional($sr->user)->name }} — {{ optional($sr->my_class)->name }} ({{ $sr->adm_no }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" class="form-control" required>
                        <option value="amount" {{ old('type') == 'amount' ? 'selected' : '' }}>Fixed amount</option>
                        <option value="percent" {{ old('type') == 'percent' ? 'selected' : '' }}>Percent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value</label>
                    <input type="number" name="value" class="form-control" value="{{ old('value') }}" required min="0" step="0.01">
                    <small>Fixed-amount value must not exceed the head amount; percent value must not exceed 100.</small>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" name="reason" class="form-control" value="{{ old('reason') }}" maxlength="255">
                </div>
                <button type="submit" class="btn btn-primary">Save Concession</button>
                <a href="{{ route('fee_structure.index') }}" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
