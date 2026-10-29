@extends('layouts.master')
@section('page_title', 'Reports & PDF Builder')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Reports &amp; PDF Builder
@endsection
@section('content')
<style>
    /* Builder redesign 2026-10-29: simple premium SaaS look, scoped
       to this page only — no shared component is touched. White
       cards, subtle blue accents (#1765f5 / #10213f), soft
       #dfe8f3 borders, 12px radii. Two-panel desktop layout
       (settings left, live A4 preview right); stacked on mobile. */
    .rb-settings .card, .rb-preview .card { background: #fff; border: 1px solid #dfe8f3; border-radius: 12px; box-shadow: none; }
    .rb-settings .card-header, .rb-preview .card-header { background: #fff; border-bottom: 1px solid #dfe8f3; border-radius: 12px 12px 0 0; }
    .rb-col-row td { vertical-align: middle; }
    .rb-col-row input[type="text"], .rb-col-row input[type="number"], .rb-col-row select { width: 100%; }
    .rb-preview-wrap { background: #f4f8fd; border: 1px solid #dfe8f3; border-radius: 12px; padding: 12px; }
    #previewFrame { width: 100%; height: 720px; border: 1px solid #dfe8f3; border-radius: 8px; background: #fff; display: block; }
    .rb-status { font-size: 13px; color: #4a5a6e; margin: 0 0 10px; line-height: 1.5; }
    .rb-status.rb-status-error { color: #b42318; }
    .rb-loading { font-size: 13px; color: #1765f5; margin: 0 0 10px; }
    @media (min-width: 992px) { .rb-preview-card { position: sticky; top: 20px; } }
    @media (max-width: 991px) { #previewFrame { height: 480px; } }
</style>

<div class="row">
    <div class="col-lg-5 rb-settings" id="rbSettings">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Build your report</h5></div>
            <div class="card-body">
                <div class="form-group">
                    <label>Start from a template</label>
                    <select id="rbPreset" class="form-control">
                        <option value="">— Custom (blank) —</option>
                        @foreach($presets as $preset)
                            <option value="{{ $preset['key'] }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Data source *</label>
                    <select id="rbSource" class="form-control">
                        @foreach($sources as $srcKey => $srcDef)
                            <option value="{{ $srcKey }}">{{ $srcDef['label'] }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">Changing the source resets the column list. Teachers see only their own classes and subjects.</small>
                </div>
                <div id="rbMarksHint" class="alert alert-info" style="display:none;">
                    Marks reports require an exam, a class and a subject. Component columns (Theory, Internal, Viva, …) are added automatically for configured marking schemes.
                </div>
                <div class="form-group">
                    <label>Report title *</label>
                    <input type="text" id="rbTitle" class="form-control" maxlength="120" placeholder="e.g. Class Roster — Prep A">
                </div>
                <div class="form-group">
                    <label>Subtitle</label>
                    <input type="text" id="rbSubtitle" class="form-control" maxlength="200" placeholder="Optional line under the title">
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Class</label>
                        <select id="rbClass" class="form-control">
                            <option value="">All classes</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}">{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Section</label>
                        <select id="rbSection" class="form-control">
                            <option value="">All sections</option>
                            @foreach($sectionsMap as $sec)
                                <option value="{{ $sec['id'] }}" data-class="{{ $sec['my_class_id'] }}">{{ $sec['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Session</label>
                        <input type="text" id="rbYear" class="form-control" value="{{ $currentSession }}">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Subject</label>
                        <select id="rbSubject" class="form-control">
                            <option value="">All subjects</option>
                            @foreach($subjectsMap as $subj)
                                <option value="{{ $subj['id'] }}" data-class="{{ $subj['my_class_id'] }}">{{ $subj['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Exam</label>
                        <select id="rbExam" class="form-control">
                            <option value="">All exams</option>
                            @foreach($exams as $exam)
                                <option value="{{ $exam->id }}">{{ $exam->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>From date</label>
                        <input type="date" id="rbDateFrom" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>To date</label>
                        <input type="date" id="rbDateTo" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Status filter</label>
                        <input type="text" id="rbStatus" class="form-control" placeholder="e.g. overdue">
                        <small class="form-text text-muted">Source-specific: overdue, issued, partial, …</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Instructions (printed at the top of the document)</label>
                    <textarea id="rbInstructions" class="form-control" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Columns</h5></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Label</th><th>Type</th><th>Width</th><th></th></tr></thead>
                        <tbody id="rbColBody"></tbody>
                    </table>
                </div>
                <div class="form-row align-items-end">
                    <div class="form-group col-8">
                        <label>Add column</label>
                        <select id="rbAddCol" class="form-control"></select>
                    </div>
                    <div class="form-group col-4">
                        <button type="button" id="rbAddBtn" class="btn btn-light btn-block">Add</button>
                    </div>
                </div>
                <div id="rbTotalsWrap" class="form-group">
                    <label>Totals (numeric columns only)</label>
                    <div id="rbTotalsBody"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Sort by</label>
                        <select id="rbSortKey" class="form-control"></select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Direction</label>
                        <select id="rbSortDir" class="form-control">
                            <option value="asc">Ascending</option>
                            <option value="desc">Descending</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Layout &amp; options</h5></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Orientation</label>
                        <select id="rbOrientation" class="form-control">
                            <option value="portrait">Portrait (A4)</option>
                            <option value="landscape">Landscape (A4)</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Side margin (mm)</label>
                        <input type="number" id="rbMargin" class="form-control" min="5" max="25" value="10">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Font size</label>
                        <input type="number" id="rbFontSize" class="form-control" min="8" max="14" value="11">
                    </div>
                </div>
                <div class="form-group">
                    <label>Blank rows (manual writing space)</label>
                    <input type="number" id="rbBlankRows" class="form-control" min="0" max="200" value="0">
                </div>
                <div class="form-check"><input type="checkbox" id="rbShowHeader" class="form-check-input" checked> <label class="form-check-label" for="rbShowHeader">Include school header</label></div>
                <div class="form-check"><input type="checkbox" id="rbShowMeta" class="form-check-input" checked> <label class="form-check-label" for="rbShowMeta">Include class / subject details</label></div>
                <div class="form-check"><input type="checkbox" id="rbShowFooter" class="form-check-input" checked> <label class="form-check-label" for="rbShowFooter">Include generation date + page numbers</label></div>
                <div class="form-check"><input type="checkbox" id="rbShowSignatures" class="form-check-input"> <label class="form-check-label" for="rbShowSignatures">Include signature lines</label></div>
                <hr>
                <div class="form-group">
                    <button type="button" id="rbPreviewBtn" class="btn btn-light mr-2">Preview</button>
                    <button type="button" id="rbPdfBtn" class="btn btn-primary">Generate PDF</button>
                </div>
                <div class="form-row align-items-end">
                    <div class="form-group col-8">
                        <label>Save as my template</label>
                        <input type="text" id="rbTemplateName" class="form-control" maxlength="80" placeholder="Template name">
                    </div>
                    <div class="form-group col-4">
                        <button type="button" id="rbSaveBtn" class="btn btn-light btn-block">Save Template</button>
                    </div>
                </div>
            </div>
        </div>

        @if($templates->count())
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">My Custom Templates</h5></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    @foreach($templates as $template)
                    <tr>
                        <td>{{ $template->name }}</td>
                        <td class="text-right" style="white-space: nowrap;">
                            <button type="button" class="btn btn-sm btn-light rb-load-template" data-template-id="{{ $template->id }}">Load</button>
                            <form method="POST" action="{{ route('reports.templates.duplicate', [$template->id]) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light">Duplicate</button>
                            </form>
                            <form method="POST" action="{{ route('reports.templates.destroy', [$template->id]) }}" style="display:inline;" onsubmit="return confirm('Delete this template?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-light">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </table>
            </div>
        </div>
        @endif
    </div>

    <div class="col-lg-7 rb-preview">
        <div class="card rb-preview-card">
            <div class="card-header"><h5 class="card-title mb-0">Live preview</h5></div>
            <div class="card-body">
                <p class="text-muted">Preview shows the real PDF — the exact document the download produces, with true pagination.</p>
                {{-- Live preview states (builder redesign 2026-10-29):
                     status line (ready / draft problem / error),
                     loading indicator while the PDF loads, then the
                     frame itself. The frame starts blank on purpose:
                     the default draft has no title yet, so there is
                     nothing valid to preview until one is typed. --}}
                <p id="rbPreviewStatus" class="rb-status" role="status">Enter a report title to start the live preview.</p>
                <p id="rbPreviewLoading" class="rb-loading" style="display:none;">Updating preview…</p>
                <div class="rb-preview-wrap">
                    <iframe id="previewFrame" name="previewFrame" src="about:blank"></iframe>
                </div>
                <p class="text-muted mt-2 mb-0">The preview updates automatically as you change settings. If there are no rows for the selected filters, the preview document itself says so — no sample records are ever shown.</p>
            </div>
        </div>
    </div>
</div>

<form id="rbPreviewForm" method="POST" action="{{ route('reports.preview') }}" target="previewFrame" style="display:none;">
    @csrf
    <input type="hidden" name="preview_frame" value="1">
    <input type="hidden" name="config" id="rbPreviewConfig">
</form>
<form id="rbPdfForm" method="POST" action="{{ route('reports.pdf') }}" style="display:none;">
    @csrf
    <input type="hidden" name="config" id="rbPdfConfig">
</form>
<form id="rbSaveForm" method="POST" action="{{ route('reports.templates.store') }}" style="display:none;">
    @csrf
    <input type="hidden" name="config" id="rbSaveConfig">
    <input type="hidden" name="name" id="rbSaveName">
</form>

<script>
(function () {
    var PRESETS = @json($presets);
    var SOURCES = @json($sources);
    var SECTIONS = @json($sectionsMap);
    var SUBJECTS = @json($subjectsMap);
    var TEMPLATES = @json($templatesMap);
    var COL_TYPES = ['text', 'number', 'currency', 'date', 'checkbox', 'box', 'dropdown', 'yes_no', 'signature', 'remarks', 'blank'];

    var columns = [];
    var totals = {};
    var sortKey = '';
    var sortDir = 'asc';

    /* Live preview (builder redesign 2026-10-29): the preview
       auto-updates from the SAME config as the download, debounced
       600ms so typing does not hammer the server with a PDF render
       per keystroke. Incomplete drafts never reach the server: the
       panel above the frame explains what is missing instead of
       showing a server error for a draft the user is still typing.
       The manual Preview button still works as a fallback. */
    var previewTimer = null;
    var previewRequested = false;
    var previewLoadGuard = null;
    function previewDraftProblem(cfg) {
        if (!cfg.title.trim()) { return 'Enter a report title to start the live preview.'; }
        if (!cfg.columns.length) { return 'Add at least one column to start the live preview.'; }
        for (var i = 0; i < cfg.columns.length; i++) {
            if (!cfg.columns[i].label.trim()) { return 'Every column needs a label before previewing.'; }
        }
        if (cfg.source === 'marks' && (!cfg.filters.exam_id || !cfg.filters.class_id || !cfg.filters.subject_id)) {
            return 'Marks reports require an exam, a class and a subject.';
        }
        return null;
    }
    function setPreviewStatus(text, isError) {
        var s = el('rbPreviewStatus');
        s.textContent = text;
        s.className = isError ? 'rb-status rb-status-error' : 'rb-status';
    }
    function setPreviewLoading(on) {
        el('rbPreviewLoading').style.display = on ? '' : 'none';
    }
    function schedulePreview() {
        if (previewTimer) { clearTimeout(previewTimer); }
        previewTimer = setTimeout(runPreview, 600);
    }
    function runPreview() {
        previewTimer = null;
        var cfg = collectConfig();
        var problem = previewDraftProblem(cfg);
        if (problem) { setPreviewLoading(false); setPreviewStatus(problem, false); return; }
        previewRequested = true;
        setPreviewLoading(true);
        setPreviewStatus('Updating preview…', false);
        el('rbPreviewConfig').value = JSON.stringify(cfg);
        el('rbPreviewForm').submit();
        /* Fallback: if the PDF viewer's load event never fires, the
           loading line must not stick forever. 60s is generous for
           a whole-class sheet; a real load clears this guard. */
        if (previewLoadGuard) { clearTimeout(previewLoadGuard); }
        previewLoadGuard = setTimeout(function () {
            if (!previewRequested) { return; }
            previewRequested = false;
            setPreviewLoading(false);
            setPreviewStatus('Live preview — the same document the download produces.', false);
        }, 60000);
    }

    function el(id) { return document.getElementById(id); }
    function isCustomKey(k) { return /^custom_\d+$/.test(k); }
    function nextCustomKey() {
        var n = 1;
        var used = {};
        columns.forEach(function (c) { used[c.key] = true; });
        while (used['custom_' + n]) { n++; }
        return 'custom_' + n;
    }
    function sourceCols() {
        var s = SOURCES[el('rbSource').value];
        return s ? s.columns : [];
    }
    function refreshAddSelect() {
        var sel = el('rbAddCol');
        sel.innerHTML = '';
        var have = {};
        columns.forEach(function (c) { have[c.key] = true; });
        sourceCols().forEach(function (c) {
            if (!have[c.key]) {
                var o = document.createElement('option');
                o.value = c.key;
                o.textContent = c.label + ' (' + c.type + ')';
                sel.appendChild(o);
            }
        });
        var oc = document.createElement('option');
        oc.value = '__custom';
        oc.textContent = 'Custom column…';
        sel.appendChild(oc);
    }
    function renderColumns() {
        var body = el('rbColBody');
        body.innerHTML = '';
        columns.forEach(function (col, idx) {
            var tr = document.createElement('tr');
            tr.className = 'rb-col-row';
            var tdLabel = document.createElement('td');
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'form-control form-control-sm';
            inp.value = col.label;
            inp.maxLength = 60;
            inp.addEventListener('input', function () { col.label = inp.value; renderTotals(); renderSort(); });
            tdLabel.appendChild(inp);
            var tdType = document.createElement('td');
            if (isCustomKey(col.key)) {
                var sel = document.createElement('select');
                sel.className = 'form-control form-control-sm';
                COL_TYPES.forEach(function (t) {
                    var o = document.createElement('option');
                    o.value = t;
                    o.textContent = t;
                    if (t === col.type) { o.selected = true; }
                    sel.appendChild(o);
                });
                sel.addEventListener('change', function () { col.type = sel.value; renderTotals(); });
                tdType.appendChild(sel);
            } else {
                tdType.textContent = col.type;
            }
            var tdWidth = document.createElement('td');
            var winp = document.createElement('input');
            winp.type = 'number';
            winp.className = 'form-control form-control-sm';
            winp.min = 1;
            winp.max = 100;
            winp.value = col.width;
            winp.addEventListener('input', function () { col.width = parseInt(winp.value, 10) || 10; });
            tdWidth.appendChild(winp);
            var tdAct = document.createElement('td');
            tdAct.style.whiteSpace = 'nowrap';
            var up = document.createElement('button');
            up.type = 'button';
            up.className = 'btn btn-sm btn-light';
            up.textContent = '↑';
            up.addEventListener('click', function () {
                if (idx > 0) {
                    var tmp = columns[idx - 1];
                    columns[idx - 1] = columns[idx];
                    columns[idx] = tmp;
                    renderColumns();
                }
            });
            var dn = document.createElement('button');
            dn.type = 'button';
            dn.className = 'btn btn-sm btn-light';
            dn.textContent = '↓';
            dn.addEventListener('click', function () {
                if (idx < columns.length - 1) {
                    var tmp = columns[idx + 1];
                    columns[idx + 1] = columns[idx];
                    columns[idx] = tmp;
                    renderColumns();
                }
            });
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn btn-sm btn-light';
            rm.textContent = '×';
            rm.addEventListener('click', function () {
                columns.splice(idx, 1);
                renderColumns();
            });
            tdAct.appendChild(up);
            tdAct.appendChild(dn);
            tdAct.appendChild(rm);
            tr.appendChild(tdLabel);
            tr.appendChild(tdType);
            tr.appendChild(tdWidth);
            tr.appendChild(tdAct);
            body.appendChild(tr);
        });
        refreshAddSelect();
        renderTotals();
        renderSort();
        /* Column add / remove / reorder / preset / template / source
           changes all flow through here, so the live preview follows
           them without a separate hook per button. */
        schedulePreview();
    }
    function renderTotals() {
        var wrap = el('rbTotalsBody');
        wrap.innerHTML = '';
        var any = false;
        columns.forEach(function (col) {
            if (col.type !== 'number' && col.type !== 'currency') { return; }
            any = true;
            var row = document.createElement('div');
            row.className = 'form-row align-items-center mb-1';
            var lab = document.createElement('div');
            lab.className = 'col-6';
            lab.textContent = col.label;
            var selWrap = document.createElement('div');
            selWrap.className = 'col-6';
            var sel = document.createElement('select');
            sel.className = 'form-control form-control-sm';
            [['', 'No total'], ['sum', 'Sum'], ['count', 'Count'], ['avg', 'Average']].forEach(function (opt) {
                var o = document.createElement('option');
                o.value = opt[0];
                o.textContent = opt[1];
                if ((totals[col.key] || '') === opt[0]) { o.selected = true; }
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                if (sel.value) { totals[col.key] = sel.value; } else { delete totals[col.key]; }
            });
            selWrap.appendChild(sel);
            row.appendChild(lab);
            row.appendChild(selWrap);
            wrap.appendChild(row);
        });
        if (!any) { wrap.textContent = 'Add a numeric column to enable totals.'; }
    }
    function renderSort() {
        var sel = el('rbSortKey');
        var prev = sortKey;
        sel.innerHTML = '';
        var none = document.createElement('option');
        none.value = '';
        none.textContent = 'Source order';
        sel.appendChild(none);
        columns.forEach(function (col) {
            var o = document.createElement('option');
            o.value = col.key;
            o.textContent = col.label;
            if (col.key === prev) { o.selected = true; }
            sel.appendChild(o);
        });
        sortKey = sel.value;
        sel.addEventListener('change', function () { sortKey = sel.value; });
    }
    function collectConfig() {
        var filters = {};
        var classId = el('rbClass').value;
        var sectionId = el('rbSection').value;
        var subjectId = el('rbSubject').value;
        var examId = el('rbExam').value;
        if (classId) { filters.class_id = parseInt(classId, 10); }
        if (sectionId) { filters.section_id = parseInt(sectionId, 10); }
        if (subjectId) { filters.subject_id = parseInt(subjectId, 10); }
        if (examId) { filters.exam_id = parseInt(examId, 10); }
        if (el('rbYear').value) { filters.year = el('rbYear').value; }
        if (el('rbDateFrom').value) { filters.date_from = el('rbDateFrom').value; }
        if (el('rbDateTo').value) { filters.date_to = el('rbDateTo').value; }
        if (el('rbStatus').value) { filters.status = el('rbStatus').value; }
        var options = {
            orientation: el('rbOrientation').value,
            margin_mm: parseInt(el('rbMargin').value, 10) || 10,
            font_size: parseInt(el('rbFontSize').value, 10) || 11,
            show_header: el('rbShowHeader').checked,
            show_meta: el('rbShowMeta').checked,
            show_footer: el('rbShowFooter').checked,
            show_signatures: el('rbShowSignatures').checked,
            blank_rows: parseInt(el('rbBlankRows').value, 10) || 0,
            totals: totals,
            sort: sortKey ? { key: sortKey, dir: el('rbSortDir').value } : null
        };
        return {
            title: el('rbTitle').value,
            subtitle: el('rbSubtitle').value,
            source: el('rbSource').value,
            filters: filters,
            columns: columns,
            options: options,
            instructions: el('rbInstructions').value
        };
    }
    function validForSubmit(cfg) {
        if (!cfg.title.trim()) { alert('Please enter a report title.'); return false; }
        if (!cfg.columns.length) { alert('Please add at least one column.'); return false; }
        for (var i = 0; i < cfg.columns.length; i++) {
            if (!cfg.columns[i].label.trim()) { alert('Every column needs a label.'); return false; }
        }
        if (cfg.source === 'marks' && (!cfg.filters.exam_id || !cfg.filters.class_id || !cfg.filters.subject_id)) {
            alert('Marks reports require an exam, a class and a subject.');
            return false;
        }
        return true;
    }
    function applySourceColumns(sourceKey) {
        columns = [];
        sourceCols().forEach(function (c) {
            columns.push({ key: c.key, label: c.label, type: c.type, width: 12 });
        });
        totals = {};
        sortKey = '';
        renderColumns();
    }
    function applyConfig(cfg) {
        if (!cfg) { return; }
        if (cfg.source && SOURCES[cfg.source]) { el('rbSource').value = cfg.source; }
        el('rbTitle').value = cfg.title || '';
        el('rbSubtitle').value = cfg.subtitle || '';
        el('rbInstructions').value = cfg.instructions || '';
        var f = cfg.filters || {};
        el('rbClass').value = f.class_id || '';
        filterDependent();
        el('rbSection').value = f.section_id || '';
        el('rbSubject').value = f.subject_id || '';
        el('rbExam').value = f.exam_id || '';
        if (f.year) { el('rbYear').value = f.year; }
        el('rbDateFrom').value = f.date_from || '';
        el('rbDateTo').value = f.date_to || '';
        el('rbStatus').value = f.status || '';
        columns = (cfg.columns || []).map(function (c) {
            return { key: c.key, label: c.label, type: c.type, width: c.width || 12 };
        });
        var o = cfg.options || {};
        el('rbOrientation').value = o.orientation || 'portrait';
        el('rbMargin').value = o.margin_mm || 10;
        el('rbFontSize').value = o.font_size || 11;
        el('rbBlankRows').value = o.blank_rows || 0;
        el('rbShowHeader').checked = o.show_header !== false;
        el('rbShowMeta').checked = o.show_meta !== false;
        el('rbShowFooter').checked = o.show_footer !== false;
        el('rbShowSignatures').checked = !!o.show_signatures;
        totals = o.totals || {};
        sortKey = (o.sort && o.sort.key) || '';
        el('rbSortDir').value = (o.sort && o.sort.dir) || 'asc';
        updateMarksHint();
        renderColumns();
    }
    function filterDependent() {
        var classId = el('rbClass').value;
        [el('rbSection'), el('rbSubject')].forEach(function (sel) {
            for (var i = 0; i < sel.options.length; i++) {
                var opt = sel.options[i];
                var oc = opt.getAttribute('data-class');
                opt.style.display = (!classId || !oc || oc === classId) ? '' : 'none';
            }
        });
    }
    function updateMarksHint() {
        el('rbMarksHint').style.display = el('rbSource').value === 'marks' ? '' : 'none';
    }

    el('rbSource').addEventListener('change', function () {
        updateMarksHint();
        applySourceColumns(el('rbSource').value);
    });
    el('rbClass').addEventListener('change', function () {
        filterDependent();
        el('rbSection').value = '';
        el('rbSubject').value = '';
    });
    el('rbPreset').addEventListener('change', function () {
        var key = el('rbPreset').value;
        if (!key) { return; }
        for (var i = 0; i < PRESETS.length; i++) {
            if (PRESETS[i].key === key) { applyConfig(PRESETS[i].config); return; }
        }
    });
    el('rbAddBtn').addEventListener('click', function () {
        var val = el('rbAddCol').value;
        if (val === '__custom') {
            var key = nextCustomKey();
            columns.push({ key: key, label: 'Field ' + key.replace('custom_', ''), type: 'text', width: 15 });
        } else {
            var found = null;
            sourceCols().forEach(function (c) { if (c.key === val) { found = c; } });
            if (found) { columns.push({ key: found.key, label: found.label, type: found.type, width: 12 }); }
        }
        renderColumns();
    });
    el('rbPreviewBtn').addEventListener('click', function () {
        var cfg = collectConfig();
        if (!validForSubmit(cfg)) { return; }
        if (previewTimer) { clearTimeout(previewTimer); previewTimer = null; }
        previewRequested = true;
        setPreviewLoading(true);
        setPreviewStatus('Updating preview…', false);
        el('rbPreviewConfig').value = JSON.stringify(cfg);
        el('rbPreviewForm').submit();
        if (previewLoadGuard) { clearTimeout(previewLoadGuard); }
        previewLoadGuard = setTimeout(function () {
            if (!previewRequested) { return; }
            previewRequested = false;
            setPreviewLoading(false);
            setPreviewStatus('Live preview — the same document the download produces.', false);
        }, 60000);
    });
    /* Auto-update on any settings change (title, filters, layout
       options, column edits). The template-name box is excluded:
       typing a name to save must not refresh the preview. */
    el('rbSettings').addEventListener('input', function (ev) {
        if (ev.target && ev.target.id === 'rbTemplateName') { return; }
        schedulePreview();
    });
    el('rbSettings').addEventListener('change', function (ev) {
        if (ev.target && ev.target.id === 'rbTemplateName') { return; }
        schedulePreview();
    });
    /* Loading / error states for the frame. The initial about:blank
       load is ignored (previewRequested is false until a real
       preview is submitted). A PDF load leaves contentDocument
       unreadable, so it reports live; the server's error document
       carries the data-rb-preview-error marker and reports error. */
    el('previewFrame').addEventListener('load', function () {
        if (!previewRequested) { return; }
        previewRequested = false;
        if (previewLoadGuard) { clearTimeout(previewLoadGuard); previewLoadGuard = null; }
        setPreviewLoading(false);
        var isError = false;
        try {
            var fdoc = el('previewFrame').contentDocument;
            if (fdoc && fdoc.body && fdoc.body.getAttribute('data-rb-preview-error')) { isError = true; }
        } catch (e) { isError = false; }
        if (isError) {
            setPreviewStatus('Preview could not be generated — see the message in the preview panel, then fix the settings.', true);
        } else {
            setPreviewStatus('Live preview — the same document the download produces.', false);
        }
    });
    el('rbPdfBtn').addEventListener('click', function () {
        var cfg = collectConfig();
        if (!validForSubmit(cfg)) { return; }
        el('rbPdfConfig').value = JSON.stringify(cfg);
        el('rbPdfForm').submit();
    });
    el('rbSaveBtn').addEventListener('click', function () {
        var cfg = collectConfig();
        if (!validForSubmit(cfg)) { return; }
        var name = el('rbTemplateName').value.trim();
        if (!name) { alert('Please enter a template name.'); return; }
        el('rbSaveConfig').value = JSON.stringify(cfg);
        el('rbSaveName').value = name;
        el('rbSaveForm').submit();
    });
    document.querySelectorAll('.rb-load-template').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cfg = TEMPLATES[btn.getAttribute('data-template-id')];
            if (cfg) { applyConfig(cfg); }
        });
    });

    updateMarksHint();
    applySourceColumns(el('rbSource').value);
})();
</script>
@endsection
