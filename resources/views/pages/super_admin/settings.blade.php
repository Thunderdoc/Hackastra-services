@extends('layouts.master')
@section('page_title', 'Manage System Settings')
@section('content')

    {{-- Batch 5B: rebuilt on the shared form_page pattern (Loop 28).
         Same form contract as the legacy two-column layout — ONE PUT form
         to settings.update, multipart for the logo, every field name/id,
         required flag, select logic and error hook byte-equivalent.
         The teal italic hints are now plain muted helper text. --}}
    @component('partials.patterns.form_page', [
        'form_action'  => route('settings.update'),
        'form_method'  => 'PUT',
        'form_enctype' => 'multipart/form-data',
        'submit_label' => 'Save Settings',
    ])
        @slot('title')
            Update System Settings
        @endslot
        @slot('subtitle')
            School profile, calendar, fees and exam security for the whole school.
        @endslot

        {{-- ======================= School Profile ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">School Profile</div>
            <div class="pat-form-section-sub">The school's identity, shown across the app and on printed documents.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="system_name">Name of School <span class="text-danger">*</span></label>
                    <input name="system_name" id="system_name" value="{{ $s['system_name'] }}" required type="text" class="form-control @error('system_name') is-invalid @enderror" placeholder="Name of School">
                    @include('partials.errors.field', ['field' => 'system_name'])
                </div>
                <div class="pat-field">
                    <label for="current_session">Current Session <span class="text-danger">*</span></label>
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
                <div class="pat-field">
                    <label for="system_title">School Acronym</label>
                    <input name="system_title" id="system_title" value="{{ $s['system_title'] }}" type="text" class="form-control @error('system_title') is-invalid @enderror" placeholder="School Acronym">
                    @include('partials.errors.field', ['field' => 'system_title'])
                </div>
                <div class="pat-field">
                    <label for="phone">Phone</label>
                    <input name="phone" id="phone" value="{{ $s['phone'] }}" type="text" class="form-control @error('phone') is-invalid @enderror" placeholder="e.g. 98xxxxxx01">
                    @include('partials.errors.field', ['field' => 'phone'])
                </div>
                <div class="pat-field">
                    <label for="system_email">School Email</label>
                    <input name="system_email" id="system_email" value="{{ $s['system_email'] }}" type="email" class="form-control @error('system_email') is-invalid @enderror" placeholder="School Email">
                    @include('partials.errors.field', ['field' => 'system_email'])
                </div>
                <div class="pat-field pat-field-wide">
                    <label for="address">School Address <span class="text-danger">*</span></label>
                    <input required name="address" id="address" value="{{ $s['address'] }}" type="text" class="form-control @error('address') is-invalid @enderror" placeholder="School Address">
                    @include('partials.errors.field', ['field' => 'address'])
                </div>
                <div class="pat-field pat-field-wide">
                    <label for="logo">School Logo</label>
                    <div class="mb-2">
                        <img style="max-width: 140px" src="{{ Qs::getLogo() }}" alt="School logo">
                    </div>
                    <input name="logo" id="logo" accept="image/*" type="file" class="file-input @error('logo') is-invalid @enderror" data-show-caption="false" data-show-upload="false" data-fouc>
                    @include('partials.errors.field', ['field' => 'logo'])
                    <small class="form-text text-muted">Accepted Images: jpeg, jpg, png, gif, webp. Max file size 2Mb</small>
                    @if($s['logo'])
                        <small class="form-text text-muted">This logo appears across the sidebar, login page and printed documents.</small>
                    @else
                        <small class="form-text text-muted">Default logo shown — upload a file here to use your school's own logo across the sidebar, login page and printed documents.</small>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===================== Academic Calendar ===================== --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Academic Calendar</div>
            <div class="pat-form-section-sub">Key dates for the current term.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="term_ends">This Term Ends</label>
                    <input name="term_ends" id="term_ends" value="{{ $s['term_ends'] }}" type="text" class="form-control date-pick @error('term_ends') is-invalid @enderror" placeholder="Date Term Ends">
                    @include('partials.errors.field', ['field' => 'term_ends'])
                    <small class="form-text text-muted">M-D-Y or M/D/Y format.</small>
                </div>
                <div class="pat-field">
                    <label for="term_begins">Next Term Begins</label>
                    <input name="term_begins" id="term_begins" value="{{ $s['term_begins'] }}" type="text" class="form-control date-pick @error('term_begins') is-invalid @enderror" placeholder="Date Term Ends">
                    @include('partials.errors.field', ['field' => 'term_begins'])
                    <small class="form-text text-muted">M-D-Y or M/D/Y format.</small>
                </div>
            </div>
        </div>

        {{-- ======================= Next Term Fees ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Next Term Fees</div>
            <div class="pat-form-section-sub">Default fee for each class type next term.</div>
            <div class="pat-fields">
                @foreach($class_types as $ct)
                    <div class="pat-field">
                        <label for="next_term_fees_{{ strtolower($ct->code) }}">{{ $ct->name }}</label>
                        <input class="form-control @error('next_term_fees_'.strtolower($ct->code)) is-invalid @enderror" value="{{ $s['next_term_fees_'.strtolower($ct->code)] }}" name="next_term_fees_{{ strtolower($ct->code) }}" id="next_term_fees_{{ strtolower($ct->code) }}" placeholder="{{ $ct->name }}" type="text">
                        @include('partials.errors.field', ['field' => 'next_term_fees_'.strtolower($ct->code)])
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ======================= Exam Security ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Exam Security</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="lock_exam">Lock Exam</label>
                    <select class="form-control select @error('lock_exam') is-invalid @enderror" name="lock_exam" id="lock_exam">
                        <option {{ $s['lock_exam'] ? 'selected' : '' }} value="1">Yes</option>
                        <option {{ $s['lock_exam'] ?: 'selected' }} value="0">No</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'lock_exam'])
                    <small class="form-text text-muted">{{ __('msg.lock_exam') }}.</small>
                </div>
            </div>
        </div>

        {{-- ======================= Report Card ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Report Card</div>
            <div class="pat-form-section-sub">The principal's name and the school stamp printed on every report card.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="principal_name">Principal Name</label>
                    <input name="principal_name" id="principal_name" value="{{ $s['principal_name'] ?? '' }}" type="text" class="form-control @error('principal_name') is-invalid @enderror" placeholder="Name printed under the Principal's signature">
                    @include('partials.errors.field', ['field' => 'principal_name'])
                    <small class="form-text text-muted">Printed under the Principal's signature line on every report card. Leave blank to print the line only.</small>
                </div>
                <div class="pat-field">
                    <label for="school_stamp">School Stamp</label>
                    @if(!empty($s['school_stamp']))
                        <div class="mb-2">
                            <img style="max-width: 110px" src="{{ $s['school_stamp'] }}" alt="School stamp">
                        </div>
                        <label class="d-block font-weight-normal">
                            <input type="checkbox" name="school_stamp_remove" value="1"> Remove this stamp
                        </label>
                    @else
                        <div class="text-muted mb-2">No stamp uploaded — report cards print without one. A stamp appears on a card only when one is uploaded here; no placeholder is ever drawn.</div>
                    @endif
                    <input name="school_stamp" id="school_stamp" accept="image/*" type="file" class="file-input @error('school_stamp') is-invalid @enderror" data-show-caption="false" data-show-upload="false" data-fouc>
                    @include('partials.errors.field', ['field' => 'school_stamp'])
                    <small class="form-text text-muted">A transparent PNG or WebP of the school seal works best. Accepted Images: jpeg, jpg, png, gif, webp. Max file size 2Mb</small>
                </div>
            </div>
        </div>

    @endcomponent

    {{--Settings Edit Ends--}}

@endsection
