@extends('layouts.master')
@section('page_title', 'Give Feedback / Report an Issue')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Communication &nbsp;/&nbsp; <a href="{{ route('feedback.mine') }}">My Feedback</a> &nbsp;/&nbsp; Report an Issue
@endsection
@section('content')
    {{-- Feedback & issue report (Workstream CW + Loop 87 uplift): any
         signed-in user reports to the school Admin on the shared
         form_page pattern. Loop 87 wraps it in a two-column layout
         with a context rail; the 4-step strip is a VISUAL GUIDE over
         this single-page form (no wizard, no fake Review step).
         The hidden page_url field is the auto-log: it carries the
         ?from= deep-link target when present, else the page the
         reporter came from — the reporter never types it. --}}
    @php
        $fbFrom = request('from');
        if (!$fbFrom) {
            $fbPrev = parse_url(url()->previous());
            $fbFrom = ($fbPrev['path'] ?? '') . (!empty($fbPrev['query']) ? '?' . $fbPrev['query'] : '');
        }
    @endphp
  <div class="row l87-create-row">
    <div class="col-lg-8">
    @component('partials.patterns.form_page', [
        'form_action' => route('feedback.store'),
        'form_enctype' => 'multipart/form-data',
        'back' => route('feedback.mine'),
    ])
        @slot('subtitle')
            Tell the school Admin about any work, facility, service or app issue — anywhere in the school. You can follow its progress under <strong>My Feedback</strong>.
        @endslot

        {{-- Visual guide only: one single-page form, step 1 active. --}}
        <div class="l87-steps" aria-hidden="true">
            <div class="l87-step is-active"><span class="l87-step-dot">1</span><span class="l87-step-label">Report Details</span></div>
            <div class="l87-step"><span class="l87-step-dot">2</span><span class="l87-step-label">Describe &amp; Attach</span></div>
            <div class="l87-step"><span class="l87-step-dot">3</span><span class="l87-step-label">Review</span></div>
            <div class="l87-step"><span class="l87-step-dot">4</span><span class="l87-step-label">Submit</span></div>
        </div>

        <input type="hidden" name="page_url" value="{{ old('page_url', $fbFrom) }}">

        <div class="pat-form-section">
            <div class="pat-form-section-title"><span class="pat-sec-icon"><i class="icon-file-text2"></i></span> Report Details</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="type">Type <span class="text-danger">*</span></label>
                    <select name="type" id="type" class="form-control select @error('type') is-invalid @enderror">
                        @foreach($types as $value => $label)
                            <option value="{{ $value }}" {{ old('type', 'problem') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'type'])
                </div>

                <div class="pat-field">
                    <label for="category">Area <span class="text-danger">*</span></label>
                    <select name="category" id="category" class="form-control select @error('category') is-invalid @enderror">
                        @foreach($categories as $value => $label)
                            <option value="{{ $value }}" {{ old('category') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'category'])
                </div>

                <div class="pat-field">
                    <label for="priority">Priority <span class="text-danger">*</span></label>
                    <select name="priority" id="priority" class="form-control select @error('priority') is-invalid @enderror">
                        @foreach($priorities as $value => $label)
                            <option value="{{ $value }}" {{ old('priority', 'medium') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'priority'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="title">Title <span class="text-danger">*</span></label>
                    <input name="title" id="title" value="{{ old('title') }}" required maxlength="150" type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. Water cooler not working near Block B">
                    <div class="l87-counter" data-l87-counter-for="title">0 / 150</div>
                    @include('partials.errors.field', ['field' => 'title'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="context">Where did this happen?</label>
                    <input name="context" id="context" value="{{ old('context') }}" maxlength="255" type="text" class="form-control @error('context') is-invalid @enderror" placeholder="Eg. Ground floor, Block B, near the library">
                    <div class="l87-counter" data-l87-counter-for="context">0 / 255</div>
                    @include('partials.errors.field', ['field' => 'context'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="description">Description <span class="text-danger">*</span></label>
                    <textarea name="description" id="description" rows="5" required class="form-control @error('description') is-invalid @enderror" placeholder="Describe the issue or feedback in detail — what happened, since when, and anything that helps the Admin act on it.">{{ old('description') }}</textarea>
                    @include('partials.errors.field', ['field' => 'description'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="attachments"><i class="icon-camera mr-1"></i> Add screenshot / files</label>
                    {{-- Loop 75: styled upload zone — the real input is
                         unchanged (name/accept/multiple), stretched over
                         the zone; the list below previews chosen names.
                         Contract mirrors FeedbackSave exactly: up to 3
                         files, images or PDF, 2 MB each. --}}
                    <div class="l75-upload" data-l75-upload>
                        <input name="attachments[]" id="attachments" type="file" multiple accept="image/*,.pdf" class="@error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror">
                        <div class="l75-upload-body">
                            <span class="l75-upload-icon"><i class="icon-upload"></i></span>
                            <div>
                                <div class="l75-upload-main">Choose screenshots or files <span>or drag them here</span></div>
                                <div class="l75-upload-hint">Images (jpg, png, gif, webp) or PDF</div>
                            </div>
                        </div>
                        <ul class="l75-upload-files"></ul>
                    </div>
                    @include('partials.errors.field', ['field' => 'attachments'])
                    <span class="form-text text-muted">Attach up to <strong>3 files</strong> — screenshots help the most. Images (jpg, png, gif, webp) or PDF, up to 2 MB each.</span>
                </div>
            </div>
        </div>

        @slot('actions')
            <a href="{{ route('feedback.mine') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Send to Admin <i class="icon-paperplane ml-2"></i></button>
        @endslot
    @endcomponent
    </div>

    <div class="col-lg-4">
        <aside class="l87-rail">
            <div class="card l87-rail-card">
                <div class="card-body">
                    <div class="l87-rail-head">
                        <span class="l87-rail-icon"><i class="icon-bubbles"></i></span>
                        <h6 class="l87-rail-title">Your Feedback Matters</h6>
                    </div>
                    <p class="text-muted mb-0">Every report goes to your school Admin, who reviews it and takes action. If an issue needs platform-level help, the Admin forwards a compiled report to the Super Admin. You can follow the status of every report under <strong>My Feedback</strong>.</p>
                </div>
            </div>

            <div class="card l87-rail-card">
                <div class="card-body">
                    <div class="l87-rail-head">
                        <span class="l87-rail-icon"><i class="icon-list"></i></span>
                        <h6 class="l87-rail-title">Common Examples</h6>
                    </div>
                    <ul class="l87-examples">
                        <li><i class="icon-checkmark-circle2"></i> Facility or maintenance issue</li>
                        <li><i class="icon-checkmark-circle2"></i> Classroom or laboratory concern</li>
                        <li><i class="icon-checkmark-circle2"></i> Cleanliness &amp; safety</li>
                        <li><i class="icon-checkmark-circle2"></i> IT system or app problem</li>
                        <li><i class="icon-checkmark-circle2"></i> Academic suggestion</li>
                        <li><i class="icon-checkmark-circle2"></i> Any other concern</li>
                    </ul>
                </div>
            </div>

            <div class="card l87-rail-card">
                <div class="card-body">
                    <div class="l87-rail-head">
                        <span class="l87-rail-icon"><i class="icon-shield-check"></i></span>
                        <h6 class="l87-rail-title">Rest Assured</h6>
                    </div>
                    <p class="text-muted mb-0">Only you and the school Admin can see your report. If the Admin forwards it for platform-level help, the Super Admin sees it too. Other students, parents and teachers cannot view your reports.</p>
                </div>
            </div>
        </aside>
    </div>
  </div>

    {{-- Loop 75: upload-zone preview — lists the chosen file names
         inside the zone; the input itself is untouched.
         Loop 87: live character counters for the two fields whose
         server validation carries a max (title 150, location 255).
         Description has no server max, so it gets no counter. --}}
    <script>
        (function () {
            var zone = document.querySelector('[data-l75-upload]');
            if (zone) {
                var input = zone.querySelector('input[type="file"]');
                var list = zone.querySelector('.l75-upload-files');
                function render() {
                    list.innerHTML = '';
                    if (!input.files) return;
                    for (var i = 0; i < input.files.length; i++) {
                        var li = document.createElement('li');
                        var icon = document.createElement('i');
                        icon.className = 'icon-file-text2';
                        li.appendChild(icon);
                        li.appendChild(document.createTextNode(input.files[i].name));
                        list.appendChild(li);
                    }
                }
                input.addEventListener('change', render);
                ['dragenter', 'dragover'].forEach(function (ev) {
                    zone.addEventListener(ev, function () { zone.classList.add('is-dragover'); });
                });
                ['dragleave', 'drop'].forEach(function (ev) {
                    zone.addEventListener(ev, function () { zone.classList.remove('is-dragover'); });
                });
            }

            document.querySelectorAll('[data-l87-counter-for]').forEach(function (el) {
                var field = document.getElementById(el.getAttribute('data-l87-counter-for'));
                if (!field) return;
                var max = field.getAttribute('maxlength');
                function update() { el.textContent = field.value.length + ' / ' + max; }
                field.addEventListener('input', update);
                update();
            });
        })();
    </script>
@endsection
