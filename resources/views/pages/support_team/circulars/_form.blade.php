{{-- Workstream BA: fields laid out on the shared form_page pattern
     (Loop 28 .pat-* grid) — full-width rows instead of the legacy
     label-rail + col-lg-9 squeeze. Every field name, id, option value,
     default and validation hook is unchanged from the legacy form. --}}
@if(!isset($circular) && \App\Services\AiAssist::enabled())
    {{-- Workstream CX (Assist 1): AI draft panel — create form only.
         The draft fills the Title/Message fields below; nothing is
         saved until the user reviews and submits the form normally. --}}
    <div class="pat-form-section">
        <div class="pat-form-section-title">Draft with AI</div>
        <div class="pat-form-section-sub">Type a few words about the announcement, pick a tone and language, and AI will draft the title and message for you to review and edit.</div>
        <div class="pat-fields">
            <div class="pat-field pat-field-wide">
                <label for="ai_brief">What is this circular about?</label>
                <input id="ai_brief" type="text" class="form-control" placeholder="Eg. PTM Saturday 10am, bring report cards">
            </div>
            <div class="pat-field">
                <label for="ai_tone">Tone</label>
                <select id="ai_tone" class="form-control select">
                    <option value="Formal">Formal</option>
                    <option value="Friendly">Friendly</option>
                </select>
            </div>
            <div class="pat-field">
                <label for="ai_language">Language</label>
                <select id="ai_language" class="form-control select">
                    <option value="English">English</option>
                    <option value="Hindi">Hindi</option>
                    <option value="Tamil">Tamil</option>
                </select>
            </div>
            <div class="pat-field pat-field-wide">
                <button type="button" id="ai_draft_btn" class="btn btn-info">Draft with AI <i class="icon-magic-wand ml-2"></i></button>
                <span id="ai_draft_status" class="ml-2 text-muted"></span>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var btn = document.getElementById('ai_draft_btn');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var brief = document.getElementById('ai_brief').value.trim();
                var status = document.getElementById('ai_draft_status');
                if (!brief) { status.textContent = 'Type a few words first.'; return; }
                var meta = document.querySelector('meta[name="csrf-token"]');
                var body = 'brief=' + encodeURIComponent(brief)
                    + '&tone=' + encodeURIComponent(document.getElementById('ai_tone').value)
                    + '&language=' + encodeURIComponent(document.getElementById('ai_language').value);
                btn.disabled = true;
                status.textContent = 'Drafting…';
                fetch('{{ route('ai.circular_draft') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '', 'Accept': 'application/json' },
                    body: body
                }).then(function (r) {
                    return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                }).then(function (res) {
                    btn.disabled = false;
                    if (!res.ok) { status.textContent = res.data.message || 'AI is unavailable right now.'; return; }
                    document.getElementById('title').value = res.data.title;
                    document.getElementById('body').value = res.data.body;
                    status.textContent = 'Draft ready — review it below, edit anything, then Save.';
                }).catch(function () {
                    btn.disabled = false;
                    status.textContent = 'AI is unavailable right now. Please try again later.';
                });
            });
        })();
    </script>
@endif
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
