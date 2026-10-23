{{-- Workstream BA: fields laid out on the shared form_page pattern
     (Loop 28 .pat-* grid) — full-width rows instead of the legacy
     label-rail + col-lg-9 squeeze. Every field name, id, option value,
     default and validation hook is unchanged from the legacy form. --}}
@if(!isset($circular) && \App\Services\AiAssist::enabled())
    {{-- Workstream CX (Assist 1): AI draft panel — create form only.
         The draft fills the Title/Message fields below; nothing is
         saved until the user reviews and submits the form normally. --}}
    <div class="pat-form-section">
        <div class="pat-form-section-title"><span class="pat-sec-icon"><i class="icon-magic-wand"></i></span> Draft with AI</div>
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
    <div class="pat-form-section-title"><span class="pat-sec-num">1</span> Circular Details</div>
    <div class="pat-form-section-sub">Enter the title and message for your circular.</div>
    <div class="pat-fields">
        <div class="pat-field pat-field-wide">
            <label for="title">Title <span class="text-danger">*</span> <span class="l75-count" id="title_count">0/150</span></label>
            <input name="title" id="title" value="{{ old('title', isset($circular) ? $circular->title : '') }}" required type="text" maxlength="150" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. School reopens on Monday">
            @include('partials.errors.field', ['field' => 'title'])
        </div>

        <div class="pat-field pat-field-wide">
            <label for="body">Message <span class="text-danger">*</span></label>
            {{-- Loop 75: plain-text formatting strip. The message is
                 stored and rendered as plain text, so these buttons
                 insert plain-text constructs (bullets, numbering, a
                 divider) that read exactly as typed in the feed. --}}
            <div class="l75-toolbar">
                <button type="button" class="l75-tbtn" data-fmt="bullet" title="Bullet list">&bull; List</button>
                <button type="button" class="l75-tbtn" data-fmt="numbered" title="Numbered list">1. List</button>
                <button type="button" class="l75-tbtn" data-fmt="divider" title="Divider line">&mdash; Divider</button>
            </div>
            <textarea name="body" id="body" rows="6" required class="form-control @error('body') is-invalid @enderror" placeholder="Write the full circular message here...">{{ old('body', isset($circular) ? $circular->body : '') }}</textarea>
            {{-- Loop 114: the count sits at the reference's bottom-right
                 of the editor. It carries no "/max" denominator: the
                 body has no server-side max (CircularSave), and the
                 house rule (Loop 87) is that counters only show a max
                 the server actually enforces. --}}
            <div class="l114-body-count"><span id="body_count">0</span></div>
            @include('partials.errors.field', ['field' => 'body'])
        </div>
    </div>
</div>

