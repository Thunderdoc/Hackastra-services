@extends('layouts.master')
@section('page_title', 'Create Payment')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Create Payment</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <form class="ajax-store" method="post" action="{{ route('payments.store') }}">
                        @csrf
                        <div class="form-group row">
                            <label for="title" class="col-lg-3 col-form-label font-weight-semibold">Title <span class="text-danger">*</span></label>
                            <div class="col-lg-9">
                                <input name="title" id="title" value="{{ old('title') }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. School Fees">
                                @include('partials.errors.field', ['field' => 'title'])
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="my_class_id" class="col-lg-3 col-form-label font-weight-semibold">Class </label>
                            <div class="col-lg-9">
                                <select class="form-control select-search @error('my_class_id') is-invalid @enderror" name="my_class_id" id="my_class_id">
                                    <option value="">All Classes</option>
                                    @foreach($my_classes as $c)
                                        <option {{ old('my_class_id') == $c->id ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                @include('partials.errors.field', ['field' => 'my_class_id'])
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="method" class="col-lg-3 col-form-label font-weight-semibold">Payment Method</label>
                            <div class="col-lg-9">
                                <select class="form-control select @error('method') is-invalid @enderror" name="method" id="method">
                                    <option selected value="Cash">Cash</option>
                                    <option disabled value="Online">Online</option>
                                </select>
                                @include('partials.errors.field', ['field' => 'method'])
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="amount" class="col-lg-3 col-form-label font-weight-semibold">Amount (₹) <span class="text-danger">*</span></label>
                            <div class="col-lg-9">
                                <input class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required name="amount" id="amount" type="number">
                                @include('partials.errors.field', ['field' => 'amount'])
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="description" class="col-lg-3 col-form-label font-weight-semibold">Description</label>
                            <div class="col-lg-9">
                                <input class="form-control @error('description') is-invalid @enderror" value="{{ old('description') }}" name="description" id="description" type="text">
                                @include('partials.errors.field', ['field' => 'description'])
                            </div>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-primary">Submit form <i class="icon-paperplane ml-2"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{--Payment Create Ends--}}

@endsection
