@extends('layouts.master')
@section('page_title', 'Edit User')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Edit User Details</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="wizard-form steps-validation ajax-update" action="{{ route('users.update', Qs::hash($user->id)) }}" data-fouc>
                @csrf @method('PUT')
                <h6>Personal Data</h6>
                <fieldset>
                    <div class="row">
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="user_type"> Select User: <span class="text-danger">*</span></label>
                                <select disabled="disabled" class="form-control select" id="user_type">
                                    <option value="">{{ strtoupper($user->user_type) }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="name">Full Name: <span class="text-danger">*</span></label>
                                <input value="{{ $user->name }}" required type="text" name="name" id="name" placeholder="Full Name" class="form-control @error('name') is-invalid @enderror">
                                @include('partials.errors.field', ['field' => 'name'])
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="address">Address: <span class="text-danger">*</span></label>
                                <input value="{{ $user->address }}" class="form-control @error('address') is-invalid @enderror" placeholder="Address" name="address" id="address" type="text" required>
                                @include('partials.errors.field', ['field' => 'address'])
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="email">Email address: </label>
                                <input value="{{ $user->email }}" type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror" placeholder="your@email.com">
                                @include('partials.errors.field', ['field' => 'email'])
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="phone">Phone:</label>
                                <input value="{{ $user->phone }}" type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror" placeholder="" >
                                @include('partials.errors.field', ['field' => 'phone'])
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="phone2">Telephone:</label>
                                <input value="{{ $user->phone2 }}" type="text" name="phone2" id="phone2" class="form-control @error('phone2') is-invalid @enderror" placeholder="" >
                                @include('partials.errors.field', ['field' => 'phone2'])
                            </div>
                        </div>

                    </div>

                    <div class="row">
                        @if(in_array($user->user_type, Qs::getStaff()))
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="emp_date">Date of Employment:</label>
                                    <input autocomplete="off" name="emp_date" id="emp_date" value="{{ $user->staff->first()->emp_date ?? '' }}" type="text" class="form-control date-pick @error('emp_date') is-invalid @enderror" placeholder="Select Date...">
                                    @include('partials.errors.field', ['field' => 'emp_date'])

                                </div>
                            </div>
                        @endif

                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="gender">Gender: <span class="text-danger">*</span></label>
                                <select class="select form-control @error('gender') is-invalid @enderror" id="gender" name="gender" required data-fouc data-placeholder="Choose..">
                                    <option value=""></option>
                                    <option {{ ($user->gender == 'Male') ? 'selected' : '' }} value="Male">Male</option>
                                    <option {{ ($user->gender == 'Female') ? 'selected' : '' }} value="Female">Female</option>
                                </select>
                                @include('partials.errors.field', ['field' => 'gender'])
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="nal_id">Nationality: <span class="text-danger">*</span></label>
                                <select data-placeholder="Choose..." required name="nal_id" id="nal_id" class="select-search form-control @error('nal_id') is-invalid @enderror">
                                    <option value=""></option>
                                    @foreach($nationals as $nal)
                                        <option {{ ($user->nal_id == $nal->id) ? 'selected' : '' }} value="{{ $nal->id }}">{{ $nal->name }}</option>
                                    @endforeach
                                </select>
                                @include('partials.errors.field', ['field' => 'nal_id'])
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <label for="state_id">State: <span class="text-danger">*</span></label>
                            <select onchange="getLGA(this.value)" required data-placeholder="Choose.." class="select-search form-control @error('state_id') is-invalid @enderror" name="state_id" id="state_id">
                                <option value=""></option>
                                @foreach($states as $st)
                                    <option {{ ($user->state_id == $st->id ? 'selected' : '') }} value="{{ $st->id }}">{{ $st->name }}</option>
                                @endforeach
                            </select>
                            @include('partials.errors.field', ['field' => 'state_id'])
                        </div>

                        <div class="col-md-4">
                            <label for="lga_id">LGA: <span class="text-danger">*</span></label>
                            <select required data-placeholder="Select State First" class="select-search form-control @error('lga_id') is-invalid @enderror" name="lga_id" id="lga_id">
                                <option value="{{ $user->lga_id ?? '' }}">{{ $user->lga->name ?? '' }}</option>
                            </select>
                            @include('partials.errors.field', ['field' => 'lga_id'])
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="bg_id">Blood Group: </label>
                                <select class="select form-control @error('bg_id') is-invalid @enderror" id="bg_id" name="bg_id" data-fouc data-placeholder="Choose..">
                                    <option value=""></option>
                                    @foreach($blood_groups as $bg)
                                        <option {{ ($user->bg_id == $bg->id ? 'selected' : '') }} value="{{ $bg->id }}">{{ $bg->name }}</option>
                                    @endforeach
                                </select>
                                @include('partials.errors.field', ['field' => 'bg_id'])
                            </div>
                        </div>

                    </div>

                    {{--Passport--}}
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="photo" class="d-block">Upload Passport Photo:</label>
                                <input value="{{ old('photo') }}" accept="image/*" type="file" name="photo" id="photo" class="form-input-styled @error('photo') is-invalid @enderror" data-fouc>
                                @include('partials.errors.field', ['field' => 'photo'])
                                <span class="form-text text-muted">Accepted Images: jpeg, png. Max file size 2Mb</span>
                            </div>
                        </div>
                    </div>

                </fieldset>



            </form>
        </div>

    </div>
@endsection
