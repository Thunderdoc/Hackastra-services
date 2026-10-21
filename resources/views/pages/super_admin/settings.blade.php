@extends('layouts.master')
@section('page_title', 'Manage System Settings')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title font-weight-semibold">Update System Settings</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <form enctype="multipart/form-data" method="post" action="{{ route('settings.update') }}">
                @csrf @method('PUT')
            <div class="row">
                <div class="col-md-6 border-right-2 border-right-blue-400">
                        <div class="form-group row">
                            <label for="system_name" class="col-lg-3 col-form-label font-weight-semibold">Name of School <span class="text-danger">*</span></label>
                            <div class="col-lg-9">
                                <input name="system_name" id="system_name" value="{{ $s['system_name'] }}" required type="text" class="form-control @error('system_name') is-invalid @enderror" placeholder="Name of School">
                                @include('partials.errors.field', ['field' => 'system_name'])
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="current_session" class="col-lg-3 col-form-label font-weight-semibold">Current Session <span class="text-danger">*</span></label>
                            <div class="col-lg-9">
                                <select data-placeholder="Choose..." required name="current_session" id="current_session" class="select-search form-control @error('current_session') is-invalid @enderror">
                                    <option value=""></option>
                                    @php $sessionInRange = false; @endphp
                                    @for($y=date('Y', strtotime('- 3 years')); $y<=date('Y', strtotime('+ 1 years')); $y++)
                                        @php $sessionLabel = ($y-1).'-'.$y; if($sessionLabel === $s['current_session']) $sessionInRange = true; @endphp
                                        <option value="{{ $sessionLabel }}" {{ $s['current_session'] == $sessionLabel ? 'selected' : '' }}>{{ $sessionLabel }}</option>
                                    @endfor
                                    @unless($sessionInRange)
                                        <option value="{{ $s['current_session'] }}" selected>{{ $s['current_session'] }} (current)</option>
                                    @endunless
                                </select>
                                @include('partials.errors.field', ['field' => 'current_session'])
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="system_title" class="col-lg-3 col-form-label font-weight-semibold">School Acronym</label>
                            <div class="col-lg-9">
                                <input name="system_title" id="system_title" value="{{ $s['system_title'] }}" type="text" class="form-control @error('system_title') is-invalid @enderror" placeholder="School Acronym">
                                @include('partials.errors.field', ['field' => 'system_title'])
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="phone" class="col-lg-3 col-form-label font-weight-semibold">Phone</label>
                            <div class="col-lg-9">
                                <input name="phone" id="phone" value="{{ $s['phone'] }}" type="text" class="form-control @error('phone') is-invalid @enderror" placeholder="e.g. 98xxxxxx01">
                                @include('partials.errors.field', ['field' => 'phone'])
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="system_email" class="col-lg-3 col-form-label font-weight-semibold">School Email</label>
                            <div class="col-lg-9">
                                <input name="system_email" id="system_email" value="{{ $s['system_email'] }}" type="email" class="form-control @error('system_email') is-invalid @enderror" placeholder="School Email">
                                @include('partials.errors.field', ['field' => 'system_email'])
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="address" class="col-lg-3 col-form-label font-weight-semibold">School Address <span class="text-danger">*</span></label>
                            <div class="col-lg-9">
                                <input required name="address" id="address" value="{{ $s['address'] }}" type="text" class="form-control @error('address') is-invalid @enderror" placeholder="School Address">
                                @include('partials.errors.field', ['field' => 'address'])
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="term_ends" class="col-lg-3 col-form-label font-weight-semibold">This Term Ends</label>
                            <div class="col-lg-6">
                                <input name="term_ends" id="term_ends" value="{{ $s['term_ends'] }}" type="text" class="form-control date-pick @error('term_ends') is-invalid @enderror" placeholder="Date Term Ends">
                                @include('partials.errors.field', ['field' => 'term_ends'])
                            </div>
                            <div class="col-lg-3 mt-2">
                                <span class="font-weight-bold font-italic">M-D-Y or M/D/Y </span>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="term_begins" class="col-lg-3 col-form-label font-weight-semibold">Next Term Begins</label>
                            <div class="col-lg-6">
                                <input name="term_begins" id="term_begins" value="{{ $s['term_begins'] }}" type="text" class="form-control date-pick @error('term_begins') is-invalid @enderror" placeholder="Date Term Ends">
                                @include('partials.errors.field', ['field' => 'term_begins'])
                            </div>
                            <div class="col-lg-3 mt-2">
                                <span class="font-weight-bold font-italic">M-D-Y or M/D/Y </span>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="lock_exam" class="col-lg-3 col-form-label font-weight-semibold">Lock Exam</label>
                            <div class="col-lg-3">
                                <select class="form-control select @error('lock_exam') is-invalid @enderror" name="lock_exam" id="lock_exam">
                                    <option {{ $s['lock_exam'] ? 'selected' : '' }} value="1">Yes</option>
                                    <option {{ $s['lock_exam'] ?: 'selected' }} value="0">No</option>
                                </select>
                                @include('partials.errors.field', ['field' => 'lock_exam'])
                            </div>
                            <div class="col-lg-6">
                                    <span class="font-weight-bold font-italic text-info-800">{{ __('msg.lock_exam') }}</span>
                            </div>
                        </div>
                </div>
                <div class="col-md-6">
                    {{--Fees--}}
               <fieldset>
                   <legend><strong>Next Term Fees</strong></legend>
                   @foreach($class_types as $ct)
                   <div class="form-group row">
                       <label for="next_term_fees_{{ strtolower($ct->code) }}" class="col-lg-3 col-form-label font-weight-semibold">{{ $ct->name }}</label>
                       <div class="col-lg-9">
                           <input class="form-control @error('next_term_fees_'.strtolower($ct->code)) is-invalid @enderror" value="{{ $s['next_term_fees_'.strtolower($ct->code)] }}" name="next_term_fees_{{ strtolower($ct->code) }}" id="next_term_fees_{{ strtolower($ct->code) }}" placeholder="{{ $ct->name }}" type="text">
                           @include('partials.errors.field', ['field' => 'next_term_fees_'.strtolower($ct->code)])
                       </div>
                   </div>
                       @endforeach
               </fieldset>
                    <hr class="divider">

                    {{--Logo--}}
                    <div class="form-group row">
                        <label for="logo" class="col-lg-3 col-form-label font-weight-semibold">Change Logo:</label>
                        <div class="col-lg-9">
                            <div class="mb-3">
                                <img style="max-width: 140px" src="{{ Qs::getLogo() }}" alt="School logo">
                                @unless($s['logo'])
                                    <div class="text-muted mt-1">Default logo shown — upload a file here to use your school's own logo across the sidebar, login page and printed documents.</div>
                                @endunless
                            </div>
                            <input name="logo" id="logo" accept="image/*" type="file" class="file-input @error('logo') is-invalid @enderror" data-show-caption="false" data-show-upload="false" data-fouc>
                            @include('partials.errors.field', ['field' => 'logo'])
                        </div>
                    </div>
                </div>
            </div>

                <hr class="divider">

                <div class="text-right">
                    <button type="submit" class="btn btn-danger">Submit form <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        </div>
    </div>

    {{--Settings Edit Ends--}}

@endsection
