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
       styles (rb5-) — no shared component is touched.
       Simplification (owner, 2026-10-30 evening — "fix this, and
       I do NOT want all this stuff"): the toolbar card is gone;
       title / subtitle / sort ride as hidden inputs (the document
       itself is where they are edited); paper + orientation live
       in a collapsed Page settings section on the left; font
       family/size controls are gone — the engine defaults apply. */
    .rb5-switcher { display: none; gap: 8px; margin-bottom: 10px; }
    .rb5-switcher .btn { flex: 1; }
    .rb5-wrap { display: flex; gap: 14px; align-items: stretch; }
    .rb5-left { flex: 0 0 43%; max-width: 43%; min-width: 0; overflow-y: auto; padding-right: 2px; }
    .rb5-right { flex: 1 1 57%; min-width: 0; display: flex; flex-direction: column; }
    .rb5-left .card { background: #fff; border: 1px solid #c9d6e4; border-radius: 12px; box-shadow: 0 1px 3px rgba(16, 38, 59, .07); margin-bottom: 14px; }
    .rb5-left .card-header { background: #f7fafd; border-bottom: 1px solid #dfe8f3; border-radius: 12px 12px 0 0; padding: 10px 16px; }
    .rb5-left .card-title { font-size: 15px; font-weight: 700; color: #10263b; }
    .rb5-left .card-body { padding: 14px 16px; }
    /* Simplification (owner, 2026-10-30 evening): the rb5 toolbar
       card is DELETED on his order ("I do not want all this
       stuff"). In its place, ONE slim header row above the
       document: the "Report: …" summary + Print + Download PDF. */
    .rb5-docbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; background: #fff; border: 1px solid #c9d6e4; border-radius: 10px; padding: 7px 10px; margin-bottom: 8px; }
    .rb5-docbar-actions { display: flex; gap: 6px; align-items: center; }
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
    .rb5-pagesettings-toggle { font-size: 15px; font-weight: 700; color: #10263b; text-decoration: none !important; }
    .rb5-pagesettings-toggle:hover { color: #1765f5; }
    /* Beauty Batch 1 (2026-11-07, audit G1): dark parity for the
       rb5 chrome — the config cards, column items, docbar and
       workspace desk take the app dark tokens. The document
       sheet (#previewFrame) stays white on purpose: it is paper.
       Light mode is untouched. */
    html.hk-dark .rb5-left .card { background: #26272a; border-color: #36383c; box-shadow: 0 1px 3px rgba(0, 0, 0, .35); }
    html.hk-dark .rb5-left .card-header { background: #202124; border-bottom-color: #36383c; }
    html.hk-dark .rb5-left .card-title { color: #f4f4f5; }
    html.hk-dark .rb5-docbar { background: #26272a; border-color: #36383c; }
    html.hk-dark .rb2-status { color: #a5a8ad; }
    html.hk-dark .rb2-status-error { color: #f2a9a9; }
    html.hk-dark .rb2-summary { color: #f4f4f5; }
    html.hk-dark .rb2-loading { color: #7db3ff; }
    html.hk-dark .rb5-workspace { background: #101112; border-color: #2c2e31; }
    html.hk-dark .rb5-col-item { background: #202124; border-color: #36383c; }
    html.hk-dark .rb5-col-item.rb5-drag-over { border-color: #3478f6; box-shadow: 0 0 0 1px #3478f6 inset; }
    html.hk-dark .rb5-drag { color: #7b7f87; }
    html.hk-dark .rb2-col-field label { color: #a5a8ad; }
    html.hk-dark .rb2-col-static { color: #c9cbce; }
    html.hk-dark .rb5-pagesettings-toggle { color: #f4f4f5; }
    html.hk-dark .rb5-pagesettings-toggle:hover { color: #7db3ff; }
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
                {{-- Title / subtitle / sort have no visible fields
                     (owner simplification): they ride as hidden
                     inputs — the title and subtitle are edited in
                     the document itself (canvas data-edit sync),
                     and sort arrives via presets / saved templates. --}}
                <input type="hidden" id="rbTitle">
                <input type="hidden" id="rbSubtitle">
                <input type="hidden" id="rbSortKey" value="">
                <input type="hidden" id="rbSortDir" value="asc">
                <div id="rbMarksHint" class="alert alert-info" style="display:none;">
                    Marks reports require an exam, a class and a subject. Component columns (Theory, Internal, Viva, …) are added automatically for configured marking schemes.
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
                        <span id="rbStatusWrap"><input type="text" id="rbStatus" class="form-control" placeholder="e.g. overdue"></span>
                        <small class="form-text text-muted" id="rbStatusHint">Source-specific: overdue, issued, partial, …</small>
                    </div>
                    <div class="form-group col-md-6 rb2-filter" data-filter-group="session">
                        <label>Academic Year / Session</label>
                        <input type="text" id="rbYear" class="form-control" value="{{ $currentSession }}">
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

        {{-- Page settings (owner simplification): ONE collapsed
             section, closed by default. Paper + orientation moved
             here from the deleted toolbar; the font size control
             is gone entirely — the engine default applies. --}}
        <div class="card">
            <div class="card-header">
                <button type="button" class="btn btn-link p-0 rb5-pagesettings-toggle" data-toggle="collapse" data-target="#rbPageSettings" aria-expanded="false" aria-controls="rbPageSettings">Page settings</button>
            </div>
            <div class="collapse" id="rbPageSettings">
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Paper size</label>
                            <select id="rbPaper" class="form-control">
                                <option value="a4">A4</option>
                                <option value="letter">Letter</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Orientation</label>
                            <select id="rbOrientation" class="form-control">
                                <option value="portrait">Portrait</option>
                                <option value="landscape">Landscape</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-check"><input type="checkbox" id="rbShowHeader" class="form-check-input" checked> <label class="form-check-label" for="rbShowHeader">Include school header (logo, name, contacts)</label></div>
                    <div class="form-check"><input type="checkbox" id="rbShowMeta" class="form-check-input" checked> <label class="form-check-label" for="rbShowMeta">Include class / subject details line</label></div>
                    <div class="form-check"><input type="checkbox" id="rbShowFooter" class="form-check-input" checked> <label class="form-check-label" for="rbShowFooter">Include footer + page numbers</label></div>
                    <div class="form-check mb-2"><input type="checkbox" id="rbShowSignatures" class="form-check-input"> <label class="form-check-label" for="rbShowSignatures">Include signature lines</label></div>
                    <div class="form-group">
                        <label>Footer text</label>
                        <input type="text" id="rbFooterText" class="form-control" maxlength="200" placeholder="Printed at the foot of every page">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Margins (mm)</label>
                            <input type="number" id="rbMargin" class="form-control" min="5" max="25" value="10">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Blank rows</label>
                            <input type="number" id="rbBlankRows" class="form-control" min="0" max="200" value="0">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label>Instructions (printed at the top of the document)</label>
                        <textarea id="rbInstructions" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            {{-- Beauty Batch 6 (audit E8): the Download PDF / Word
                 pair lived here AND in the document bar above the
                 sheet. The docbar pair (bound by class in the page
                 script) is the one home now; this card only saves
                 templates. --}}
            <div class="card-header"><h5 class="card-title mb-0">Save Template</h5></div>
            <div class="card-body">
                <div class="form-row align-items-end">
                    <div class="form-group col-8 mb-0">
                        <label>Save as my template</label>
                        <input type="text" id="rbTemplateName" class="form-control" maxlength="80" placeholder="Template name">
                    </div>
                    <div class="form-group col-4 mb-0">
                        <button type="button" id="rbSaveBtn" class="btn btn-light btn-block" data-hx-guard data-hx-guard-ms="6000">Save Template</button>
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
                            <form method="POST" action="{{ route('reports.templates.destroy', [$template->id]) }}" style="display:inline;" data-confirm="Delete template {{ $template->name }}? This cannot be undone." data-confirm-title="Delete Template" data-confirm-action="Delete" data-confirm-danger>
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
        {{-- Slim document header (owner simplification): the
             "Report: …" summary + the two downloads. This is
             the ONLY chrome above the document now. --}}
        <div class="rb5-docbar">
            <p id="rbSummary" class="rb2-summary mb-0"></p>
            <div class="rb5-docbar-actions">
                <button type="button" class="btn btn-primary btn-sm rb-generate-btn" style="height:32px;" data-hx-guard data-hx-guard-ms="6000"><i class="icon-file-pdf mr-1"></i> Download PDF</button>
                <button type="button" class="btn btn-light btn-sm rb-word-btn" style="height:32px;" data-hx-guard data-hx-guard-ms="6000"><i class="icon-file-word mr-1"></i> Download Word</button>
            </div>
        </div>
        <div class="rb5-statusbar">
            <p id="rbPreviewStatus" class="rb2-status mb-0" role="status">Loading the document…</p>
            <p id="rbPreviewLoading" class="rb2-loading mb-0" style="display:none;">Updating document…</p>
            <button type="button" id="rbRefreshData" class="btn btn-link btn-sm p-0">↻ Refresh data</button>
            <span id="rbDraftNote" class="rb2-status" style="display:none;">Restored your unsaved draft — <button type="button" id="rbStartFresh" class="btn btn-link btn-sm p-0">Start fresh</button></span>
        </div>
        <div class="hk-skel-bar" id="rbSkelBar" style="display:none;" aria-hidden="true"></div>
        <div class="rb5-workspace">
            <div class="hk-skel-overlay" id="rbSkelOverlay">@include('partials.patterns.skeleton', ['type' => 'frame', 'rows' => 8])</div>
            <iframe id="previewFrame" name="previewFrame" src="about:blank" title="Report document"></iframe>
        </div>
    </div>
</div>

<form id="rbPdfForm" method="POST" action="{{ route('reports.pdf') }}" style="display:none;">
    @csrf
    <input type="hidden" name="config" id="rbPdfConfig">
</form>
<form id="rbWordForm" method="POST" action="{{ route('reports.word') }}" style="display:none;">
    @csrf
    <input type="hidden" name="config" id="rbWordConfig">
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
    var SCHOOL_NAME = @json(\App\Helpers\Qs::getSetting('system_name') ?: 'School');
    var CURRENT_SESSION = @json($currentSession);
    var PREVIEW_HTML_URL = @json(route('reports.preview_html'));
    var RB_USER_ID = @json(auth()->id());
    var DRAFT_KEY = 'rb5-draft-' + RB_USER_ID;
    var COL_TYPES = ['text', 'number', 'currency', 'date', 'checkbox', 'box', 'dropdown', 'yes_no', 'signature', 'remarks', 'blank'];

    /* Which filter groups each source shows — derived from the
       declaration the server puts on every source
       (ReportSources::definitions()['filters']: the exact keys
       that source's row builder reads), so the Details card can
       never offer a filter the source would ignore. One
       declaration, in one place, for UI and resolver alike. */
    var FILTER_KEY_GROUPS = { class_id: 'class', section_id: 'section', subject_id: 'subject', exam_id: 'exam', date_from: 'dates', date_to: 'dates', year: 'session', status: 'status' };
    var FILTER_MAP = {};
    Object.keys(SOURCES).forEach(function (srcKey) {
        var groups = [];
        (SOURCES[srcKey].filters || []).forEach(function (filterKey) {
            var g = FILTER_KEY_GROUPS[filterKey];
            if (g && groups.indexOf(g) === -1) { groups.push(g); }
        });
        FILTER_MAP[srcKey] = groups;
    });
    var FILTER_GROUP_INPUTS = { class: ['rbClass'], section: ['rbSection'], subject: ['rbSubject'], exam: ['rbExam'], dates: ['rbDateFrom', 'rbDateTo'], status: ['rbStatus'], session: ['rbYear'] };
    function el(id) { return document.getElementById(id); }
    /* Status control (Phase-1 live-edit fix): when the source's
       definition carries status_options, the status filter is a
       SELECT whose values are the stored keys and whose labels are
       the displayed words — the user can no longer type the
       displayed word ("Partial") and silently get zero rows.
       Sources without options keep the free-text box. Switching
       source re-renders the control and drops a value the new
       source does not offer. */
    function renderStatusControl() {
        var wrap = el('rbStatusWrap');
        if (!wrap) { return; }
        var srcDef = SOURCES[el('rbSource').value] || {};
        var options = srcDef.status_options || null;
        var current = el('rbStatus');
        var prev = current ? current.value : '';
        wrap.innerHTML = '';
        var hint = el('rbStatusHint');
        if (options) {
            var sel = document.createElement('select');
            sel.id = 'rbStatus';
            sel.className = 'form-control';
            var anyOpt = document.createElement('option');
            anyOpt.value = '';
            anyOpt.textContent = 'Any';
            sel.appendChild(anyOpt);
            Object.keys(options).forEach(function (k) {
                var o = document.createElement('option');
                o.value = k;
                o.textContent = options[k];
                sel.appendChild(o);
            });
            sel.value = Object.prototype.hasOwnProperty.call(options, prev) ? prev : '';
            wrap.appendChild(sel);
            if (hint) { hint.textContent = 'Choose the status to include — Any shows all.'; }
        } else {
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.id = 'rbStatus';
            inp.className = 'form-control';
            inp.placeholder = 'e.g. overdue';
            inp.value = prev;
            wrap.appendChild(inp);
            if (hint) { hint.textContent = 'Source-specific: overdue, issued, partial, …'; }
        }
    }
    function applyFilterVisibility() {
        var groups = FILTER_MAP[el('rbSource').value] || [];
        document.querySelectorAll('.rb2-filter').forEach(function (node) {
            var g = node.getAttribute('data-filter-group');
            var show = groups.indexOf(g) !== -1;
            node.style.display = show ? '' : 'none';
            if (!show) {
                /* A hidden filter is a cleared filter (owner,
                   2026-11-01): its old value must never ride along
                   into this source's config. The session box resets
                   to the current session, its standing default. */
                (FILTER_GROUP_INPUTS[g] || []).forEach(function (inputId) {
                    var input = el(inputId);
                    if (input) { input.value = inputId === 'rbYear' ? CURRENT_SESSION : ''; }
                });
            }
        });
        el('rbNoFilters').style.display = groups.length ? 'none' : '';
        renderStatusControl();
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
    var rbFirstDocLoaded = false;
    var previewSeq = 0;
    var previewAbort = null;
    var lastPreviewHtml = '';
    var lastRenderedConfigJson = '';
    var lastRenderTime = 0;
    var pendingGenAt = '';
    var pendingRecords = '';
    var pendingConfigJson = '';
    var pendingScroll = null;
    var suppressPresetReset = false;
    var autoTitle = '';
    var titleTouched = false;
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
        /* Round 5 Slice 1 (P2): once the first document has loaded,
           updates show the slim skeleton bar — the paper on screen is
           never blanked while the next render is in flight. Before
           the first load, the skeleton overlay over the frame (hidden
           by the load listener) is the loading state. */
        var bar = el('rbSkelBar');
        if (bar && rbFirstDocLoaded) { bar.style.display = on ? '' : 'none'; }
    }
    function updateSummary() {
        var out = el('rbSummary');
        if (!out) { return; }
        /* The summary is computed from collectConfig() — the very
           object the preview and the downloads are built from — on
           every change, so the bar can never describe a different
           report than the settings on the left (owner, 2026-11-01:
           it kept reading Students / All classes after the source
           and class had already changed). */
        var cfg = collectConfig();
        function optionLabel(selId, value) {
            var sel = el(selId);
            if (!sel) { return ''; }
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === String(value)) { return sel.options[i].textContent.trim(); }
            }
            return '';
        }
        var parts = ['Report: ' + sourceLabel()];
        parts.push('Class: ' + (cfg.filters.class_id ? (optionLabel('rbClass', cfg.filters.class_id) || 'All classes') : 'All classes'));
        if (cfg.filters.section_id) { parts.push('Section: ' + optionLabel('rbSection', cfg.filters.section_id)); }
        if (cfg.filters.subject_id) { parts.push('Subject: ' + optionLabel('rbSubject', cfg.filters.subject_id)); }
        if (cfg.filters.exam_id) { parts.push('Exam: ' + optionLabel('rbExam', cfg.filters.exam_id)); }
        if (cfg.filters.status) { parts.push('Status: ' + cfg.filters.status); }
        if (cfg.filters.date_from || cfg.filters.date_to) { parts.push('Dates: ' + (cfg.filters.date_from || '…') + ' to ' + (cfg.filters.date_to || '…')); }
        parts.push(cfg.columns.length + ' column' + (cfg.columns.length === 1 ? '' : 's'));
        out.textContent = parts.join(' • ');
    }
    /* Draft autosave (Phase-1 live-edit fix): every schedule saves
       the current config to localStorage under a per-user key, so a
       reload — including the Save / Duplicate / Delete template
       page reloads — restores the work instead of losing it. */
    function saveDraft() {
        try {
            var payload = { config: collectConfig(), preset: el('rbPreset') ? el('rbPreset').value : '' };
            localStorage.setItem(DRAFT_KEY, JSON.stringify(payload));
        } catch (e) { /* storage full / blocked — editing still works */ }
    }
    /* Single scheduler (Phase-1 live-edit fix): every change flows
       through markDirty(). Typing-class changes debounce 600 ms,
       discrete changes 250 ms. Three truthful statusbar states:
       Editing… (dirty, not yet sent), Updating document… (in
       flight), Updated HH:MM:SS • N records (done, from the
       X-RB-* response headers). Any manual change also resets the
       preset select to Custom, so a preset can always be
       re-applied. */
    function markDirty(source) {
        if (!suppressPresetReset && source !== 'preset' && source !== 'restore') {
            var presetEl = el('rbPreset');
            if (presetEl && presetEl.value) { presetEl.value = ''; }
        }
        updateSummary();
        saveDraft();
        if (previewTimer) { clearTimeout(previewTimer); }
        setPreviewStatus('Editing…', false);
        setPreviewLoading(true);
        var delay = source === 'typing' ? 600 : 250;
        previewTimer = setTimeout(runPreview, delay);
    }
    function schedulePreview() { markDirty('discrete'); }
    /* rb5 transport: the document is HTML (the same print view),
       fetched and swapped into the frame. A sequence number
       discards stale responses, so a slow earlier render can never
       overwrite a newer one, and an AbortController cancels the
       superseded request itself. Response discipline (Phase-1):
       ONLY an HTTP 200 swaps the frame; 422 keeps the existing
       error-document path; 401/419 means the session expired and
       is NEVER loaded into the frame; any other failure keeps the
       old document and says so. Freshness is true by construction:
       every successful preview is a fresh DB resolve — nothing is
       cached client- or server-side (cache: 'no-store'). Text
       edits inside the document stay local and sync into the
       config — they never trigger this. */
    function runPreview() {
        previewTimer = null;
        var cfg = collectConfig();
        var problem = previewDraftProblem(cfg);
        if (problem) { setPreviewLoading(false); setPreviewStatus('Document not updated — ' + problem + ' The document below still shows the previous settings.', true); return; }
        var seq = ++previewSeq;
        if (previewAbort) { try { previewAbort.abort(); } catch (e) { /* already settled */ } }
        previewAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        /* Swap preservation: capture the frame scroll (and whether
           the user was mid-edit in the canvas) before the swap, so
           the new document lands where the old one was read. */
        pendingScroll = null;
        try {
            var frameEl = el('previewFrame');
            var fw = frameEl.contentWindow;
            var fdoc0 = frameEl.contentDocument;
            var focusKind = null;
            if (fdoc0 && fdoc0.activeElement) {
                var ae = fdoc0.activeElement;
                if (ae.getAttribute && ae.getAttribute('data-edit')) { focusKind = ae.getAttribute('data-edit'); }
                else if (ae.contentEditable === 'true') { focusKind = 'heading'; }
            }
            pendingScroll = { x: fw ? (fw.scrollX || 0) : 0, y: fw ? (fw.scrollY || 0) : 0, focusKind: focusKind };
        } catch (e) { pendingScroll = null; }
        pendingConfigJson = JSON.stringify(cfg);
        previewRequested = true;
        setPreviewLoading(true);
        setPreviewStatus('Updating document…', false);
        var fd = new FormData();
        var tokenInput = el('rbSaveForm').querySelector('input[name="_token"]');
        fd.append('_token', tokenInput ? tokenInput.value : '');
        fd.append('config', JSON.stringify(cfg));
        var fetchOpts = { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' };
        if (previewAbort) { fetchOpts.signal = previewAbort.signal; }
        fetch(PREVIEW_HTML_URL, fetchOpts)
            .then(function (r) { return r.text().then(function (t) { return { status: r.status, ok: r.ok, text: t, genAt: r.headers.get('X-RB-Generated-At') || '', records: r.headers.get('X-RB-Records') || '' }; }); })
            .then(function (res) {
                if (seq !== previewSeq) { return; }
                if (res.status === 401 || res.status === 419) {
                    previewRequested = false;
                    setPreviewLoading(false);
                    setPreviewStatus('Session expired — your draft is saved on this device; reload to sign in again', true);
                    return;
                }
                if (res.status === 422) {
                    pendingGenAt = '';
                    pendingRecords = '';
                    el('previewFrame').srcdoc = res.text;
                    return;
                }
                if (!res.ok) {
                    previewRequested = false;
                    setPreviewLoading(false);
                    setPreviewStatus('Document not updated — server error. The document below still shows the previous settings.', true);
                    return;
                }
                pendingGenAt = res.genAt;
                pendingRecords = res.records;
                if (lastPreviewHtml && res.text === lastPreviewHtml) {
                    /* Byte-identical refresh: skip the swap so
                       scroll and focus survive untouched. */
                    previewRequested = false;
                    setPreviewLoading(false);
                    lastRenderTime = Date.now();
                    lastRenderedConfigJson = pendingConfigJson;
                    setPreviewStatus(updatedStamp(), false);
                    return;
                }
                lastPreviewHtml = res.text;
                el('previewFrame').srcdoc = res.text;
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') { return; }
                if (seq !== previewSeq) { return; }
                previewRequested = false;
                setPreviewLoading(false);
                setPreviewStatus('Document not updated — could not reach the server. The document below still shows the previous settings.', true);
            });
    }
    function updatedStamp() {
        var time = '';
        var m = /(\d{2}:\d{2}:\d{2})/.exec(pendingGenAt || '');
        if (m) { time = m[1]; }
        if (!time) { time = new Date().toLocaleTimeString('en-GB'); }
        var n = pendingRecords !== '' && pendingRecords !== null ? pendingRecords : '0';
        return 'Updated ' + time + ' • ' + n + ' record' + (String(n) === '1' ? '' : 's');
    }

    function isCustomKey(k) { return /^custom_\d+$/.test(k); }
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
        var addBtn = el('rbAddBtn');
        if (addBtn) { addBtn.disabled = sel.options.length === 0; }
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
            inp.addEventListener('input', function () { col.label = inp.value; renderTotals(); renderSort(); /* scheduling is left to the delegated #rbSettings input handler */ });
            line1.appendChild(inp);
            var showWrap = document.createElement('label');
            showWrap.className = 'rb2-col-show';
            var chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.checked = !col.hidden;
            chk.addEventListener('change', function () { col.hidden = !chk.checked; renderTotals(); /* scheduling is left to the delegated #rbSettings change handler */ });
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
                sel.addEventListener('change', function () { col.type = sel.value; renderTotals(); /* scheduling is left to the delegated #rbSettings change handler */ });
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
            winp.addEventListener('input', function () { col.width = parseInt(winp.value, 10) || 10; /* scheduling is left to the delegated #rbSettings input handler */ });
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
            asel.addEventListener('change', function () { col.align = asel.value; /* scheduling is left to the delegated #rbSettings change handler */ });
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
            if (col.hidden) { return; }
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
                /* scheduling is left to the delegated #rbSettings change handler */
            });
            selWrap.appendChild(sel);
            row.appendChild(lab);
            row.appendChild(selWrap);
            wrap.appendChild(row);
        });
        if (!any) { wrap.textContent = 'Add a numeric column to enable totals.'; }
    }
    /* Sort has no visible control any more (owner simplification):
       the key/dir arrive via presets and saved templates and ride
       in the hidden inputs. renderSort() only keeps the state
       honest — a sort key whose column was removed falls back to
       the source order — and mirrors it into the hidden input. */
    function renderSort() {
        var valid = false;
        columns.forEach(function (col) { if (col.key === sortKey) { valid = true; } });
        if (!valid) { sortKey = ''; }
        el('rbSortKey').value = sortKey;
    }
    function collectConfig() {
        /* Only the filters the current source declares are sent —
           a value left in a hidden field can never leak into the
           config (the visibility pass clears them too; this is the
           guarantee, that is the hygiene). */
        var groups = FILTER_MAP[el('rbSource').value] || [];
        var filters = {};
        var classId = el('rbClass').value;
        var sectionId = el('rbSection').value;
        var subjectId = el('rbSubject').value;
        var examId = el('rbExam').value;
        if (classId && groups.indexOf('class') !== -1) { filters.class_id = parseInt(classId, 10); }
        if (sectionId && groups.indexOf('section') !== -1) { filters.section_id = parseInt(sectionId, 10); }
        if (subjectId && groups.indexOf('subject') !== -1) { filters.subject_id = parseInt(subjectId, 10); }
        if (examId && groups.indexOf('exam') !== -1) { filters.exam_id = parseInt(examId, 10); }
        if (el('rbYear').value && groups.indexOf('session') !== -1) { filters.year = el('rbYear').value; }
        if (el('rbDateFrom').value && groups.indexOf('dates') !== -1) { filters.date_from = el('rbDateFrom').value; }
        if (el('rbDateTo').value && groups.indexOf('dates') !== -1) { filters.date_to = el('rbDateTo').value; }
        if (el('rbStatus').value && groups.indexOf('status') !== -1) { filters.status = el('rbStatus').value; }
        var options = {
            orientation: el('rbOrientation').value,
            paper: el('rbPaper').value,
            footer_text: el('rbFooterText').value,
            margin_mm: parseInt(el('rbMargin').value, 10) || 10,
            /* No font_size: the control is deleted (owner
               simplification) — the engine default applies. */
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
        suppressPresetReset = true;
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
        /* Membership check (Phase-1): a saved section/subject that
           does not belong to the loaded class is dropped — with a
           note — instead of silently producing an empty report. */
        var droppedNotes = [];
        var sectionOk = !f.section_id;
        if (f.section_id) {
            for (var si = 0; si < SECTIONS.length; si++) {
                if (String(SECTIONS[si].id) === String(f.section_id) && (!f.class_id || String(SECTIONS[si].my_class_id) === String(f.class_id))) { sectionOk = true; }
            }
        }
        if (!sectionOk) { droppedNotes.push('section'); }
        el('rbSection').value = sectionOk ? (f.section_id || '') : '';
        var subjectOk = !f.subject_id;
        if (f.subject_id) {
            for (var sj = 0; sj < SUBJECTS.length; sj++) {
                if (String(SUBJECTS[sj].id) === String(f.subject_id) && (!f.class_id || String(SUBJECTS[sj].my_class_id) === String(f.class_id))) { subjectOk = true; }
            }
        }
        if (!subjectOk) { droppedNotes.push('subject'); }
        el('rbSubject').value = subjectOk ? (f.subject_id || '') : '';
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
        suppressPresetReset = false;
        if (droppedNotes.length) {
            setPreviewStatus('Note: the saved ' + droppedNotes.join(' and ') + ' does not belong to the selected class, so it was cleared.', false);
        }
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
            th.addEventListener('input', function () {
                /* Sync on input (Phase-1): the rename lands in the
                   column object immediately, so an ill-timed
                   re-render can no longer eat it. Blur still
                   triggers the re-render. */
                var liveText = th.textContent.trim();
                if (liveText) { vis[i].label = liveText; }
            });
            th.addEventListener('blur', function () {
                var text = th.textContent.trim();
                if (text && text !== original) {
                    vis[i].label = text;
                    renderColumns();
                }
            });
        });
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
        /* scheduling is left to the delegated #rbSettings change handler */
    });
    el('rbPreset').addEventListener('change', function () {
        var key = el('rbPreset').value;
        if (!key) { markDirty('preset'); return; }
        for (var i = 0; i < PRESETS.length; i++) {
            if (PRESETS[i].key === key) { applyConfig(PRESETS[i].config); return; }
        }
    });
    el('rbAddBtn').addEventListener('click', function () {
        /* Real source columns only (owner, 2026-11-01): a report's
           data always comes from the school records — there is no
           hand-typed custom column any more. Old templates that
           carry custom_N columns still load and render. */
        var val = el('rbAddCol').value;
        var found = null;
        sourceCols().forEach(function (c) { if (c.key === val) { found = c; } });
        if (found) { columns.push({ key: found.key, label: found.label, type: found.type, width: 12 }); }
        renderColumns();
    });
    el('rbRestoreCols').addEventListener('click', function () {
        applySourceColumns(el('rbSource').value);
    });
    el('rbChangeTemplate').addEventListener('click', function () {
        el('rb5Wrap').classList.remove('rb5-show-doc');
        el('rbPreset').focus();
    });
    /* Delegated scheduling (Phase-1): the ONE delegated pair is
       the scheduler for every control inside #rbSettings whose own
       handler does not already render (columns list, page
       settings, filters). Typing-class controls (text / number /
       date inputs, textareas) debounce as typing; everything else
       is discrete. rbPreset and rbSource are excluded — their own
       change handlers apply a whole config, which schedules via
       renderColumns(). */
    function delegatedMarkDirty(ev) {
        if (!ev.target) { return; }
        var id = ev.target.id;
        if (id === 'rbTemplateName' || id === 'rbPreset' || id === 'rbSource') { return; }
        var t = ev.target;
        var typing = t.tagName === 'TEXTAREA' || (t.tagName === 'INPUT' && (t.type === 'text' || t.type === 'number' || t.type === 'date'));
        markDirty(typing ? 'typing' : 'discrete');
    }
    el('rbSettings').addEventListener('input', delegatedMarkDirty);
    el('rbSettings').addEventListener('change', delegatedMarkDirty);
    /* Paper / orientation now live INSIDE #rbSettings (Page
       settings section), so the delegated input/change handlers
       above already schedule their previews — no separate wiring.
       The old browser-Print button is gone (owner, 2026-11-01):
       printing the frame applied the PDF-only letterhead layout
       in Chrome — giant blank page tops, no letterhead after
       page 1. The PDF and Word downloads are the document. */
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
        rbFirstDocLoaded = true;
        var rbSkelOverlayEl = el('rbSkelOverlay');
        if (rbSkelOverlayEl) { rbSkelOverlayEl.style.display = 'none'; }
        var isError = false;
        try {
            var fdoc = el('previewFrame').contentDocument;
            if (fdoc && fdoc.body && fdoc.body.getAttribute('data-rb-preview-error')) { isError = true; }
        } catch (e) { isError = false; }
        if (isError) {
            setPreviewStatus('Document not updated — the document could not be generated with these settings; see the message in the document panel.', true);
        } else {
            lastRenderTime = Date.now();
            lastRenderedConfigJson = pendingConfigJson;
            setPreviewStatus(updatedStamp(), false);
            wireCanvas();
            /* Restore the scroll captured before the swap. Focus
               is deliberately NOT taken back: if the user was
               mid-edit in the canvas, the new document must not
               steal it. */
            if (pendingScroll) {
                try {
                    var fw2 = el('previewFrame').contentWindow;
                    if (fw2) { fw2.scrollTo(pendingScroll.x, pendingScroll.y); }
                } catch (e) { /* frame not ready — scroll stays at top */ }
            }
        }
        updateSummary();
    });
    /* Freshness triggers (Phase-1): an explicit Refresh in the
       statusbar, plus an automatic refetch when the user returns
       to this tab — but only when nothing changed since the last
       render (a dirty config already has its own render queued)
       and at least 5 s have passed since that render. */
    el('rbRefreshData').addEventListener('click', function () { runPreview(); });
    function maybeAutoRefresh() {
        if (!lastRenderTime) { return; }
        if (Date.now() - lastRenderTime < 5000) { return; }
        if (JSON.stringify(collectConfig()) !== lastRenderedConfigJson) { return; }
        runPreview();
    }
    window.addEventListener('focus', maybeAutoRefresh);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && document.visibilityState === 'visible') { maybeAutoRefresh(); }
    });
    el('rbStartFresh').addEventListener('click', function () {
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* nothing stored */ }
        el('rbDraftNote').style.display = 'none';
        el('rbPreset').value = '';
        var firstSource = el('rbSource').options.length ? el('rbSource').options[0].value : el('rbSource').value;
        el('rbSource').value = firstSource;
        updateMarksHint();
        titleTouched = false;
        ensureAutoTitle();
        applySourceColumns(firstSource);
    });
    document.querySelectorAll('.rb-generate-btn').forEach(function (genBtn) {
        genBtn.addEventListener('click', function () {
            var cfg = collectConfig();
            if (!validForSubmit(cfg)) { return; }
            el('rbPdfConfig').value = JSON.stringify(cfg);
            el('rbPdfForm').submit();
        });
    });
    document.querySelectorAll('.rb-word-btn').forEach(function (wordBtn) {
        wordBtn.addEventListener('click', function () {
            var cfg = collectConfig();
            if (!validForSubmit(cfg)) { return; }
            el('rbWordConfig').value = JSON.stringify(cfg);
            el('rbWordForm').submit();
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
    /* Draft restore (Phase-1): if this user left an unsaved draft
       on this device, apply it over the default and say so in the
       statusbar — reloading the builder is no longer punished. */
    try {
        var rawDraft = localStorage.getItem(DRAFT_KEY);
        if (rawDraft) {
            var draftData = JSON.parse(rawDraft);
            if (draftData && draftData.config) {
                suppressPresetReset = true;
                applyConfig(draftData.config);
                suppressPresetReset = false;
                if (draftData.preset) { el('rbPreset').value = draftData.preset; }
                el('rbDraftNote').style.display = '';
                saveDraft();
            }
        }
    } catch (e) { /* unreadable draft — the default builder stands */ }
})();
</script>
@endsection
