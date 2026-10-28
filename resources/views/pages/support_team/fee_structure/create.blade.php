@extends('layouts.master')
@section('page_title', 'New Fee Head')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder &nbsp;/&nbsp; New Fee Head
@endsection
@section('content')
    <div class="card">
        <div class="card-header"><h5 class="card-title">New Fee Head</h5></div>
        <div class="card-body">
            <form method="POST" action="{{ route('fee_structure.store') }}">
                @csrf
                <div class="form-group">
                    <label>Class</label>
                    <select name="my_class_id" class="form-control" required>
                        <option value="">Select class</option>
                        @foreach($my_classes as $mc)
                            <option value="{{ $mc->id }}" {{ old('my_class_id') == $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" class="form-control" value="{{ old('title') }}" required maxlength="255">
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" class="form-control" value="{{ old('amount') }}" required min="0" step="1">
                </div>
                <div class="form-group">
                    <label>Session / Year</label>
                    <input type="text" name="year" class="form-control" value="{{ old('year', $year) }}" required maxlength="50">
                </div>
                <p>Installments (optional). If you add any, they must sum to the head amount exactly, otherwise the save is refused. Leave all rows blank to save the head bare and add installments later from its detail page.</p>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>No.</th><th>Due Date</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            @for($i = 0; $i < 3; $i++)
                                <tr>
                                    <td><input type="number" name="installments[{{ $i }}][installment_no]" class="form-control" min="1" step="1" value="{{ old('installments.'.$i.'.installment_no') }}"></td>
                                    <td><input type="date" name="installments[{{ $i }}][due_date]" class="form-control" value="{{ old('installments.'.$i.'.due_date') }}"></td>
                                    <td><input type="number" name="installments[{{ $i }}][amount]" class="form-control" min="0" step="1" value="{{ old('installments.'.$i.'.amount') }}"></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary">Save Fee Head</button>
                <a href="{{ route('fee_structure.index') }}" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
