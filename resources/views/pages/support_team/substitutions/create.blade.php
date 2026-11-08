@extends('layouts.master')
@section('page_title', 'Arrange Substitution')
@section('own_page_back', true)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('substitutions.index') }}">Substitutions</a> &nbsp;/&nbsp; Arrange
@endsection
@section('page_actions')
    <a href="{{ route('substitutions.index') }}" class="btn btn-light">Back to Substitutions</a>
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Arrange Substitution</h5>
            <p class="text-muted mb-0">Pick the date and period, then the teacher covering it. The date must fall on the period's weekday, and the substitute must be free at that time.</p>
        </div>
        <div class="card-body">

            <form method="POST" action="{{ route('substitutions.store') }}">
                @csrf
                <div class="form-group">
                    <label for="sub_date">Date <span class="text-danger">*</span></label>
                    <input type="date" name="date" id="sub_date" class="form-control @error('date') is-invalid @enderror" value="{{ old('date', $selected_date) }}" required>@include('partials.errors.field', ['field' => 'date'])
                </div>
                <div class="form-group">
                    <label for="sub_period">Period <span class="text-danger">*</span></label>
                    <select name="time_table_id" id="sub_period" class="form-control @error('time_table_id') is-invalid @enderror" required>
                        <option value="">Select a period</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" {{ (string) old('time_table_id', $selected_period) === (string) $period->id ? 'selected' : '' }}>
                                {{ optional(optional($period->tt_record)->my_class)->name }}
                                @if(optional($period->tt_record)->section)
                                    — {{ $period->tt_record->section->name }}
                                @else
                                    — All sections
                                @endif
                                — {{ optional($period->subject)->name }} ({{ $period->day }} {{ optional($period->time_slot)->full }})
                            </option>
                        @endforeach
                    </select>@include('partials.errors.field', ['field' => 'time_table_id'])
                </div>
                <div class="form-group">
                    <label for="sub_substitute">Substitute Teacher <span class="text-danger">*</span></label>
                    <select name="substitute_teacher_id" id="sub_substitute" class="form-control @error('substitute_teacher_id') is-invalid @enderror" required>
                        <option value="">Select a teacher</option>
                        @foreach($teachers as $teacher)
                            <option value="{{ $teacher->id }}" {{ (string) old('substitute_teacher_id') === (string) $teacher->id ? 'selected' : '' }}>{{ $teacher->name }}</option>
                        @endforeach
                    </select>@include('partials.errors.field', ['field' => 'substitute_teacher_id'])
                </div>
                <div class="form-group">
                    <label for="sub_reason">Reason</label>
                    <input type="text" name="reason" id="sub_reason" class="form-control @error('reason') is-invalid @enderror" value="{{ old('reason') }}" maxlength="500" placeholder="e.g. Sick leave">@include('partials.errors.field', ['field' => 'reason'])
                </div>
                <button type="submit" class="btn btn-primary">Arrange Substitution</button>
                <a href="{{ route('substitutions.index') }}" class="btn btn-light ml-2">Cancel</a>
            </form>
        </div>
    </div>
@endsection
