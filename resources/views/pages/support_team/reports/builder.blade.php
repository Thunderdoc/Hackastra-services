@extends('layouts.master')
@section('page_title', 'Reports & PDF Builder')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Reports &amp; PDF Builder
@endsection
@section('page_subtitle', 'Configure on the left, edit the actual document on the right — then print it or download the PDF.')
@section('content')
<style>
    /* Builder rb5 (owner, 2026-10-30 — full replacement ordered):
       ONE screen, TWO panels. LEFT (43%) configures the report;
       RIGHT (57%) is the live editable document itself — the same
       print view the PDF download uses, rendered as HTML with the
       title / subtitle / footer line editable in place. No wizard,
       no tabs, no separate preview screen, no Preview button.
       Same engine, sources, routes and config contract as rb4;
       only the page and the preview transport changed. Scoped
       styles (rb5-) — no shared component is touched. */
    .rb5-switcher { display: none; gap: 8px; margin-bottom: 10px; }
    .rb5-switcher .btn { flex: 1; }
    .rb5-wrap { display: flex; gap: 14px; align-items: stretch; }
    .rb5-left { flex: 0 0 43%; max-width: 43%; min-width: 0; overflow-y: auto; padding-right: 2px; }
    .rb5-right { flex: 1 1 57%; min-width: 0; display: flex; flex-direction: column; }
    .rb5-left .card { background: #fff; border: 1px solid #c9d6e4; border-radius: 12px; box-shadow: 0 1px 3px rgba(16, 38, 59, .07); margin-bottom: 14px; }
    .rb5-left .card-header { background: #f7fafd; border-bottom: 1px solid #dfe8f3; border-radius: 12px 12px 0 0; padding: 10px 16px; }
    .rb5-left .card-title { font-size: 15px; font-weight: 700; color: #10263b; }
    .rb5-left .card-body { padding: 14px 16px; }
    .rb5-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; background: #fff; border: 1px solid #c9d6e4; border-radius: 10px; padding: 7px 10px; margin-bottom: 8px; }
    .rb5-toolbar .form-control { width: auto; height: 32px; padding: 2px 6px; font-size: 13px; }
    .rb5-tb-btn { border: 1px solid #dfe8f3; background: #f7fafd; color: #10263b; border-radius: 7px; min-width: 32px; height: 32px; font-size: 13px; cursor: pointer; }
    .rb5-tb-btn:hover { background: #e8f0fc; }
    .rb5-tb-sep { width: 1px; height: 22px; background: #dfe8f3; margin: 0 2px; }
    .rb5-statusbar { display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: baseline; padding: 0 2px 8px; min-height: 22px; }
    .rb2-status { font-size: 13px; color: #4a5a6e; }
    .rb2-status-error { color: #b42318; font-weight: 600; }
    .rb2-summary { font-size: 13px; font-weight: 600; color: #10263b; }
    .rb2-loading { font-size: 12.5px; color: #1765f5; }
    .rb5-workspace { flex: 1; min-height: 0; background: #cfd9e4; border: 1px solid #b9c7d6; border-radius: 12px; padding: 16px; overflow: auto; display: flex; justify-content: center; }
    #previewFrame { width: 100%; max-width: 980px; height: 100%; min-height: 520px; border: none; background: #fff; box-shadow: 0 4px 22px rgba(16, 38, 59, .30); display: block; }
    .rb5-col-item { border: 1px solid #dfe8f3; border-radius: 9px; padding: 8px 10px; margin-bottom: 8px; background: #fbfcfe; }
    .rb5-col-item.rb5-drag-over { border-color: #1765f5; box-shadow: 0 0 0 1px #1765f5 inset; }
    .rb2-col-line1 { display: flex; gap: 6px; align-items: center; }
    .rb2-col-line1 input[type="text"] { flex: 1; min-width: 0; }
    .rb5-drag { cursor: grab; color: #8496ad; font-size: 15px; padding: 0 2px; user-select: none; }
    .rb2-col-show { display: flex; gap: 4px; align-items: center; font-size: 12.5px; margin: 0; white-space: nowrap; }
    .rb2-col-line2 { display: flex; gap: 10px; margin-top: 7px; flex-wrap: wrap; }
    .rb2-col-field { flex: 1; min-width: 86px; }
    .rb2-col-field label { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #718096; margin-bottom: 2px; display: block; }
    .rb2-col-static { font-size: 13px; padding: 5px 0; color: #4a5a6e; }
    .rb5-details summary { cursor: pointer; font-weight: 700; color: #10263b; font-size: 15px; padding: 10px 16px; }
    .rb5-details { background: #fff; border: 1px solid #c9d6e4; border-radius: 12px; box-shadow: 0 1px 3px rgba(16, 38, 59, .07); margin-bottom: 14px; }
    .rb5-details .card-body { padding-top: 4px; }
    @media (min-width: 992px) {
        .rb5-wrap { height: calc(100vh - 168px); min-height: 600px; }
        .rb5-left { height: 100%; }
        .rb5-right { height: 100%; }
    }
    @media (max-width: 991px) {
        .rb5-switcher { display: flex; }
        .rb5-wrap { display: block; }
        .rb5-left { max-width: none; overflow: visible; }
        .rb5-right { margin-top: 4px; }
        .rb5-wrap .rb5-right { display: none; }
        .rb5-wrap.rb5-show-doc .rb5-right { display: flex; }
        .rb5-wrap.rb5-show-doc .rb5-left { display: none; }
        #previewFrame { min-height: 70vh; }
        .rb5-workspace { padding: 8px; }
    }
</style>

<div class="rb5-switcher">
    <button type="button" class="btn btn-primary" id="rb5SwConfig">Configure</button>
    <button type="button" class="btn btn-light" id="rb5SwDoc">Document</button>
</div>

<div class="rb5-wrap" id="rb5Wrap">
    {{-- ================= LEFT — CONFIGURE ================= --}}
    <div class="rb5-left" id="rbSettings">

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Report Template</h5>
                <button type="button" class="btn btn-sm btn-light" id="rbChangeTemplate">Change Template</button>
            </div>
            <div class="card-body">
                @if($presets)
                <div class="form-group">
                    <label>Ready template</label>
                    <select id="rbPreset" class="form-control">
                        <option value="">— Custom (blank) —</option>
                        @foreach($presets as $preset)
                            <option value="{{ $preset['key'] }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @else
                <select id="rbPreset" class="d-none" aria-hidden="true" tabindex="-1"><option value=""></option></select>
                @endif
                <div class="form-group mb-1">
                    <label>Data source *</label>
                    <select id="rbSource" class="form-control">
                        @foreach($sources as $srcKey => $srcDef)
                            <option value="{{ $srcKey }}">{{ $srcDef['label'] }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted" id="rbTemplateDesc">Changing the source resets the column list. Teachers see only their own classes and subjects.</small>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Report Details</h5></div>
            <div class="card-body">
                <div id="rbMarksHint" class="alert alert-info" style="display:none;">
                    Marks reports require an exam, a class and a subject. Component columns (Theory, Internal, Viva, …) are added automatically for configured marking schemes.
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Report title *</label>
                        <input type="text" id="rbTitle" class="form-control" maxlength="120" placeholder="e.g. Class Roster — Prep A">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Subtitle</label>
                        <input type="text" id="rbSubtitle" class="form-control" maxlength="200" placeholder="Optional line under the title">
                    </div>
                </div>
                <p class="text-muted mb-2" id="rbNoFilters" style="display:none;">This source has no filters — the report covers everything it holds.</p>
                <div class="form-row">
                    <div class="form-group col-md-6 rb2-filter" data-filter-group="class">
                        <label>Class</label>
                        <select id="rbClass" class="form-control">
                            <option value="">All classes</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}">{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-6 rb2-filter" data-filter-group="section">
                        <label>Section</label>
                        <select id="rbSection" class="form-control">
                            <option value="">All sections</option>
                            @foreach($sectionsMap as $sec)
                                <option value="{{ $sec['id'] }}" data-class="{{ $sec['my_class_id'] }}">{{ $sec['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6 rb2-filter" data-filter-group="subject">
                        <label>Subject</label>
                        <select id="rbSubject" class="form-control">
                            <option value="">All subjects</option>
                            @foreach($subjectsMap as $subj)
                                <option value="{{ $subj['id'] }}" data-class="{{ $subj['my_class_id'] }}">{{ $subj['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-6 rb2-filter" data-filter-group="exam">
                        <label>Exam</label>
                        <select id="rbExam" class="form-control">
                            <option value="">All exams</option>
                            @foreach($exams as $exam)
                                <option value="{{ $exam->id }}">{{ $exam->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row rb2-filter" data-filter-group="dates">
                    <div class="form-group col-md-6">
                        <label>From date</label>
                        <input type="date" id="rbDateFrom" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>To date</label>
                        <input type="date" id="rbDateTo" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6 rb2-filter" data-filter-group="status">
                        <label>Status</label>
                        <input type="text" id="rbStatus" class="form-control" placeholder="e.g. overdue">
                        <small class="form-text text-muted">Source-specific: overdue, issued, partial, …</small>
                    </div>
                    <div class="form-group col-md-6 rb2-filter" data-filter-group="session">
                        <label>Academic Year / Session</label>
                        <input type="text" id="rbYear" class="form-control" value="{{ $currentSession }}">
                    </div>
                </div>
                <div class="form-row mb-0">
                    <div class="form-group col-md-6 mb-0">
                        <label>Sort By</label>
                        <select id="rbSortKey" class="form-control"></select>
                    </div>
                    <div class="form-group col-md-6 mb-0">
                        <label>Sort Order</label>
                        <select id="rbSortDir" class="form-control">
                            <option value="asc">Ascending</option>
                            <option value="desc">Descending</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Columns <span class="text-muted" style="font-size:12px; font-weight:400;">(drag to reorder)</span></h5>
                <button type="button" class="btn btn-sm btn-light" id="rbRestoreCols">Restore defaults</button>
            </div>
            <div class="card-body">
                <div id="rbColBody"></div>
                <div class="form-row align-items-end">
                    <div class="form-group col-8">
                        <label>Add column</label>
                        <select id="rbAddCol" class="form-control"></select>
                    </div>
                    <div class="form-group col-4">
                        <button type="button" id="rbAddBtn" class="btn btn-primary btn-block">+ Add Column</button>
                    </div>
                </div>
                <div id="rbTotalsWrap" class="form-group mb-0">
                    <label>Totals (numeric columns only)</label>
                    <div id="rbTotalsBody"></div>
                </div>
            </div>
        </div>

        <details class="rb5-details">
            <summary>Header, Footer &amp; Page Settings</summary>
            <div class="card-body">
                <div class="form-check"><input type="checkbox" id="rbShowHeader" class="form-check-input" checked> <label class="form-check-label" for="rbShowHeader">Include school header (logo, name, contacts)</label></div>
                <div class="form-check"><input type="checkbox" id="rbShowMeta" class="form-check-input" checked> <label class="form-check-label" for="rbShowMeta">Include class / subject details line</label></div>
                <div class="form-check"><input type="checkbox" id="rbShowFooter" class="form-check-input" checked> <label class="form-check-label" for="rbShowFooter">Include footer + page numbers</label></div>
                <div class="form-check mb-2"><input type="checkbox" id="rbShowSignatures" class="form-check-input"> <label class="form-check-label" for="rbShowSignatures">Include signature lines</label></div>
                <div class="form-group">
                    <label>Footer text</label>
                    <input type="text" id="rbFooterText" class="form-control" maxlength="200" placeholder="Printed at the foot of every page">
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Margins (mm)</label>
                        <input type="number" id="rbMargin" class="form-control" min="5" max="25" value="10">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Font size</label>
                        <input type="number" id="rbFontSize" class="form-control" min="8" max="14" value="11">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Blank rows</label>
                        <input type="number" id="rbBlankRows" class="form-control" min="0" max="200" value="0">
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label>Instructions (printed at the top of the document)</label>
                    <textarea id="rbInstructions" class="form-control" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </details>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Save &amp; Export</h5></div>
            <div class="card-body">
                <button type="button" id="rbPdfBtn" class="btn btn-primary btn-block btn-lg rb-generate-btn"><i class="icon-file-pdf mr-2"></i> Download PDF</button>
                <div class="form-row align-items-end mt-3">
                    <div class="form-group col-8 mb-0">
                        <label>Save as my template</label>
                        <input type="text" id="rbTemplateName" class="form-control" maxlength="80" placeholder="Template name">
                    </div>
                    <div class="form-group col-4 mb-0">
                        <button type="button" id="rbSaveBtn" class="btn btn-light btn-block">Save Template</button>
                    </div>
                </div>
            </div>
        </div>

        @if($templates->count())
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">My Saved Templates</h5></div>
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

    {{-- ================= RIGHT — LIVE EDITABLE DOCUMENT ================= --}}
    <div class="rb5-right">
        <div class="rb5-toolbar" id="rb5Toolbar">
            <select id="rbTbFont" class="form-control" title="Document font">
                <option value="">Default font</option>
                <option value="'DejaVu Sans', Arial, sans-serif">DejaVu Sans</option>
                <option value="Arial, Helvetica, sans-serif">Arial</option>
                <option value="Georgia, 'Times New Roman', serif">Georgia</option>
                <option value="'Times New Roman', Times, serif">Times New Roman</option>
                <option value="'Courier New', monospace">Courier New</option>
            </select>
            <select id="rbTbFontSize" class="form-control" title="Font size">
                <option value="9">9</option>
                <option value="10">10</option>
                <option value="11" selected>11</option>
                <option value="12">12</option>
                <option value="13">13</option>
                <option value="14">14</option>
            </select>
            <span class="rb5-tb-sep"></span>
            <button type="button" class="rb5-tb-btn" id="rbTbBold" title="Bold"><strong>B</strong></button>
            <button type="button" class="rb5-tb-btn" id="rbTbItalic" title="Italic"><em>I</em></button>
            <button type="button" class="rb5-tb-btn" id="rbTbUnderline" title="Underline"><u>U</u></button>
            <input type="color" id="rbTbColor" value="#10263b" title="Text colour" style="width:34px; height:32px; padding:1px; border:1px solid #dfe8f3; border-radius:7px; background:#fff;">
            <span class="rb5-tb-sep"></span>
            <button type="button" class="rb5-tb-btn" id="rbTbAlignLeft" title="Align left">⯇</button>
            <button type="button" class="rb5-tb-btn" id="rbTbAlignCenter" title="Align centre">≡</button>
            <button type="button" class="rb5-tb-btn" id="rbTbAlignRight" title="Align right">⯈</button>
            <span class="rb5-tb-sep"></span>
            <select id="rbPaper" class="form-control" title="Paper size">
                <option value="a4">A4</option>
                <option value="letter">Letter</option>
            </select>
            <select id="rbOrientation" class="form-control" title="Orientation">
                <option value="portrait">Portrait</option>
                <option value="landscape">Landscape</option>
            </select>
            <select id="rbTbZoom" class="form-control" title="Zoom">
                <option value="0.6">60%</option>
                <option value="0.75">75%</option>
                <option value="0.9">90%</option>
                <option value="1" selected>100%</option>
                <option value="1.25">125%</option>
                <option value="1.5">150%</option>
            </select>
            <span class="rb5-tb-sep"></span>
            <button type="button" class="rb5-tb-btn" id="rbTbUndo" title="Undo">↺</button>
            <button type="button" class="rb5-tb-btn" id="rbTbRedo" title="Redo">↻</button>
            <button type="button" class="btn btn-primary btn-sm" id="rbTbPrint" style="height:32px;"><i class="icon-printer mr-1"></i> Print</button>
        </div>
        <div class="rb5-statusbar">
            <p id="rbPreviewStatus" class="rb2-status mb-0" role="status">Loading the document…</p>
            <p id="rbSummary" class="rb2-summary mb-0"></p>
            <p id="rbPreviewLoading" class="rb2-loading mb-0" style="display:none;">Updating document…</p>
        </div>
        <div class="rb5-workspace">
            <iframe id="previewFrame" name="previewFrame" src="about:blank" title="Report document"></iframe>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:12.5px;">The document is live: click its title, subtitle, footer line or any column heading to edit it in place. Data rows come straight from the school records — they are never typed by hand. Print prints only this document; Download PDF produces the identical file.</p>
    </div>
</div>

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
    var SCHOOL_NAME = @json(\App\Helpers\Qs::getSetting('system_name') ?: 'Hackstra Academy');
    var PREVIEW_HTML_URL = @json(route('reports.preview_html'));
    var COL_TYPES = ['text', 'number', 'currency', 'date', 'checkbox', 'box', 'dropdown', 'yes_no', 'signature', 'remarks', 'blank'];

    /* Which filter groups each source actually reads (grounded in
       ReportSources' row builders) — the Details card shows exactly
       these groups and hides the rest. */
    var FILTER_MAP = {
        students: ['class', 'section'],
        marks: ['class', 'section', 'subject', 'exam'],
        attendance: ['class', 'section', 'subject', 'dates'],
        assignments: ['class', 'section', 'subject', 'dates'],
        fees: ['class', 'section', 'dates', 'session'],
        defaulters: ['class', 'section', 'status', 'session'],
        fines: ['status'],
        books: ['class'],
        issues: ['status'],
        staff: [],
        admissions: ['class', 'status', 'session'],
        certificates: ['status'],
        circulars: ['status'],
        classes: [],
        timetables: ['class', 'section'],
        fee_summary: ['class', 'session'],
        attendance_summary: ['class', 'subject', 'dates'],
        results_summary: ['class', 'exam'],
        library_summary: [],
        platform: [],
        blank: []
    };
    function el(id) { return document.getElementById(id); }
    function applyFilterVisibility() {
        var groups = FILTER_MAP[el('rbSource').value] || [];
        document.querySelectorAll('.rb2-filter').forEach(function (node) {
            node.style.display = groups.indexOf(node.getAttribute('data-filter-group')) !== -1 ? '' : 'none';
        });
        el('rbNoFilters').style.display = groups.length ? 'none' : '';
    }
    function refreshTemplateDesc() {
        var s = SOURCES[el('rbSource').value];
        var desc = el('rbTemplateDesc');
        if (s && desc) {
            desc.textContent = s.label + ' — ' + s.columns.length + ' fields available. Changing the source resets the column list. Teachers see only their own classes and subjects.';
        }
    }

    var columns = [];
    var totals = {};
    var sortKey = '';
    var sortDir = 'asc';

    var previewTimer = null;
    var previewRequested = false;
    var previewSeq = 0;
    var autoTitle = '';
    var titleTouched = false;
    var canvasFont = '';
    function sourceLabel() {
        var s = SOURCES[el('rbSource').value];
        return s ? s.label : 'Report';
    }
    function ensureAutoTitle() {
        var t = el('rbTitle');
        if (titleTouched && t.value.trim() && t.value !== autoTitle) { return; }
        t.value = sourceLabel();
        autoTitle = t.value;
    }
    function previewDraftProblem(cfg) {
        if (!cfg.title.trim()) { return 'Enter a report title to start the document.'; }
        if (!cfg.columns.length) { return 'Add at least one column to start the document.'; }
        for (var i = 0; i < cfg.columns.length; i++) {
            if (!cfg.columns[i].label.trim()) { return 'Every column needs a label before the document can update.'; }
        }
        if (cfg.source === 'marks' && (!cfg.filters.exam_id || !cfg.filters.class_id || !cfg.filters.subject_id)) {
            return 'Marks reports require an exam, a class and a subject.';
        }
        return null;
    }
    function setPreviewStatus(text, isError) {
        var s = el('rbPreviewStatus');
        s.textContent = text;
        s.className = isError ? 'rb2-status rb2-status-error' : 'rb2-status';
    }
    function setPreviewLoading(on) {
        el('rbPreviewLoading').style.display = on ? '' : 'none';
    }
    function updateSummary() {
        var out = el('rbSummary');
        if (!out) { return; }
        var clsSel = el('rbClass');
        var clsLabel = 'All classes';
        if (clsSel && clsSel.value) {
            for (var ci = 0; ci < clsSel.options.length; ci++) {
                if (clsSel.options[ci].value === clsSel.value) {
                    clsLabel = clsSel.options[ci].textContent.trim();
                    break;
                }
            }
        }
        var visible = 0;
        columns.forEach(function (c) { if (!c.hidden) { visible++; } });
        out.textContent = 'Report: ' + sourceLabel() + ' • Class: ' + clsLabel + ' • ' + visible + ' column' + (visible === 1 ? '' : 's');
    }
    function schedulePreview() {
        updateSummary();
        if (previewTimer) { clearTimeout(previewTimer); }
        setPreviewLoading(true);
        previewTimer = setTimeout(runPreview, 350);
    }
    /* rb5 transport: the document is HTML (the same print view),
       fetched and swapped into the frame. A sequence number
       discards stale responses, so a slow earlier render can never
       overwrite a newer one. Text edits inside the document stay
       local and sync into the config — they never trigger this. */
    function runPreview() {
        previewTimer = null;
        var cfg = collectConfig();
        var problem = previewDraftProblem(cfg);
        if (problem) { setPreviewLoading(false); setPreviewStatus(problem, false); return; }
        var seq = ++previewSeq;
        previewRequested = true;
        setPreviewLoading(true);
        setPreviewStatus('Updating document…', false);
        var fd = new FormData();
        var tokenInput = el('rbSaveForm').querySelector('input[name="_token"]');
        fd.append('_token', tokenInput ? tokenInput.value : '');
        fd.append('config', JSON.stringify(cfg));
        fetch(PREVIEW_HTML_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, text: t }; }); })
            .then(function (res) {
                if (seq !== previewSeq) { return; }
                el('previewFrame').srcdoc = res.text;
            })
            .catch(function () {
                if (seq !== previewSeq) { return; }
                previewRequested = false;
                setPreviewLoading(false);
                setPreviewStatus('Could not reach the server — the document will refresh when the connection returns.', true);
            });
    }

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
    /* The columns the document will actually carry, in order —
       the single cleaning rule collectConfig() sends and the
       canvas heading editor maps against. */
    function visibleColumns() {
        var srcDef = SOURCES[el('rbSource').value] || {};
        var srcKeys = {};
        (srcDef.columns || []).forEach(function (c) { srcKeys[c.key] = true; });
        return columns.filter(function (c) {
            if (c.hidden) { return false; }
            return srcKeys[c.key] || /^custom_\d+$/.test(c.key) || /^comp_\d+$/.test(c.key);
        });
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
            var item = document.createElement('div');
            item.className = 'rb5-col-item';
            item.draggable = true;
            item.setAttribute('data-idx', idx);
            item.addEventListener('dragstart', function (ev) {
                ev.dataTransfer.setData('text/plain', String(idx));
                ev.dataTransfer.effectAllowed = 'move';
            });
            item.addEventListener('dragover', function (ev) {
                ev.preventDefault();
                item.classList.add('rb5-drag-over');
            });
            item.addEventListener('dragleave', function () { item.classList.remove('rb5-drag-over'); });
            item.addEventListener('drop', function (ev) {
                ev.preventDefault();
                item.classList.remove('rb5-drag-over');
                var from = parseInt(ev.dataTransfer.getData('text/plain'), 10);
                if (isNaN(from) || from === idx) { return; }
                var moved = columns.splice(from, 1)[0];
                columns.splice(idx, 0, moved);
                renderColumns();
            });
            var line1 = document.createElement('div');
            line1.className = 'rb2-col-line1';
            var grip = document.createElement('span');
            grip.className = 'rb5-drag';
            grip.textContent = '⋮⋮';
            grip.title = 'Drag to reorder';
            line1.appendChild(grip);
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'form-control form-control-sm';
            inp.value = col.label;
            inp.maxLength = 60;
            inp.setAttribute('aria-label', 'Column label');
            inp.addEventListener('input', function () { col.label = inp.value; renderTotals(); renderSort(); schedulePreview(); });
            line1.appendChild(inp);
            var showWrap = document.createElement('label');
            showWrap.className = 'rb2-col-show';
            var chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.checked = !col.hidden;
            chk.addEventListener('change', function () { col.hidden = !chk.checked; schedulePreview(); });
            showWrap.appendChild(chk);
            showWrap.appendChild(document.createTextNode(' Show'));
            line1.appendChild(showWrap);
            var up = document.createElement('button');
            up.type = 'button';
            up.className = 'btn btn-sm btn-light';
            up.textContent = '↑';
            up.title = 'Move up';
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
            dn.title = 'Move down';
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
            rm.title = 'Remove column';
            rm.addEventListener('click', function () {
                columns.splice(idx, 1);
                renderColumns();
            });
            line1.appendChild(up);
            line1.appendChild(dn);
            line1.appendChild(rm);
            item.appendChild(line1);
            var line2 = document.createElement('div');
            line2.className = 'rb2-col-line2';
            var fType = document.createElement('div');
            fType.className = 'rb2-col-field';
            var lType = document.createElement('label');
            lType.textContent = 'Field';
            fType.appendChild(lType);
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
                sel.addEventListener('change', function () { col.type = sel.value; renderTotals(); schedulePreview(); });
                fType.appendChild(sel);
            } else {
                var tdiv = document.createElement('div');
                tdiv.className = 'rb2-col-static';
                tdiv.textContent = col.key + ' (' + col.type + ')';
                fType.appendChild(tdiv);
            }
            line2.appendChild(fType);
            var fWidth = document.createElement('div');
            fWidth.className = 'rb2-col-field';
            var lWidth = document.createElement('label');
            lWidth.textContent = 'Width';
            fWidth.appendChild(lWidth);
            var winp = document.createElement('input');
            winp.type = 'number';
            winp.className = 'form-control form-control-sm';
            winp.min = 1;
            winp.max = 100;
            winp.value = col.width;
            winp.addEventListener('input', function () { col.width = parseInt(winp.value, 10) || 10; schedulePreview(); });
            fWidth.appendChild(winp);
            line2.appendChild(fWidth);
            var fAlign = document.createElement('div');
            fAlign.className = 'rb2-col-field';
            var lAlign = document.createElement('label');
            lAlign.textContent = 'Align';
            fAlign.appendChild(lAlign);
            var asel = document.createElement('select');
            asel.className = 'form-control form-control-sm';
            [['', 'Auto'], ['left', 'Left'], ['center', 'Center'], ['right', 'Right']].forEach(function (pair) {
                var o = document.createElement('option');
                o.value = pair[0];
                o.textContent = pair[1];
                if ((col.align || '') === pair[0]) { o.selected = true; }
                asel.appendChild(o);
            });
            asel.addEventListener('change', function () { col.align = asel.value; schedulePreview(); });
            fAlign.appendChild(asel);
            line2.appendChild(fAlign);
            item.appendChild(line2);
            body.appendChild(item);
        });
        refreshAddSelect();
        renderTotals();
        renderSort();
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
                schedulePreview();
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
        sel.addEventListener('change', function () { sortKey = sel.value; schedulePreview(); });
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
            paper: el('rbPaper').value,
            footer_text: el('rbFooterText').value,
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
            columns: visibleColumns(),
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
        applyFilterVisibility();
        refreshTemplateDesc();
        columns = [];
        sourceCols().forEach(function (c) {
            columns.push({ key: c.key, label: c.label, type: c.type, width: 12, align: c.align || '', hidden: !!c.hidden });
        });
        totals = {};
        sortKey = '';
        renderColumns();
    }
    function applyConfig(cfg) {
        if (!cfg) { return; }
        if (cfg.source && SOURCES[cfg.source]) { el('rbSource').value = cfg.source; }
        applyFilterVisibility();
        refreshTemplateDesc();
        el('rbTitle').value = cfg.title || '';
        titleTouched = !!el('rbTitle').value.trim();
        autoTitle = '';
        if (!titleTouched) { ensureAutoTitle(); }
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
            return { key: c.key, label: c.label, type: c.type, width: c.width || 12, align: c.align || '', hidden: !!c.hidden };
        });
        var o = cfg.options || {};
        el('rbOrientation').value = o.orientation || 'portrait';
        el('rbPaper').value = o.paper || 'a4';
        el('rbFooterText').value = o.footer_text || SCHOOL_NAME;
        el('rbMargin').value = o.margin_mm || 10;
        el('rbFontSize').value = o.font_size || 11;
        el('rbTbFontSize').value = String(o.font_size || 11);
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

    /* ---- Editable document wiring (rb5) ----
       After every render, the frame's title / subtitle / footer
       line carry data-edit (rendered by the print view in canvas
       mode) and its column headings become editable. Typing syncs
       into the config inputs immediately; the document re-renders
       on blur ONLY when the text actually changed, so editing
       never fights the cursor. Data cells are never editable. */
    function wireCanvas() {
        var frame = el('previewFrame');
        var fdoc = frame.contentDocument;
        if (!fdoc || !fdoc.body) { return; }
        if (canvasFont) { fdoc.body.style.fontFamily = canvasFont; }
        fdoc.querySelectorAll('[data-edit]').forEach(function (node) {
            var kind = node.getAttribute('data-edit');
            var original = node.textContent;
            node.addEventListener('focus', function () { original = node.textContent; });
            node.addEventListener('input', function () {
                var text = node.textContent.trim();
                if (kind === 'title') { el('rbTitle').value = text; titleTouched = true; }
                if (kind === 'subtitle') { el('rbSubtitle').value = text; }
                if (kind === 'footer') { el('rbFooterText').value = text; }
            });
            node.addEventListener('blur', function () {
                if (node.textContent !== original) { schedulePreview(); }
            });
        });
        var heads = fdoc.querySelectorAll('table.items thead th');
        var vis = visibleColumns();
        heads.forEach(function (th, i) {
            if (!vis[i]) { return; }
            th.contentEditable = 'true';
            th.setAttribute('spellcheck', 'false');
            th.title = 'Click to rename this column';
            var original = th.textContent;
            th.addEventListener('focus', function () { original = th.textContent; });
            th.addEventListener('blur', function () {
                var text = th.textContent.trim();
                if (text && text !== original) {
                    vis[i].label = text;
                    renderColumns();
                }
            });
        });
    }
    function frameExec(cmd, val) {
        var fdoc = el('previewFrame').contentDocument;
        if (fdoc) { try { fdoc.execCommand(cmd, false, val || null); } catch (e) {} }
    }

    /* ---- events ---- */
    el('rbTitle').addEventListener('input', function () { titleTouched = true; });
    el('rbSource').addEventListener('change', function () {
        updateMarksHint();
        ensureAutoTitle();
        applySourceColumns(el('rbSource').value);
    });
    el('rbClass').addEventListener('change', function () {
        filterDependent();
        el('rbSection').value = '';
        el('rbSubject').value = '';
        schedulePreview();
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
    el('rbRestoreCols').addEventListener('click', function () {
        applySourceColumns(el('rbSource').value);
    });
    el('rbChangeTemplate').addEventListener('click', function () {
        el('rb5Wrap').classList.remove('rb5-show-doc');
        el('rbPreset').focus();
    });
    el('rbSettings').addEventListener('input', function (ev) {
        if (ev.target && ev.target.id === 'rbTemplateName') { return; }
        schedulePreview();
    });
    el('rbSettings').addEventListener('change', function (ev) {
        if (ev.target && ev.target.id === 'rbTemplateName') { return; }
        schedulePreview();
    });
    /* Toolbar controls live outside #rbSettings — wire them too. */
    el('rbPaper').addEventListener('change', schedulePreview);
    el('rbOrientation').addEventListener('change', schedulePreview);
    el('rbTbFontSize').addEventListener('change', function () {
        el('rbFontSize').value = el('rbTbFontSize').value;
        schedulePreview();
    });
    el('rbTbFont').addEventListener('change', function () {
        canvasFont = el('rbTbFont').value;
        var fdoc = el('previewFrame').contentDocument;
        if (fdoc && fdoc.body) { fdoc.body.style.fontFamily = canvasFont; }
    });
    el('rbTbBold').addEventListener('click', function () { frameExec('bold'); });
    el('rbTbItalic').addEventListener('click', function () { frameExec('italic'); });
    el('rbTbUnderline').addEventListener('click', function () { frameExec('underline'); });
    el('rbTbColor').addEventListener('input', function () { frameExec('foreColor', el('rbTbColor').value); });
    el('rbTbAlignLeft').addEventListener('click', function () { frameExec('justifyLeft'); });
    el('rbTbAlignCenter').addEventListener('click', function () { frameExec('justifyCenter'); });
    el('rbTbAlignRight').addEventListener('click', function () { frameExec('justifyRight'); });
    el('rbTbUndo').addEventListener('click', function () { frameExec('undo'); });
    el('rbTbRedo').addEventListener('click', function () { frameExec('redo'); });
    el('rbTbZoom').addEventListener('change', function () {
        el('previewFrame').style.zoom = el('rbTbZoom').value;
    });
    el('rbTbPrint').addEventListener('click', function () {
        var fw = el('previewFrame').contentWindow;
        if (fw) { fw.focus(); fw.print(); }
    });
    el('rb5SwConfig').addEventListener('click', function () {
        el('rb5Wrap').classList.remove('rb5-show-doc');
        el('rb5SwConfig').className = 'btn btn-primary';
        el('rb5SwDoc').className = 'btn btn-light';
    });
    el('rb5SwDoc').addEventListener('click', function () {
        el('rb5Wrap').classList.add('rb5-show-doc');
        el('rb5SwDoc').className = 'btn btn-primary';
        el('rb5SwConfig').className = 'btn btn-light';
    });
    el('previewFrame').addEventListener('load', function () {
        if (!previewRequested) { return; }
        previewRequested = false;
        setPreviewLoading(false);
        var isError = false;
        try {
            var fdoc = el('previewFrame').contentDocument;
            if (fdoc && fdoc.body && fdoc.body.getAttribute('data-rb-preview-error')) { isError = true; }
        } catch (e) { isError = false; }
        if (isError) {
            var tooMany = false;
            try { tooMany = !!(fdoc && fdoc.body && fdoc.body.textContent && fdoc.body.textContent.indexOf('Too many rows') !== -1); } catch (e) { tooMany = false; }
            if (tooMany) {
                setPreviewStatus('Too many rows for one document — pick a class or section on the left and the document will appear here.', false);
            } else {
                setPreviewStatus('The document could not be generated — see the message in the document panel, then fix the settings.', true);
            }
        } else {
            setPreviewStatus('Live document — click its title, subtitle, footer or column headings to edit. Print and the PDF use exactly this document.', false);
            wireCanvas();
        }
        updateSummary();
    });
    document.querySelectorAll('.rb-generate-btn').forEach(function (genBtn) {
        genBtn.addEventListener('click', function () {
            var cfg = collectConfig();
            if (!validForSubmit(cfg)) { return; }
            el('rbPdfConfig').value = JSON.stringify(cfg);
            el('rbPdfForm').submit();
        });
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

    if (!el('rbFooterText').value) { el('rbFooterText').value = SCHOOL_NAME; }
    updateMarksHint();
    ensureAutoTitle();
    applySourceColumns(el('rbSource').value);
    updateSummary();
})();
</script>
@endsection
