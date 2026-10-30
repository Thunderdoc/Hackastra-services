@extends('layouts.master')
@section('page_title', 'New Parent Message')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">New Parent Message</h5>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
            <p class="text-muted">The message goes to every parent of students in the chosen class — or only one section, when you pick one. Parents get an in-app notification; no SMS or WhatsApp is sent.</p>
            <form method="post" action="{{ route('parent_messages.store') }}">
                @csrf
                <div class="form-group">
                    <label for="my_class_id">Class *</label>
                    <select name="my_class_id" id="my_class_id" class="form-control" required>
                        <option value="">Select a class</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ old('my_class_id') == $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="section_id">Section (optional — leave blank for the whole class)</label>
                    <select name="section_id" id="section_id" class="form-control">
                        <option value="">All sections</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" data-class="{{ $section->my_class_id }}" {{ old('section_id') == $section->id ? 'selected' : '' }}>{{ $section->my_class->name ?? '' }} — {{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="title">Title *</label>
                    <input type="text" name="title" id="title" class="form-control" value="{{ old('title') }}" required maxlength="255">
                </div>
                <div class="form-group">
                    <label for="body">Message *</label>
                    <textarea name="body" id="body" class="form-control" rows="5" required>{{ old('body') }}</textarea>
                </div>
                <button type="submit" class="btn btn-primary">Send Message</button>
                <a href="{{ route('parent_messages.index') }}" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
    <script>
    /* Class -> Section cascade (owner bug report 2026-10-30):
       the section list used to offer EVERY section in the school,
       so Class 12 could be paired with a Class 6 section and the
       send died on "The selected section id is invalid." Sections
       now follow the chosen class — on change and on load (which
       also repairs a mismatched pair restored from old input). */
    (function () {
        var classSel = document.getElementById('my_class_id');
        var sectionSel = document.getElementById('section_id');
        if (!classSel || !sectionSel) { return; }
        function cascade() {
            var classId = classSel.value;
            var keep = false;
            for (var i = 0; i < sectionSel.options.length; i++) {
                var opt = sectionSel.options[i];
                var oc = opt.getAttribute('data-class');
                var show = !opt.value || !classId || oc === classId;
                opt.style.display = show ? '' : 'none';
                opt.disabled = !show;
                if (show && opt.value && sectionSel.value === opt.value) { keep = true; }
            }
            if (!keep) { sectionSel.value = ''; }
        }
        classSel.addEventListener('change', cascade);
        cascade();
    })();
    </script>
@endsection
