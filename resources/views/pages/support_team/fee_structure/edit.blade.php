@extends('layouts.master')
@section('page_title', 'Edit Fee Head')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder &nbsp;/&nbsp; Edit Fee Head
@endsection
@section('content')
    <div class="card">
        <div class="card-header"><h5 class="card-title">Edit Fee Head</h5></div>
        <div class="card-body">
            <form method="POST" action="{{ route('fee_structure.update', ['fee_head' => $head->id]) }}">
                @csrf
                @method('PUT')
                <div class="form-group">
                    <label>Class</label>
                    <select name="my_class_id" class="form-control" required>
                        @foreach($my_classes as $mc)
                            <option value="{{ $mc->id }}" {{ old('my_class_id', $head->my_class_id) == $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" class="form-control" value="{{ old('title', $head->title) }}" required maxlength="255">
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" class="form-control" value="{{ old('amount', $head->amount) }}" required min="0" step="1">
                </div>
                <div class="form-group">
                    <label>Session / Year</label>
                    <input type="text" name="year" class="form-control" value="{{ old('year', $head->year) }}" required maxlength="50">
                </div>
                <p>Installments replace the current set on save. If you add any, they must sum to the head amount exactly, otherwise the save is refused. Fully blank rows are ignored.</p>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>No.</th><th>Due Date</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            @foreach($head->installments as $idx => $inst)
                                <tr>
                                    <td><input type="number" name="installments[{{ $idx }}][installment_no]" class="form-control" min="1" step="1" value="{{ old('installments.'.$idx.'.installment_no', $inst->installment_no) }}"></td>
                                    <td><input type="date" name="installments[{{ $idx }}][due_date]" class="form-control" value="{{ old('installments.'.$idx.'.due_date', optional($inst->due_date)->format('Y-m-d')) }}"></td>
                                    <td><input type="number" name="installments[{{ $idx }}][amount]" class="form-control" min="0" step="1" value="{{ old('installments.'.$idx.'.amount', $inst->amount) }}"></td>
                                </tr>
                            @endforeach
                            @for($i = $head->installments->count(); $i < $head->installments->count() + 2; $i++)
                                <tr>
                                    <td><input type="number" name="installments[{{ $i }}][installment_no]" class="form-control" min="1" step="1"></td>
                                    <td><input type="date" name="installments[{{ $i }}][due_date]" class="form-control"></td>
                                    <td><input type="number" name="installments[{{ $i }}][amount]" class="form-control" min="0" step="1"></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary">Update Fee Head</button>
                <a href="{{ route('fee_structure.index') }}" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
