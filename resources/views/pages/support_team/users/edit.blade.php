@extends('layouts.master')
@section('page_title', 'Edit User')
@section('content')
    {{-- Batch 5A: rebuilt on the shared form_page pattern (Loop 28).
         Was a 69KB legacy wizard. Shell change only: every field
         keeps its original name, id, classes, data attributes,
         required flags, $user bindings, @error hooks and error
         includes, the getLGA() cascade and the staff-only emp_date
         block; one multipart PUT to users.update (class ajax-update
         keeps the global AJAX submit handler bound, as before). The
         user type stays a disabled, unsubmitted display field.
         Loop 106: premium design scope (.l106-users) — presentation
         only, same form pattern and fields. --}}
  <div class="l106 l106-users">
    @component('partials.patterns.form_page', [
        'form_action'  => route('users.update', Qs::hash($user->id)),
        'form_method'  => 'PUT',
        'form_enctype' => 'multipart/form-data',
        'form_class'   => 'ajax-update',
        'back'         => route('users.index'),
        'submit_label' => 'Save Changes',
    ])
        @slot('title') Edit User Details @endslot
        @slot('subtitle') Update the details of {{ $user->name }} below. @endslot

        <div class="pat-form-section">
            <div class="pat-form-section-title">Role &amp; Employment</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="user_type"> Select User: <span class="text-danger">*</span></label>
                    <select disabled="disabled" class="form-control select" id="user_type">
                        <option value="">{{ strtoupper($user->user_type) }}</option>
                    </select>
                </div>

                @if(in_array($user->user_type, Qs::getStaff()))
                    <div class="pat-field">
                        <label for="emp_date">Date of Employment:</label>
                        <input autocomplete="off" name="emp_date" id="emp_date" value="{{ $user->staff->first()->emp_date ?? '' }}" type="text" class="form-control date-pick @error('emp_date') is-invalid @enderror" placeholder="Select Date...">
                        @include('partials.errors.field', ['field' => 'emp_date'])
                    </div>
                @endif
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Personal Data</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Full Name: <span class="text-danger">*</span></label>
                    <input value="{{ $user->name }}" required type="text" name="name" id="name" placeholder="Full Name" class="form-control @error('name') is-invalid @enderror">
                    @include('partials.errors.field', ['field' => 'name'])
                </div>

                <div class="pat-field">
                    <label for="gender">Gender: <span class="text-danger">*</span></label>
                    <select class="select form-control @error('gender') is-invalid @enderror" id="gender" name="gender" required data-fouc data-placeholder="Choose..">
                        <option value=""></option>
                        <option {{ ($user->gender == 'Male') ? 'selected' : '' }} value="Male">Male</option>
                        <option {{ ($user->gender == 'Female') ? 'selected' : '' }} value="Female">Female</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'gender'])
                </div>

                <div class="pat-field">
                    <label for="bg_id">Blood Group: </label>
                    <select class="select form-control @error('bg_id') is-invalid @enderror" id="bg_id" name="bg_id" data-fouc data-placeholder="Choose..">
                        <option value=""></option>
                        @foreach($blood_groups as $bg)
                            <option {{ ($user->bg_id == $bg->id ? 'selected' : '') }} value="{{ $bg->id }}">{{ $bg->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'bg_id'])
                </div>

                <div class="pat-field">
                    <label for="address">Address: <span class="text-danger">*</span></label>
                    <input value="{{ $user->address }}" class="form-control @error('address') is-invalid @enderror" placeholder="Address" name="address" id="address" type="text" required>
                    @include('partials.errors.field', ['field' => 'address'])
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Contact &amp; Origin</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="email">Email address: </label>
                    <input value="{{ $user->email }}" type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror" placeholder="your@email.com">
                    @include('partials.errors.field', ['field' => 'email'])
                </div>

                <div class="pat-field">
                    <label for="phone">Phone:</label>
                    <input value="{{ $user->phone }}" type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror" placeholder="" >
                    @include('partials.errors.field', ['field' => 'phone'])
                </div>

                <div class="pat-field">
                    <label for="phone2">Telephone:</label>
                    <input value="{{ $user->phone2 }}" type="text" name="phone2" id="phone2" class="form-control @error('phone2') is-invalid @enderror" placeholder="" >
                    @include('partials.errors.field', ['field' => 'phone2'])
                </div>

                <div class="pat-field">
                    <label for="nal_id">Nationality: <span class="text-danger">*</span></label>
                    <select data-placeholder="Choose..." required name="nal_id" id="nal_id" class="select-search form-control @error('nal_id') is-invalid @enderror">
                        <option value=""></option>
                        @foreach($nationals as $nal)
                            <option {{ ($user->nal_id == $nal->id) ? 'selected' : '' }} value="{{ $nal->id }}">{{ $nal->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'nal_id'])
                </div>

                <div class="pat-field">
                    <label for="state_id">State: <span class="text-danger">*</span></label>
                    <select onchange="getLGA(this.value)" required data-placeholder="Choose.." class="select-search form-control @error('state_id') is-invalid @enderror" name="state_id" id="state_id">
                        <option value=""></option>
                        @foreach($states as $st)
                            <option {{ ($user->state_id == $st->id ? 'selected' : '') }} value="{{ $st->id }}">{{ $st->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'state_id'])
                </div>

                <div class="pat-field">
                    <label for="lga_id">LGA: <span class="text-danger">*</span></label>
                    <select required data-placeholder="Select State First" class="select-search form-control @error('lga_id') is-invalid @enderror" name="lga_id" id="lga_id">
                        <option value="{{ $user->lga_id ?? '' }}">{{ $user->lga->name ?? '' }}</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'lga_id'])
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Photo</div>
            <div class="pat-fields">
                <div class="pat-field pat-field-wide">
                    <label for="photo" class="d-block">Upload Passport Photo:</label>
                    <input value="{{ old('photo') }}" accept="image/*" type="file" name="photo" id="photo" class="form-input-styled @error('photo') is-invalid @enderror" data-fouc>
                    @include('partials.errors.field', ['field' => 'photo'])
                    <span class="form-text text-muted">Accepted Images: jpeg, jpg, png, gif, webp. Max file size 2Mb</span>
                </div>
            </div>
        </div>
    @endcomponent
  </div>{{-- /.l106-users --}}
@endsection
