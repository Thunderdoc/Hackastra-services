@extends('layouts.master')
@section('page_title', 'Edit Payment')
@section('content')

    {{-- Workstream BT: rebuilt on the shared form_page pattern
         (Loop 28/39) — same cure as Create Payment. Shell change
         only: the editable fields keep their names and values
         (title, description), and Class / Method / Amount stay
         read-only display values that are never submitted, exactly
         as before, in one plain PUT to payments.update (Loop 112: post-save lands on the index). --}}
    @component('partials.patterns.form_page', [
        'form_action'  => route('payments.update', $payment->id),
        'form_method'  => 'PUT',
        'back'         => route('payments.index'),
        'submit_label' => 'Submit form',
    ])
        @slot('title') Edit Payment @endslot

        <div class="pat-form-section">
            <div class="pat-form-section-title">Payment Details</div>
            <div class="pat-fields">
                <div class="pat-field pat-field-span2">
                    <label for="title">Title <span class="text-danger">*</span></label>
                    <input name="title" id="title" value="{{ $payment->title }}" required type="text" class="form-control" placeholder="Eg. School Fees">
                </div>
                <div class="pat-field">
                    <label for="amount">Amount (₹)</label>
                    <input disabled class="form-control" value="{{ $payment->amount }}" id="amount" type="text">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class</label>
                    <input class="form-control" id="my_class_id" title="Class" disabled value="{{ $payment->my_class_id ? $payment->my_class->name : 'All Classes' }}" type="text">
                </div>
                <div class="pat-field">
                    <label for="method">Payment Method</label>
                    <input title="method" id="method" value="{{ ucwords($payment->method) }}" disabled class="form-control" type="text">
                </div>
                <div class="pat-field">
                    <label for="description">Description</label>
                    <input class="form-control" value="{{ $payment->description }}" name="description" id="description" type="text">
                </div>
            </div>
        </div>
    @endcomponent

    {{--Payment Edit Ends--}}

@endsection
