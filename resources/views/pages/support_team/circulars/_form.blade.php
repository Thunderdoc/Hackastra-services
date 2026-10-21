<div class="form-group row">
    <label for="title" class="col-lg-3 col-form-label font-weight-semibold">Title <span class="text-danger">*</span></label>
    <div class="col-lg-9">
        <input name="title" id="title" value="{{ old('title', isset($circular) ? $circular->title : '') }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. School reopens on Monday">
        @include('partials.errors.field', ['field' => 'title'])
    </div>
</div>

<div class="form-group row">
    <label for="body" class="col-lg-3 col-form-label font-weight-semibold">Message <span class="text-danger">*</span></label>
    <div class="col-lg-9">
        <textarea name="body" id="body" rows="6" required class="form-control @error('body') is-invalid @enderror" placeholder="Write the full circular message here">{{ old('body', isset($circular) ? $circular->body : '') }}</textarea>
        @include('partials.errors.field', ['field' => 'body'])
    </div>
</div>

<div class="form-group row">
    <label for="audience" class="col-lg-3 col-form-label font-weight-semibold">Audience <span class="text-danger">*</span></label>
    <div class="col-lg-9">
        <select name="audience" id="audience" class="form-control select @error('audience') is-invalid @enderror">
            @foreach($audiences as $value => $label)
                <option value="{{ $value }}" {{ old('audience', isset($circular) ? $circular->audience : 'all') == $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        @include('partials.errors.field', ['field' => 'audience'])
    </div>
</div>

<div class="form-group row">
    <label for="priority" class="col-lg-3 col-form-label font-weight-semibold">Priority <span class="text-danger">*</span></label>
    <div class="col-lg-9">
        <select name="priority" id="priority" class="form-control select @error('priority') is-invalid @enderror">
            @foreach($priorities as $value => $label)
                <option value="{{ $value }}" {{ old('priority', isset($circular) ? $circular->priority : 'normal') == $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        @include('partials.errors.field', ['field' => 'priority'])
    </div>
</div>

<div class="form-group row">
    <label for="starts_at" class="col-lg-3 col-form-label font-weight-semibold">Show From</label>
    <div class="col-lg-9">
        <input name="starts_at" id="starts_at" value="{{ old('starts_at', isset($circular) && $circular->starts_at ? $circular->starts_at->format('Y-m-d') : '') }}" type="date" class="form-control @error('starts_at') is-invalid @enderror">
        @include('partials.errors.field', ['field' => 'starts_at'])
        <span class="form-text text-muted">Leave empty to show immediately.</span>
    </div>
</div>

<div class="form-group row">
    <label for="expires_at" class="col-lg-3 col-form-label font-weight-semibold">Show Until</label>
    <div class="col-lg-9">
        <input name="expires_at" id="expires_at" value="{{ old('expires_at', isset($circular) && $circular->expires_at ? $circular->expires_at->format('Y-m-d') : '') }}" type="date" class="form-control @error('expires_at') is-invalid @enderror">
        @include('partials.errors.field', ['field' => 'expires_at'])
        <span class="form-text text-muted">Leave empty to keep showing until unpublished.</span>
    </div>
</div>

<div class="form-group row">
    <label for="is_published" class="col-lg-3 col-form-label font-weight-semibold">Status</label>
    <div class="col-lg-9">
        <select name="is_published" id="is_published" class="form-control select @error('is_published') is-invalid @enderror">
            <option value="1" {{ old('is_published', isset($circular) ? (int) $circular->is_published : 1) == 1 ? 'selected' : '' }}>Published</option>
            <option value="0" {{ old('is_published', isset($circular) ? (int) $circular->is_published : 1) == 0 ? 'selected' : '' }}>Draft (hidden from readers)</option>
        </select>
        @include('partials.errors.field', ['field' => 'is_published'])
    </div>
</div>