<div class="pat-form-section">
    <div class="pat-form-section-title"><span class="pat-sec-num">2</span> Audience &amp; Visibility</div>
    <div class="pat-form-section-sub">Choose who can see this circular and set the display period.</div>
    <div class="pat-fields">
        <div class="pat-field">
            <label for="audience">Audience <span class="text-danger">*</span></label>
            <select name="audience" id="audience" class="form-control select @error('audience') is-invalid @enderror">
                @foreach($audiences as $value => $label)
                    <option value="{{ $value }}" {{ old('audience', isset($circular) ? $circular->audience : 'all') == $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'audience'])
            <span class="form-text text-muted">Select the intended audience for this circular.</span>
        </div>

        <div class="pat-field">
            <label for="priority">Priority <span class="text-danger">*</span></label>
            <select name="priority" id="priority" class="form-control select @error('priority') is-invalid @enderror">
                @foreach($priorities as $value => $label)
                    <option value="{{ $value }}" {{ old('priority', isset($circular) ? $circular->priority : 'normal') == $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            @include('partials.errors.field', ['field' => 'priority'])
            <span class="form-text text-muted">Set the importance level.</span>
        </div>

        {{-- Loop 114: Status sits in the first row with Audience and
             Priority (reference order); Show From / Show Until follow. --}}
        <div class="pat-field">
            <label for="is_published">Status</label>
            <select name="is_published" id="is_published" class="form-control select @error('is_published') is-invalid @enderror">
                <option value="1" {{ old('is_published', isset($circular) ? (int) $circular->is_published : 1) == 1 ? 'selected' : '' }}>Published</option>
                <option value="0" {{ old('is_published', isset($circular) ? (int) $circular->is_published : 1) == 0 ? 'selected' : '' }}>Draft</option>
            </select>
            @include('partials.errors.field', ['field' => 'is_published'])
            <span class="form-text text-muted">Choose whether to publish now or save as draft.</span>
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
    </div>
</div>

{{-- Loop 105: attachments — the feedback system's contract
     (Workstream CW): at most 3 files per circular in total,
     images or PDF, 2 MB each. Files are served only through the
     audience-gated download route, never a public URL. --}}
<div class="pat-form-section">
    <div class="pat-form-section-title"><span class="pat-sec-num">3</span> Attachments (Optional)</div>
    <div class="pat-form-section-sub">Attach relevant files like PDFs, images, or notices.</div>
    <div class="pat-fields">
        @if(isset($circular) && $circular->attachments->isNotEmpty())
            <div class="pat-field pat-field-wide">
                <label>Current attachments</label>
                @foreach($circular->attachments as $attachment)
                    <div class="d-flex align-items-center mb-1">
                        <i class="icon-attachment mr-2"></i>
                        <a href="{{ $attachment->downloadUrl() }}">{{ $attachment->original_name }}</a>
                        <label class="ml-3 mb-0 text-danger" style="cursor:pointer">
                            <input type="checkbox" name="remove_attachments[]" value="{{ $attachment->id }}"> Remove
                        </label>
                    </div>
                @endforeach
                <span class="form-text text-muted">Tick Remove and save to delete a file. New uploads below are added to the ones you keep — 3 in total at most.</span>
            </div>
        @endif
        <div class="pat-field pat-field-wide">
            <label for="attachments">{{ isset($circular) ? 'Add attachments' : 'Attachments' }}</label>
            {{-- Loop 114: the Loop 75 upload zone (same pattern as the
                 feedback form) replaces the raw file input visually;
                 the input itself keeps its exact name/accept/multiple.
                 The hint states THIS app's real rules (3 files, 2 MB,
                 images or PDF) — not the reference mock's 5 MB/DOCX. --}}
            <div class="l75-upload" data-l75-upload>
                <input name="attachments[]" id="attachments" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" class="@error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror">
                <div class="l75-upload-body">
                    <span class="l75-upload-icon"><i class="icon-upload"></i></span>
                    <div>
                        <div class="l75-upload-main">Click to upload <span>or drag and drop files here</span></div>
                        <div class="l75-upload-hint">Optional. Up to 3 files in total — images (jpg, png, gif, webp) or PDF, at most 2 MB each. Only the circular's audience can download them.</div>
                    </div>
                </div>
                <ul class="l75-upload-files"></ul>
            </div>
            @include('partials.errors.field', ['field' => 'attachments'])
            @include('partials.errors.field', ['field' => 'attachments.*'])
        </div>
    </div>
</div>

{{-- Loop 75: composer helpers — character counts, the plain-text
     formatting strip, and the live preview sync. Everything reads
     the real form fields; the preview panel (_preview partial)
     mirrors the feed item from those values. A short interval
     keeps the preview honest even when the AI draft fills the
     fields programmatically. --}}
<script>
    (function () {
        var titleEl = document.getElementById('title');
        var bodyEl = document.getElementById('body');
        var audEl = document.getElementById('audience');
        var priEl = document.getElementById('priority');
        var titleCount = document.getElementById('title_count');
        var bodyCount = document.getElementById('body_count');
        if (!titleEl || !bodyEl) return;

        function syncCounts() {
            // Loop 114: the title counter always reads "N/150" — 150 is
            // the real server max (CircularSave). The body count stays
            // a plain number: the body has no server max to divide by.
            if (titleCount) titleCount.textContent = titleEl.value.length + '/150';
            if (bodyCount) bodyCount.textContent = bodyEl.value.length;
        }

        // Loop 114: the preview elements are looked up LAZILY inside
        // syncPreview — this script renders before the _preview
        // partial in the page, so capturing them here at IIFE time
        // found nothing and the sync silently never ran.
        var pvItem = null, pvTitle = null, pvBody = null, pvAud = null, pvPri = null, pvFrom = null, pvUntil = null;
        var fromEl = document.getElementById('starts_at');
        var untilEl = document.getElementById('expires_at');
        var last = '';
        function fmtDate(v) {
            if (!v) return '';
            var p = v.split('-');
            if (p.length !== 3) return v;
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return parseInt(p[2], 10) + ' ' + months[parseInt(p[1], 10) - 1] + ' ' + p[0];
        }
        function syncPreview() {
            if (!pvItem) {
                pvItem = document.getElementById('pv_item');
                pvTitle = document.getElementById('pv_title');
                pvBody = document.getElementById('pv_body');
                pvAud = document.getElementById('pv_aud');
                pvPri = document.getElementById('pv_pri');
                pvFrom = document.getElementById('pv_from');
                pvUntil = document.getElementById('pv_until');
            }
            if (!pvItem) return;
            var key = [titleEl.value, bodyEl.value, audEl ? audEl.value : '', priEl ? priEl.value : '', fromEl ? fromEl.value : '', untilEl ? untilEl.value : ''].join('|');
            if (key === last) return;
            last = key;
            var t = titleEl.value.trim();
            pvTitle.textContent = t || 'Circular Title';
            pvTitle.classList.toggle('l75-pv-placeholder', !t);
            var b = bodyEl.value.trim();
            var excerpt = b.length > 220 ? b.substring(0, 220).replace(/\s+$/, '') + '...' : b;
            pvBody.textContent = excerpt || 'Your circular message will appear here. This is a preview of how the circular will look to the selected audience.';
            pvBody.classList.toggle('l75-pv-placeholder', !b);
            if (priEl) {
                pvItem.className = 'circ-item circ-pri-' + (priEl.value || 'normal');
                if (pvPri) pvPri.textContent = priEl.options[priEl.selectedIndex].text;
            }
            if (audEl && pvAud) {
                pvAud.textContent = audEl.options[audEl.selectedIndex].text;
            }
            if (pvFrom) pvFrom.textContent = fromEl && fromEl.value ? fmtDate(fromEl.value) : 'Immediate';
            if (pvUntil) pvUntil.textContent = untilEl && untilEl.value ? fmtDate(untilEl.value) : 'Until unpublished';
        }
        function syncAll() { syncCounts(); syncPreview(); }

        function prefixLines(fmt) {
            var start = bodyEl.selectionStart, end = bodyEl.selectionEnd;
            var val = bodyEl.value;
            var selStart = val.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
            var selEnd = end;
            var nl = val.indexOf('\n', end);
            if (start === end) { selEnd = nl === -1 ? val.length : nl; }
            var block = val.substring(selStart, selEnd);
            var n = 0;
            var done = block.split('\n').map(function (line) {
                if (!line.trim()) return line;
                n++;
                return fmt === 'numbered' ? (n + '. ' + line) : ('• ' + line);
            }).join('\n');
            bodyEl.value = val.substring(0, selStart) + done + val.substring(selEnd);
            bodyEl.focus();
            bodyEl.setSelectionRange(selStart, selStart + done.length);
            syncAll();
        }
        function insertDivider() {
            var start = bodyEl.selectionStart, end = bodyEl.selectionEnd;
            var val = bodyEl.value;
            var ins = (start > 0 && val.charAt(start - 1) !== '\n' ? '\n' : '') + '—————\n';
            bodyEl.value = val.substring(0, start) + ins + val.substring(end);
            bodyEl.focus();
            bodyEl.setSelectionRange(start + ins.length, start + ins.length);
            syncAll();
        }
        Array.prototype.forEach.call(document.querySelectorAll('.l75-tbtn'), function (btn) {
            btn.addEventListener('click', function () {
                var fmt = btn.getAttribute('data-fmt');
                if (fmt === 'divider') { insertDivider(); } else { prefixLines(fmt); }
            });
        });

        [titleEl, bodyEl].forEach(function (el) { el.addEventListener('input', syncAll); });
        [audEl, priEl, fromEl, untilEl].forEach(function (el) { if (el) el.addEventListener('change', syncAll); });
        syncAll();
        setInterval(syncAll, 800);

        // Loop 114: upload-zone file list + drag state (same wiring
        // as the feedback form's zone). Presentation only — the
        // input and its submitted files are untouched.
        var zone = document.querySelector('[data-l75-upload]');
        if (zone) {
            var upInput = zone.querySelector('input[type="file"]');
            var upList = zone.querySelector('.l75-upload-files');
            function renderFiles() {
                upList.innerHTML = '';
                if (!upInput.files) return;
                for (var i = 0; i < upInput.files.length; i++) {
                    var li = document.createElement('li');
                    var icon = document.createElement('i');
                    icon.className = 'icon-file-text2';
                    li.appendChild(icon);
                    li.appendChild(document.createTextNode(upInput.files[i].name));
                    upList.appendChild(li);
                }
            }
            upInput.addEventListener('change', renderFiles);
            ['dragenter', 'dragover'].forEach(function (ev) {
                zone.addEventListener(ev, function () { zone.classList.add('is-dragover'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                zone.addEventListener(ev, function () { zone.classList.remove('is-dragover'); });
            });
        }
    })();
</script>
