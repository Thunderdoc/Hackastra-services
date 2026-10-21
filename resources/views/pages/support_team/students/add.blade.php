@extends('layouts.master')
@section('page_title', 'Admit Student')
@section('content')
        <div class="card">
            <div class="card-header bg-white header-elements-inline">
                <h6 class="card-title">Please fill The form Below To Admit A New Student</h6>

                {!! Qs::getPanelOptions() !!}
            </div>

            <form id="ajax-reg" method="post" enctype="multipart/form-data" class="wizard-form steps-validation" action="{{ route('students.store') }}" data-fouc>
               @csrf
                <h6>Personal data</h6>
                <fieldset>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="name">Full Name: <span class="text-danger">*</span></label>
                                <input value="{{ old('name') }}" required type="text" name="name" id="name" placeholder="Full Name" class="form-control @error('name') is-invalid @enderror">
                                @include('partials.errors.field', ['field' => 'name'])
                                </div>
                            </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="address">Address: <span class="text-danger">*</span></label>
                                <input value="{{ old('address') }}" class="form-control @error('address') is-invalid @enderror" placeholder="Address" name="address" id="address" type="text" required>
                                @include('partials.errors.field', ['field' => 'address'])
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="email">Email address: </label>
                                <input type="email" value="{{ old('email') }}" name="email" id="email" class="form-control @error('email') is-invalid @enderror" placeholder="Email Address">
                                @include('partials.errors.field', ['field' => 'email'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="gender">Gender: <span class="text-danger">*</span></label>
                                <select class="select form-control @error('gender') is-invalid @enderror" id="gender" name="gender" required data-fouc data-placeholder="Choose..">
                                    <option value=""></option>
                                    <option {{ (old('gender') == 'Male') ? 'selected' : '' }} value="Male">Male</option>
                                    <option {{ (old('gender') == 'Female') ? 'selected' : '' }} value="Female">Female</option>
                                </select>
                                @include('partials.errors.field', ['field' => 'gender'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="phone">Phone:</label>
                                <input value="{{ old('phone') }}" type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror" placeholder="" >
                                @include('partials.errors.field', ['field' => 'phone'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="phone2">Telephone:</label>
                                <input value="{{ old('phone2') }}" type="text" name="phone2" id="phone2" class="form-control @error('phone2') is-invalid @enderror" placeholder="" >
                                @include('partials.errors.field', ['field' => 'phone2'])
                            </div>
                        </div>

                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="dob">Date of Birth:</label>
                                <input name="dob" id="dob" value="{{ old('dob') }}" type="text" class="form-control date-pick @error('dob') is-invalid @enderror" placeholder="Select Date...">
                                @include('partials.errors.field', ['field' => 'dob'])

                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="nal_id">Nationality: <span class="text-danger">*</span></label>
                                <select data-placeholder="Choose..." required name="nal_id" id="nal_id" class="select-search form-control @error('nal_id') is-invalid @enderror">
                                    <option value=""></option>
                                    @foreach($nationals as $nal)
                                        <option {{ (old('nal_id') == $nal->id ? 'selected' : '') }} value="{{ $nal->id }}">{{ $nal->name }}</option>
                                    @endforeach
                                </select>
                                @include('partials.errors.field', ['field' => 'nal_id'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label for="state_id">State: <span class="text-danger">*</span></label>
                            <select onchange="getLGA(this.value)" required data-placeholder="Choose.." class="select-search form-control @error('state_id') is-invalid @enderror" name="state_id" id="state_id">
                                <option value=""></option>
                                @foreach($states as $st)
                                    <option {{ (old('state_id') == $st->id ? 'selected' : '') }} value="{{ $st->id }}">{{ $st->name }}</option>
                                @endforeach
                            </select>
                            @include('partials.errors.field', ['field' => 'state_id'])
                        </div>

                        <div class="col-md-3">
                            <label for="lga_id">LGA: <span class="text-danger">*</span></label>
                            <select required data-placeholder="Select State First" class="select-search form-control @error('lga_id') is-invalid @enderror" name="lga_id" id="lga_id">
                                <option value=""></option>
                            </select>
                            @include('partials.errors.field', ['field' => 'lga_id'])
                        </div>
                    </div>
                    <div class="row">

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bg_id">Blood Group: </label>
                                <select class="select form-control @error('bg_id') is-invalid @enderror" id="bg_id" name="bg_id" data-fouc data-placeholder="Choose..">
                                    <option value=""></option>
                                    @foreach(App\Models\BloodGroup::all() as $bg)
                                        <option {{ (old('bg_id') == $bg->id ? 'selected' : '') }} value="{{ $bg->id }}">{{ $bg->name }}</option>
                                    @endforeach
                                </select>
                                @include('partials.errors.field', ['field' => 'bg_id'])
                            </div>
                        </div>

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

                <h6>Student Data</h6>
                <fieldset>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="my_class_id">Class: <span class="text-danger">*</span></label>
                                <select onchange="getClassSections(this.value)" data-placeholder="Choose..." required name="my_class_id" id="my_class_id" class="select-search form-control @error('my_class_id') is-invalid @enderror">
                                    <option value=""></option>
                                    @foreach($my_classes as $c)
                                        <option {{ (old('my_class_id') == $c->id ? 'selected' : '') }} value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                </select>
                                @include('partials.errors.field', ['field' => 'my_class_id'])
                        </div>
                            </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="section_id">Section: <span class="text-danger">*</span></label>
                                <select data-placeholder="Select Class First" required name="section_id" id="section_id" class="select-search form-control @error('section_id') is-invalid @enderror">
                                    <option {{ (old('section_id')) ? 'selected' : '' }} value="{{ old('section_id') }}">{{ (old('section_id')) ? 'Selected' : '' }}</option>
                                </select>
                                @include('partials.errors.field', ['field' => 'section_id'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="my_parent_id">Parent: </label>
                                <select data-placeholder="Choose..."  name="my_parent_id" id="my_parent_id" class="select-search form-control @error('my_parent_id') is-invalid @enderror">
                                    <option  value=""></option>
                                    @foreach($parents as $p)
                                        <option {{ (old('my_parent_id') == Qs::hash($p->id)) ? 'selected' : '' }} value="{{ Qs::hash($p->id) }}">{{ $p->name }}</option>
                                    @endforeach
                                </select>
                                @include('partials.errors.field', ['field' => 'my_parent_id'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="year_admitted">Year Admitted: <span class="text-danger">*</span></label>
                                <select data-placeholder="Choose..." required name="year_admitted" id="year_admitted" class="select-search form-control @error('year_admitted') is-invalid @enderror">
                                    <option value=""></option>
                                    @for($y=date('Y', strtotime('- 10 years')); $y<=date('Y'); $y++)
                                        <option {{ (old('year_admitted') == $y) ? 'selected' : '' }} value="{{ $y }}">{{ $y }}</option>
                                    @endfor
                                </select>
                                @include('partials.errors.field', ['field' => 'year_admitted'])
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <label for="dorm_id">Dormitory: </label>
                            <select data-placeholder="Choose..."  name="dorm_id" id="dorm_id" class="select-search form-control @error('dorm_id') is-invalid @enderror">
                                <option value=""></option>
                                @foreach($dorms as $d)
                                    <option {{ (old('dorm_id') == $d->id) ? 'selected' : '' }} value="{{ $d->id }}">{{ $d->name }}</option>
                                    @endforeach
                            </select>
                            @include('partials.errors.field', ['field' => 'dorm_id'])

                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="dorm_room_no">Dormitory Room No:</label>
                                <input type="text" name="dorm_room_no" id="dorm_room_no" placeholder="Dormitory Room No" class="form-control @error('dorm_room_no') is-invalid @enderror" value="{{ old('dorm_room_no') }}">
                                @include('partials.errors.field', ['field' => 'dorm_room_no'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="house">Sport House:</label>
                                <input type="text" name="house" id="house" placeholder="Sport House" class="form-control @error('house') is-invalid @enderror" value="{{ old('house') }}">
                                @include('partials.errors.field', ['field' => 'house'])
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="adm_no">Admission Number:</label>
                                <input type="text" name="adm_no" id="adm_no" placeholder="Admission Number" class="form-control @error('adm_no') is-invalid @enderror" value="{{ old('adm_no') }}">
                                @include('partials.errors.field', ['field' => 'adm_no'])
                            </div>
                        </div>
                    </div>
                </fieldset>

            </form>
        </div>
    @endsection
