@extends('layouts.master')
@section('page_title', 'Admit Student')
{{-- Owner flag 2026-10-22: admitting students one by one is a pain —
     the CSV bulk import already exists (Students → Student Information
     → Import CSV) but this page never hinted at it. Surface it here in
     the page header; the importer itself is unchanged. --}}
@section('page_actions')
    <a href="{{ route('students.import') }}" class="btn btn-success"><i class="icon-upload mr-2"></i>Import Students (CSV)</a>
@endsection
@section('content')
    {{-- Quick Admit (Loop 102 · 2026-10-22): mode switch — Quick Admit
         (essentials only, students.quick_store) vs the Full Admission
         Form below (students.store, byte-identical). The default tab is
         Quick; a validation bounce reopens the tab whose fields failed
         (quick fields are q_-prefixed, so the two never collide). --}}
    @php
        $qaHasQuickErrors = collect($errors->keys())->contains(function ($k) { return strpos($k, 'q_') === 0; });
        $qaQuickActive = !$errors->any() || $qaHasQuickErrors;
    @endphp

    @if(session('quick_admitted'))
        @php $qaDone = session('quick_admitted'); @endphp
        <div class="qa102-success">
            <div class="qa102-success-main">
                <div class="qa102-success-title"><i class="icon-checkmark-circle2 mr-1"></i> {{ $qaDone['name'] }} admitted</div>
                <div class="qa102-success-sub">Admission Number: <strong>{{ $qaDone['adm_no'] }}</strong> &middot; Default login password: <strong>student</strong> &middot; The profile is still incomplete — finish the remaining details anytime.</div>
            </div>
            <a href="{{ route('students.edit', Qs::hash($qaDone['sr_id'])) }}" class="btn btn-primary">Complete Student Details</a>
        </div>
    @endif

    <div class="qa102-tabs" role="tablist">
        <button type="button" class="qa102-tab{{ $qaQuickActive ? ' is-active' : '' }}" data-qa102-tab="quick" role="tab">Quick Admit</button>
        <button type="button" class="qa102-tab{{ $qaQuickActive ? '' : ' is-active' }}" data-qa102-tab="full" role="tab">Full Admission Form</button>
    </div>

    <div id="qa102-pane-quick" class="qa102-pane"{{ $qaQuickActive ? '' : ' hidden' }}>
        @include('pages.support_team.students.partials.quick_admit')
    </div>

    <div id="qa102-pane-full" class="qa102-pane"{{ $qaQuickActive ? ' hidden' : '' }}>
    {{-- Batch 3: sectioned rebuild on the shared form_page pattern (Loop 28).
         Shell change only: every field keeps its original name, id, classes,
         data attributes, onchange AJAX hooks (getLGA / getClassSections),
         old() bindings, @error hooks and error includes, in the original
         order; the form still POSTs multipart to students.store. The legacy
         wizard shell (steps-validation / ajax-reg) had no submit button of
         its own; the pattern's sticky action bar provides it. --}}
    @component('partials.patterns.form_page', [
        'form_action'  => route('students.store'),
        'form_enctype' => 'multipart/form-data',
        'back'         => route('students.index'),
        'submit_label' => 'Admit Student',
    ])
        @slot('title') Admit Student @endslot
        @slot('subtitle') Please fill the form below to admit a new student. @endslot

        <div class="pat-form-section">
            <div class="pat-form-section-title">Personal Details</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Full Name: <span class="text-danger">*</span></label>
                    <input value="{{ old('name') }}" required type="text" name="name" id="name" placeholder="Full Name" class="form-control @error('name') is-invalid @enderror">
                    @include('partials.errors.field', ['field' => 'name'])
                </div>

                <div class="pat-field">
                    <label for="address">Address: <span class="text-danger">*</span></label>
                    <input value="{{ old('address') }}" class="form-control @error('address') is-invalid @enderror" placeholder="Address" name="address" id="address" type="text" required>
                    @include('partials.errors.field', ['field' => 'address'])
                </div>

                <div class="pat-field">
                    <label for="email">Email address: </label>
                    <input type="email" value="{{ old('email') }}" name="email" id="email" class="form-control @error('email') is-invalid @enderror" placeholder="Email Address">
                    @include('partials.errors.field', ['field' => 'email'])
                </div>

                <div class="pat-field">
                    <label for="gender">Gender: <span class="text-danger">*</span></label>
                    <select class="select form-control @error('gender') is-invalid @enderror" id="gender" name="gender" required data-fouc data-placeholder="Choose..">
                        <option value=""></option>
                        <option {{ (old('gender') == 'Male') ? 'selected' : '' }} value="Male">Male</option>
                        <option {{ (old('gender') == 'Female') ? 'selected' : '' }} value="Female">Female</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'gender'])
                </div>

                <div class="pat-field">
                    <label for="phone">Phone:</label>
                    <input value="{{ old('phone') }}" type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror" placeholder="" >
                    @include('partials.errors.field', ['field' => 'phone'])
                </div>

                <div class="pat-field">
                    <label for="phone2">Telephone:</label>
                    <input value="{{ old('phone2') }}" type="text" name="phone2" id="phone2" class="form-control @error('phone2') is-invalid @enderror" placeholder="" >
                    @include('partials.errors.field', ['field' => 'phone2'])
                </div>

                <div class="pat-field">
                    <label for="dob">Date of Birth:</label>
                    <input name="dob" id="dob" value="{{ old('dob') }}" type="text" class="form-control date-pick @error('dob') is-invalid @enderror" placeholder="Select Date...">
                    @include('partials.errors.field', ['field' => 'dob'])
                </div>

                <div class="pat-field">
                    <label for="bg_id">Blood Group: </label>
                    <select class="select form-control @error('bg_id') is-invalid @enderror" id="bg_id" name="bg_id" data-fouc data-placeholder="Choose..">
                        <option value=""></option>
                        @foreach(App\Models\BloodGroup::all() as $bg)
                            <option {{ (old('bg_id') == $bg->id ? 'selected' : '') }} value="{{ $bg->id }}">{{ $bg->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'bg_id'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="photo" class="d-block">Upload Passport Photo:</label>
                    <input value="{{ old('photo') }}" accept="image/*" type="file" name="photo" id="photo" class="form-input-styled @error('photo') is-invalid @enderror" data-fouc>
                    @include('partials.errors.field', ['field' => 'photo'])
                    <span class="form-text text-muted">Accepted Images: jpeg, jpg, png, gif, webp. Max file size 2Mb</span>
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Nationality &amp; Origin</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="nal_id">Nationality: <span class="text-danger">*</span></label>
                    <select data-placeholder="Choose..." required name="nal_id" id="nal_id" class="select-search form-control @error('nal_id') is-invalid @enderror">
                        <option value=""></option>
                        @foreach($nationals as $nal)
                            <option {{ (old('nal_id') == $nal->id ? 'selected' : '') }} value="{{ $nal->id }}">{{ $nal->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'nal_id'])
                </div>

                <div class="pat-field">
                    <label for="state_id">State: <span class="text-danger">*</span></label>
                    <select onchange="getLGA(this.value)" required data-placeholder="Choose.." class="select-search form-control @error('state_id') is-invalid @enderror" name="state_id" id="state_id">
                        <option value=""></option>
                        @foreach($states as $st)
                            <option {{ (old('state_id') == $st->id ? 'selected' : '') }} value="{{ $st->id }}">{{ $st->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'state_id'])
                </div>

                <div class="pat-field">
                    <label for="category">Category: </label>
                    <select class="select form-control @error('category') is-invalid @enderror" id="category" name="category" data-fouc data-placeholder="Choose..">
                        <option value=""></option>
                        @foreach(['General', 'OBC', 'SC', 'ST', 'EWS'] as $catOption)
                            <option {{ (old('category') == $catOption) ? 'selected' : '' }} value="{{ $catOption }}">{{ $catOption }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'category'])
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Academic Details</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="my_class_id">Class: <span class="text-danger">*</span></label>
                    <select onchange="getClassSections(this.value)" data-placeholder="Choose..." required name="my_class_id" id="my_class_id" class="select-search form-control @error('my_class_id') is-invalid @enderror">
                        <option value=""></option>
                        @foreach($my_classes as $c)
                            <option {{ (old('my_class_id') == $c->id ? 'selected' : '') }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'my_class_id'])
                </div>

                <div class="pat-field">
                    <label for="section_id">Section: <span class="text-danger">*</span></label>
                    <select data-placeholder="Select Class First" required name="section_id" id="section_id" class="select-search form-control @error('section_id') is-invalid @enderror">
                        <option {{ (old('section_id')) ? 'selected' : '' }} value="{{ old('section_id') }}">{{ (old('section_id')) ? 'Selected' : '' }}</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'section_id'])
                </div>

                <div class="pat-field">
                    <label for="year_admitted">Year Admitted: <span class="text-danger">*</span></label>
                    <select data-placeholder="Choose..." required name="year_admitted" id="year_admitted" class="select-search form-control @error('year_admitted') is-invalid @enderror">
                        <option value=""></option>
                        @for($y=date('Y', strtotime('- 10 years')); $y<=date('Y'); $y++)
                            <option {{ (old('year_admitted') == $y) ? 'selected' : '' }} value="{{ $y }}">{{ $y }}</option>
                        @endfor
                    </select>
                    @include('partials.errors.field', ['field' => 'year_admitted'])
                </div>

                <div class="pat-field">
                    <label for="adm_no">Admission Number:</label>
                    <input type="text" name="adm_no" id="adm_no" placeholder="Admission Number" class="form-control @error('adm_no') is-invalid @enderror" value="{{ old('adm_no') }}">
                    @include('partials.errors.field', ['field' => 'adm_no'])
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Guardian &amp; Contact</div>
            <div class="pat-fields">
                <div class="pat-field">
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
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Dormitory &amp; House</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="dorm_id">Dormitory: </label>
                    <select data-placeholder="Choose..."  name="dorm_id" id="dorm_id" class="select-search form-control @error('dorm_id') is-invalid @enderror">
                        <option value=""></option>
                        @foreach($dorms as $d)
                            <option {{ (old('dorm_id') == $d->id) ? 'selected' : '' }} value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'dorm_id'])
                </div>

                <div class="pat-field">
                    <label for="dorm_room_no">Dormitory Room No:</label>
                    <input type="text" name="dorm_room_no" id="dorm_room_no" placeholder="Dormitory Room No" class="form-control @error('dorm_room_no') is-invalid @enderror" value="{{ old('dorm_room_no') }}">
                    @include('partials.errors.field', ['field' => 'dorm_room_no'])
                </div>

                <div class="pat-field">
                    <label for="house">Sport House:</label>
                    <input type="text" name="house" id="house" placeholder="Sport House" class="form-control @error('house') is-invalid @enderror" value="{{ old('house') }}">
                    @include('partials.errors.field', ['field' => 'house'])
                </div>
            </div>
        </div>
    @endcomponent
    </div>
@endsection

@section('scripts')
    <script>
        (function () {
            var tabs = document.querySelectorAll('[data-qa102-tab]');
            function activate(which) {
                tabs.forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-qa102-tab') === which); });
                document.getElementById('qa102-pane-quick').hidden = which !== 'quick';
                document.getElementById('qa102-pane-full').hidden = which !== 'full';
                window.dispatchEvent(new Event('resize'));
            }
            tabs.forEach(function (t) {
                t.addEventListener('click', function () { activate(t.getAttribute('data-qa102-tab')); });
            });
        })();
    </script>
@endsection
