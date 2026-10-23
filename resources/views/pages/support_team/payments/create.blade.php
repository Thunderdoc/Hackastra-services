@extends('layouts.master')
@section('page_title', 'Create Payment')
@section('content')

    {{-- Workstream BT: rebuilt on the shared form_page pattern
         (Loop 28/39), the same cure the circulars and users forms
         already had — the legacy label-rail form squeezed into the
         left half of a wide card is gone. Shell change only: every
         field keeps its original name, id, classes, required flags,
         old() bindings, @error hooks and error includes, in one
         plain POST to payments.store (Loop 112: post-save lands on the index). --}}
    @component('partials.patterns.form_page', [
        'form_action'  => route('payments.store'),
        'back'         => route('payments.index'),
        'submit_label' => 'Submit form',
    ])
        @slot('title') Create Payment @endslot

        <div class="pat-form-section">
            <div class="pat-form-section-title">Payment Details</div>
            <div class="pat-fields">
                <div class="pat-field pat-field-span2">
                    <label for="title">Title <span class="text-danger">*</span></label>
                    <input name="title" id="title" value="{{ old('title') }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. School Fees">
                    @include('partials.errors.field', ['field' => 'title'])
                </div>
                <div class="pat-field">
                    <label for="amount">Amount (₹) <span class="text-danger">*</span></label>
                    <input class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required name="amount" id="amount" type="number">
                    @include('partials.errors.field', ['field' => 'amount'])
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class</label>
                    <select class="form-control select-search @error('my_class_id') is-invalid @enderror" name="my_class_id" id="my_class_id">
                        <option value="">All Classes</option>
                        @foreach($my_classes as $c)
                            <option {{ old('my_class_id') == $c->id ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'my_class_id'])
                </div>
                <div class="pat-field">
                    <label for="method">Payment Method</label>
                    <select class="form-control select @error('method') is-invalid @enderror" name="method" id="method">
                        <option selected value="Cash">Cash</option>
                        <option disabled value="Online">Online</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'method'])
                </div>
                <div class="pat-field">
                    <label for="description">Description</label>
                    <input class="form-control @error('description') is-invalid @enderror" value="{{ old('description') }}" name="description" id="description" type="text">
                    @include('partials.errors.field', ['field' => 'description'])
                </div>
            </div>
        </div>
    @endcomponent

    {{--Payment Create Ends--}}

@endsection
