@extends('layouts.master')
@section('page_title', $expense->exists ? 'Edit Expense' : 'Record Expense')
@section('content')
    @component('partials.patterns.form_page', [
        'form_action'  => $expense->exists ? route('expenses.update', $expense) : route('expenses.store'),
        'form_method'  => $expense->exists ? 'PUT' : 'POST',
        'back'         => route('expenses.index'),
        'submit_label' => $expense->exists ? 'Update expense' : 'Record expense',
    ])
        @slot('title') {{ $expense->exists ? 'Edit Expense' : 'Record Expense' }} @endslot

        <div class="pat-form-section">
            <div class="pat-form-section-title">Expense Details</div>
            <div class="pat-fields">
                <div class="pat-field pat-field-span2">
                    <label for="title">Title <span class="text-danger">*</span></label>
                    <input name="title" id="title" value="{{ old('title', $expense->title) }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. Electricity bill">
                    @include('partials.errors.field', ['field' => 'title'])
                </div>
                <div class="pat-field">
                    <label for="category">Category <span class="text-danger">*</span></label>
                    <input name="category" id="category" value="{{ old('category', $expense->category) }}" required type="text" list="expense-categories" class="form-control @error('category') is-invalid @enderror" placeholder="Eg. Utilities">
                    <datalist id="expense-categories">
                        @foreach($categories as $category)
                            <option value="{{ $category }}">
                        @endforeach
                    </datalist>
                    @include('partials.errors.field', ['field' => 'category'])
                </div>
                <div class="pat-field">
                    <label for="amount">Amount (₹) <span class="text-danger">*</span></label>
                    <input name="amount" id="amount" value="{{ old('amount', $expense->amount) }}" required type="number" step="0.01" min="0.01" class="form-control @error('amount') is-invalid @enderror">
                    @include('partials.errors.field', ['field' => 'amount'])
                </div>
                <div class="pat-field">
                    <label for="expense_date">Date <span class="text-danger">*</span></label>
                    <input name="expense_date" id="expense_date" value="{{ old('expense_date', $expense->expense_date ? $expense->expense_date->format('Y-m-d') : '') }}" required type="date" class="form-control @error('expense_date') is-invalid @enderror">
                    @include('partials.errors.field', ['field' => 'expense_date'])
                </div>
                <div class="pat-field pat-field-span2">
                    <label for="note">Note</label>
                    <textarea name="note" id="note" class="form-control @error('note') is-invalid @enderror" rows="3">{{ old('note', $expense->note) }}</textarea>
                    @include('partials.errors.field', ['field' => 'note'])
                </div>
            </div>
        </div>
    @endcomponent
@endsection
