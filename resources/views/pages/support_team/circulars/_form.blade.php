{{-- Workstream BA: fields laid out on the shared form_page pattern
     (Loop 28 .pat-* grid) — full-width rows instead of the legacy
     label-rail + col-lg-9 squeeze. Every field name, id, option value,
     default and validation hook is unchanged from the legacy form. --}}
<div class="pat-form-section">
    <div class="pat-form-section-title">Circular Details</div>
    <div class="pat-fields">
        <div class="pat-field pat-field-wide">
            <label for="title">Title <span class="text-danger">*</span></label>
            <input name="title" id="title" value="{{ old('title', isset($circular) ? $circular->title : '') }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. School reopens on Monday">
            @include('partials.errors.field', ['field' => 'title'])
        </div>

        <div class="pat-field pat-field-wide">
            <label for="body">Message <span class="text-danger">*</span></label>
            <textarea name="body" id="body" rows="6" required class="form-control @error('body') is-invalid @enderror" placeholder="Write the full circular message here">{{ old('body', isset($circular) ? $circular->body : '') }}</textarea>
            @include('partials.errors.field', ['field' => 'body'])
        </div>
    </div>
</div>

<div class="pat-form-section">
    <div class="pat-form-section-title">Audience &amp; Visibility</div>
    <div class="pat-fields">
        <div class="pat-field">
            <label for="audience">Audience <span class="text-danger">*</span></label>
            <select name="audience" id="audience" class="form-control select @error('audience') is-invalid @enderror">
                @foreach($audiences as $value => $label)
                    <option value="{{ $value }}" {{ old('audience', isset($circular) ? $circular->audience : 'all') == $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'audience'])
        </div>

        <div class="pat-field">
            <label for="priority">Priority <span class="text-danger">*</span></label>
            <select name="priority" id="priority" class="form-control select @error('priority') is-invalid @enderror">
                @foreach($priorities as $value => $label)
                    <option value="{{ $value }}" {{ old('priority', isset($circular) ? $circular->priority : 'normal') == $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'priority'])
        </div>

        <div class="pat-field">
            <label for="starts_at">Show From</label>
            <input name="starts_at" id="starts_at" value="{{ old('starts_at', isset($circular) && $circular->starts_at ? $circular->starts_at->format('Y-m-d') : '') }}" type="date" class="form-control @error('starts_at') is-invalid @enderror">
            @include('partials.errors.field', ['field' => 'starts_at'])
            <span class="form-text text-muted">Leave empty to show immediately.</span>
        </div>

        <div class="pat-field">
            <label for="expires_at">Show Until</label>
            <input name="expires_at" id="expires_at" value="{{ old('expires_at', isset($circular) && $circular->expires_at ? $circular->expires_at->format('Y-m-d') : '') }}" type="date" class="form-control @error('expires_at') is-invalid @enderror">
            @include('partials.errors.field', ['field' => 'expires_at'])
            <span class="form-text text-muted">Leave empty to keep showing until unpublished.</span>
        </div>

        <div class="pat-field">
            <label for="is_published">Status</label>
            <select name="is_published" id="is_published" class="form-control select @error('is_published') is-invalid @enderror">
                <option value="1" {{ old('is_published', isset($circular) ? (int) $circular->is_published : 1) == 1 ? 'selected' : '' }}>Published</option>
                <option value="0" {{ old('is_published', isset($circular) ? (int) $circular->is_published : 1) == 0 ? 'selected' : '' }}>Draft (hidden from readers)</option>
            </select>
            @include('partials.errors.field', ['field' => 'is_published'])
        </div>
    </div>
</div>
