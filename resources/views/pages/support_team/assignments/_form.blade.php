{{-- Workstream CS2 (LMS): assignment fields on the shared form_page
     pattern (Loop 28 .pat-* grid), following circulars/_form. Field
     names match the LMS contract: my_class_id, section_id (nullable =
     whole class), subject_id, title, description, attachment,
     max_marks, due_at, allow_late. Options come from $classes,
     $sections, $subjects passed by the controller (the teacher's own
     classes/sections/subjects). --}}
<div class="pat-form-section">
    <div class="pat-form-section-title">Assignment Details</div>
    <div class="pat-fields">
        <div class="pat-field pat-field-wide">
            <label for="title">Title <span class="text-danger">*</span></label>
            <input name="title" id="title" value="{{ old('title', $assignment->title ?? '') }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. Chapter 4 exercises — questions 1 to 10">
            @include('partials.errors.field', ['field' => 'title'])
        </div>

        <div class="pat-field pat-field-wide">
            <label for="description">Instructions</label>
            <textarea name="description" id="description" rows="5" class="form-control @error('description') is-invalid @enderror" placeholder="What should students do, and how should they submit it?">{{ old('description', $assignment->description ?? '') }}</textarea>
            @include('partials.errors.field', ['field' => 'description'])
            <span class="form-text text-muted">Shown to students exactly as written — no paper handouts needed.</span>
        </div>

        <div class="pat-field pat-field-wide">
            <label for="attachment">Attachment (question paper, worksheet, image&hellip;)</label>
            <input name="attachment" id="attachment" type="file" class="form-control @error('attachment') is-invalid @enderror">
            @include('partials.errors.field', ['field' => 'attachment'])
            @if(!empty($assignment->attachment_path))
                <span class="form-text text-muted">A file is already attached — uploading a new one replaces it. Students can download it from the assignment page.</span>
            @endif
        </div>
    </div>
</div>

<div class="pat-form-section">
    <div class="pat-form-section-title">Class &amp; Subject</div>
    <div class="pat-fields">
        <div class="pat-field">
            <label for="my_class_id">Class <span class="text-danger">*</span></label>
            <select name="my_class_id" id="my_class_id" required class="form-control select @error('my_class_id') is-invalid @enderror">
                <option value="">Select class</option>
                @foreach($classes ?? [] as $class)
                    <option value="{{ $class->id }}" {{ (string) old('my_class_id', $assignment->my_class_id ?? '') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'my_class_id'])
        </div>

        <div class="pat-field">
            <label for="section_id">Section</label>
            <select name="section_id" id="section_id" class="form-control select @error('section_id') is-invalid @enderror">
                <option value="">Whole class (all sections)</option>
                @foreach($sections ?? [] as $section)
                    <option value="{{ $section->id }}" {{ (string) old('section_id', $assignment->section_id ?? '') === (string) $section->id ? 'selected' : '' }}>{{ $section->name }}{{ isset($section->my_class) ? ' — ' . $section->my_class->name : '' }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'section_id'])
        </div>

        <div class="pat-field">
            <label for="subject_id">Subject <span class="text-danger">*</span></label>
            <select name="subject_id" id="subject_id" required class="form-control select @error('subject_id') is-invalid @enderror">
                <option value="">Select subject</option>
                @foreach($subjects ?? [] as $subject)
                    <option value="{{ $subject->id }}" {{ (string) old('subject_id', $assignment->subject_id ?? '') === (string) $subject->id ? 'selected' : '' }}>{{ $subject->name }}{{ isset($subject->my_class) ? ' — ' . $subject->my_class->name : '' }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'subject_id'])
        </div>
    </div>
</div>

<div class="pat-form-section">
    <div class="pat-form-section-title">Marks &amp; Deadline</div>
    <div class="pat-fields">
        <div class="pat-field">
            <label for="max_marks">Maximum Marks <span class="text-danger">*</span></label>
            <input name="max_marks" id="max_marks" value="{{ old('max_marks', $assignment->max_marks ?? 10) }}" required type="number" min="1" step="1" class="form-control @error('max_marks') is-invalid @enderror">
            @include('partials.errors.field', ['field' => 'max_marks'])
            <span class="form-text text-muted">Default 10 — the usual internal-assessment scale.</span>
        </div>

        <div class="pat-field">
            <label for="due_at">Submission Deadline <span class="text-danger">*</span></label>
            <input name="due_at" id="due_at" value="{{ old('due_at', isset($assignment) && $assignment->due_at ? \Carbon\Carbon::parse($assignment->due_at)->format('Y-m-d\TH:i') : '') }}" required type="datetime-local" class="form-control @error('due_at') is-invalid @enderror">
            @include('partials.errors.field', ['field' => 'due_at'])
        </div>

        <div class="pat-field">
            <label for="allow_late">Late Submissions</label>
            <select name="allow_late" id="allow_late" class="form-control select @error('allow_late') is-invalid @enderror">
                <option value="1" {{ (string) old('allow_late', isset($assignment) ? (int) $assignment->allow_late : 1) === '1' ? 'selected' : '' }}>Allow (marked LATE)</option>
                <option value="0" {{ (string) old('allow_late', isset($assignment) ? (int) $assignment->allow_late : 1) === '0' ? 'selected' : '' }}>Do not allow after the deadline</option>
            </select>
            @include('partials.errors.field', ['field' => 'allow_late'])
        </div>
    </div>
</div>
