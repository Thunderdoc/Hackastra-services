{{-- Workstream CS2 (LMS): study-material fields on the shared
     form_page pattern (Loop 28 .pat-* grid). Contract fields:
     my_class_id, section_id (nullable = whole class), subject_id,
     title, description, file (upload) OR link_url. --}}
<div class="pat-form-section">
    <div class="pat-form-section-title">Material Details</div>
    <div class="pat-fields">
        <div class="pat-field pat-field-wide">
            <label for="title">Title <span class="text-danger">*</span></label>
            <input name="title" id="title" value="{{ old('title', $material->title ?? '') }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. Chapter 4 notes — Quadratic Equations">
            @include('partials.errors.field', ['field' => 'title'])
        </div>

        <div class="pat-field pat-field-wide">
            <label for="description">Description (optional)</label>
            <textarea name="description" id="description" rows="3" class="form-control @error('description') is-invalid @enderror" placeholder="What is in this material, and which pages/topics should students focus on?">{{ old('description', $material->description ?? '') }}</textarea>
            @include('partials.errors.field', ['field' => 'description'])
        </div>
    </div>
</div>

<div class="pat-form-section">
    <div class="pat-form-section-title">File or Link</div>
    <div class="pat-fields">
        <div class="pat-field pat-field-wide">
            <label for="file">Upload a file (PDF, notes, slides, image&hellip;)</label>
            <input name="file" id="file" type="file" class="form-control @error('file') is-invalid @enderror">
            @include('partials.errors.field', ['field' => 'file'])
            <span class="form-text text-muted">Upload a file <em>or</em> paste a link below — one of the two is enough.</span>
        </div>

        <div class="pat-field pat-field-wide">
            <label for="link_url">Or paste a link (video, article, drive file&hellip;)</label>
            <input name="link_url" id="link_url" value="{{ old('link_url', $material->link_url ?? '') }}" type="url" class="form-control @error('link_url') is-invalid @enderror" placeholder="https://">
            @include('partials.errors.field', ['field' => 'link_url'])
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
                    <option value="{{ $class->id }}" {{ (string) old('my_class_id', $material->my_class_id ?? '') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'my_class_id'])
        </div>

        <div class="pat-field">
            <label for="section_id">Section</label>
            <select name="section_id" id="section_id" class="form-control select @error('section_id') is-invalid @enderror">
                <option value="">Whole class (all sections)</option>
                @foreach($sections ?? [] as $section)
                    <option value="{{ $section->id }}" {{ (string) old('section_id', $material->section_id ?? '') === (string) $section->id ? 'selected' : '' }}>{{ $section->name }}{{ isset($section->my_class) ? ' — ' . $section->my_class->name : '' }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'section_id'])
        </div>

        <div class="pat-field">
            <label for="subject_id">Subject <span class="text-danger">*</span></label>
            <select name="subject_id" id="subject_id" required class="form-control select @error('subject_id') is-invalid @enderror">
                <option value="">Select subject</option>
                @foreach($subjects ?? [] as $subject)
                    <option value="{{ $subject->id }}" {{ (string) old('subject_id', $material->subject_id ?? '') === (string) $subject->id ? 'selected' : '' }}>{{ $subject->name }}{{ isset($subject->my_class) ? ' — ' . $subject->my_class->name : '' }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'subject_id'])
        </div>
    </div>
</div>
