{{--
    QUICK ADMIT (Loop 102 · 2026-10-22) — essentials-only admission form.
    POSTs to students.quick_store (QuickAdmitRequest). Field names are
    q_-prefixed so its validation errors never collide with the full
    form's. Parent fields LINK an existing parent account only
    (importer parity) — no parent is created here.
--}}
@php
    $qaSession = (string) Qs::getSetting('current_session');
    $qaDefaultYear = old('q_year_admitted') ?: (preg_match('/^\d{4}/', $qaSession, $qaM) ? $qaM[0] : date('Y'));
    $qaYears = collect(range((int) date('Y') - 10, (int) date('Y')))
        ->push((int) $qaDefaultYear)->unique()->sort()->values();
    $qaOldClass = old('q_my_class_id');
    $qaSections = $qaOldClass ? App\Models\Section::where('my_class_id', $qaOldClass)->get() : collect();
@endphp
<form method="POST" action="{{ route('students.quick_store') }}" class="pat-form">
    @csrf
    <div class="card pat-form-card">
        <div class="pat-form-head">
            <div class="pat-form-sub">Admit a student with just the essentials. Address, nationality, blood group and the rest can be completed later from the student's Edit form.</div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">The Essentials</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="q_name">Full Name: <span class="text-danger">*</span></label>
                    <input value="{{ old('q_name') }}" required type="text" name="q_name" id="q_name" placeholder="Full Name" class="form-control @error('q_name') is-invalid @enderror">
                    @include('partials.errors.field', ['field' => 'q_name'])
                </div>

                <div class="pat-field">
                    <label for="q_gender">Gender: <span class="text-danger">*</span></label>
                    <select class="form-control @error('q_gender') is-invalid @enderror" id="q_gender" name="q_gender" required>
                        <option value="">Choose..</option>
                        <option {{ (old('q_gender') == 'Male') ? 'selected' : '' }} value="Male">Male</option>
                        <option {{ (old('q_gender') == 'Female') ? 'selected' : '' }} value="Female">Female</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'q_gender'])
                </div>

                <div class="pat-field">
                    <label for="q_my_class_id">Class: <span class="text-danger">*</span></label>
                    <select onchange="getClassSections(this.value, '#q_section_id')" required name="q_my_class_id" id="q_my_class_id" class="form-control @error('q_my_class_id') is-invalid @enderror">
                        <option value="">Choose...</option>
                        @foreach($my_classes as $c)
                            <option {{ (old('q_my_class_id') == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'q_my_class_id'])
                </div>

                <div class="pat-field">
                    <label for="q_section_id">Section: <span class="text-danger">*</span></label>
                    <select required name="q_section_id" id="q_section_id" class="form-control @error('q_section_id') is-invalid @enderror">
                        <option value="">{{ $qaSections->count() ? 'Choose...' : 'Select Class First' }}</option>
                        @foreach($qaSections as $qs)
                            <option {{ (old('q_section_id') == $qs->id) ? 'selected' : '' }} value="{{ $qs->id }}">{{ $qs->name }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'q_section_id'])
                </div>

                <div class="pat-field">
                    <label for="q_year_admitted">Year Admitted: <span class="text-danger">*</span></label>
                    <select required name="q_year_admitted" id="q_year_admitted" class="form-control @error('q_year_admitted') is-invalid @enderror">
                        @foreach($qaYears as $y)
                            <option {{ ((string) $qaDefaultYear === (string) $y) ? 'selected' : '' }} value="{{ $y }}">{{ $y }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'q_year_admitted'])
                </div>

                <div class="pat-field">
                    <label for="q_adm_no">Admission Number:</label>
                    <input type="text" name="q_adm_no" id="q_adm_no" placeholder="Leave blank to auto-generate" class="form-control @error('q_adm_no') is-invalid @enderror" value="{{ old('q_adm_no') }}">
                    @include('partials.errors.field', ['field' => 'q_adm_no'])
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Parent Link (Optional)</div>
            <div class="pat-form-section-sub">If the parent already has an account, enter their email or phone to link this student to them. New parent accounts are created from the Users page.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="q_parent_email">Parent Email:</label>
                    <input type="email" value="{{ old('q_parent_email') }}" name="q_parent_email" id="q_parent_email" class="form-control @error('q_parent_email') is-invalid @enderror" placeholder="Parent's account email">
                    @include('partials.errors.field', ['field' => 'q_parent_email'])
                </div>

                <div class="pat-field">
                    <label for="q_parent_phone">Parent Phone:</label>
                    <input type="text" value="{{ old('q_parent_phone') }}" name="q_parent_phone" id="q_parent_phone" class="form-control @error('q_parent_phone') is-invalid @enderror" placeholder="Parent's account phone">
                    @include('partials.errors.field', ['field' => 'q_parent_phone'])
                </div>
            </div>
        </div>

        <div class="pat-actionbar">
            <a href="{{ route('students.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Admit Student</button>
        </div>
    </div>
</form>
