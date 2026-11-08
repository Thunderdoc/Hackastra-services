@extends('layouts.master')
@section('page_title', 'Attendance Report')
@section('content')

    {{-- Monthly Attendance Report — screen. Workstream CD rebuilt it
         on the shared list_page pattern (Loop 28); this redesign
         (Loop 80) gives it the document's summary chips, status
         pills and class summary, all from the same
         AttendanceController::monthlyReportData assembly the print
         preview and the PDF render — screen, paper and download can
         never disagree. The old in-page print-doc-head/foot retired:
         the printed document now lives on its own preview page
         (attendance.report.print) with a real downloadable PDF
         beside it (attendance.report.pdf). Filters keep the exact
         existing contract: class_id + month, GET, auto-submit. --}}
    @php
        $marxParams = ['class_id' => !empty($all_classes) ? 'all' : ($selected_class ? $selected_class->id : ''), 'month' => $month->format('Y-m'), 'months' => $months ?? 1];
        // Section filter rides the toolbar links only when one is
        // selected — an empty section_id must never be emitted.
        if (!empty($selected_section)) {
            $marxParams['section_id'] = $selected_section->id;
        }
    @endphp

    @component('partials.patterns.list_page')
        @slot('action')
            {{-- Item 7: the document actions act on the CURRENTLY
                 selected filters (JS keeps the hrefs in sync); with
                 no complete selection they are disabled, never an
                 error. --}}
            <a href="{{ route('attendance.report.print', $marxParams) }}" data-base="{{ route('attendance.report.print') }}" target="_blank" class="btn btn-light btn-sm att-doc-btn"><i class="icon-eye mr-1"></i> Preview</a>
            <a href="{{ route('attendance.report.print', $marxParams + ['autoprint' => 1]) }}" data-base="{{ route('attendance.report.print') }}" data-autoprint="1" target="_blank" class="btn btn-light btn-sm att-doc-btn"><i class="icon-printer mr-1"></i> Print</a>
            <a href="{{ route('attendance.report.pdf', $marxParams) }}" data-base="{{ route('attendance.report.pdf') }}" class="btn btn-light btn-sm att-doc-btn"><i class="icon-file-pdf mr-1"></i> Download PDF</a>
            <a href="{{ route('attendance.export', $marxParams) }}" data-base="{{ route('attendance.export') }}" class="btn btn-primary btn-sm att-doc-btn"><i class="icon-download mr-1"></i> Export</a>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('attendance.report') }}" class="pat-filter-form" id="ar-filter-form">
                <div class="pat-filter-field">
                    <label for="ar_class_id">Class</label>
                    <select name="class_id" id="ar_class_id" class="form-control">
                        @if($classes->count() > 1)
                            <option value="all" {{ !empty($all_classes) ? 'selected' : '' }}>{{ Qs::userIsTeacher() ? 'All assigned classes' : 'All Classes' }}</option>
                        @endif
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="ar_month">Month</label>
                    <input type="month" name="month" id="ar_month" class="form-control" value="{{ $month->format('Y-m') }}">
                </div>
                <div class="pat-filter-field">
                    <label for="ar_section_id">Section</label>
                    <select name="section_id" id="ar_section_id" class="form-control" {{ $selected_class ? '' : 'disabled' }}>
                        <option value="" {{ empty($selected_section) ? 'selected' : '' }}>All sections</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" {{ $selected_section && $selected_section->id === $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
                <span id="att-updating" class="text-muted" style="display:none" role="status"><i class="icon-spinner2 spinner mr-1"></i> Updating&hellip;</span>
                <div class="pat-filter-field">
                    <label for="ar_months">Period</label>
                    <select name="months" id="ar_months" class="form-control">
                        @foreach([1 => 'This month', 2 => 'Last 2 months', 3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'] as $mVal => $mLabel)
                            <option value="{{ $mVal }}" {{ ($months ?? 1) === $mVal ? 'selected' : '' }}>{{ $mLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        @endslot

        <div id="att-results">
            @include('pages.support_team.attendance.partials.report_results')
        </div>
    @endcomponent
@endsection

@section('scripts')
<script>
    /* Item 7 (owner, 2026-11-01): the filters refresh ONLY the
       results region via AJAX (fast, in seconds) — no Filter
       button, no page reload. The list stays empty until a class
       AND a section are chosen; the document buttons track the
       current filters and disable until the selection is complete. */
    $(function () {
        var resultsUrl = '{{ route('attendance.report.results') }}',
            sectionsUrl = '{{ route('attendance.report.sections') }}',
            $results = $('#att-results'), $updating = $('#att-updating'),
            $class = $('#ar_class_id'), $section = $('#ar_section_id'),
            seq = 0;

        function params() {
            return {
                class_id: $class.val() || '',
                section_id: $section.val() || '',
                month: $('#ar_month').val() || '',
                months: $('#ar_months').val() || '1'
            };
        }
        function ready(p) {
            return p.class_id !== '' && p.class_id !== 'all' && p.section_id !== '';
        }
        function syncActions(p) {
            var ok = ready(p), q = {};
            if (p.class_id) { q.class_id = p.class_id; }
            if (p.section_id) { q.section_id = p.section_id; }
            if (p.month) { q.month = p.month; }
            if (p.months) { q.months = p.months; }
            $('.att-doc-btn').each(function () {
                var base = $(this).attr('data-base'), extra = {};
                if ($(this).attr('data-autoprint')) { extra.autoprint = 1; }
                $(this).attr('href', base + '?' + $.param($.extend({}, q, extra)));
                $(this).toggleClass('disabled', !ok).attr('aria-disabled', ok ? 'false' : 'true');
            });
        }
        function refresh() {
            var p = params(), my = ++seq;
            syncActions(p);
            $updating.show();
            /* Round 5 Slice 1 (P2): the wait is a skeleton table, not
               stale rows. Only for a complete selection — an
               incomplete one resolves straight to the pick-a-class
               prompt. A failed fetch restores the previous rows and
               the shared error floor says what happened. */
            var skel = ready(p) && window.hkSkel;
            if (skel) { window.hkSkel($results[0], true, { type: 'table', rows: 6 }); }
            $.getJSON(resultsUrl, p).done(function (d) {
                if (my !== seq) { return; }
                if (window.hkSkel) { window.hkSkel($results[0], false); }
                $results.html(d.results);
            }).fail(function () {
                if (my !== seq) { return; }
                if (skel) { window.hkSkel($results[0], false, { restore: true }); }
            }).always(function () {
                if (my === seq) { $updating.hide(); }
            });
        }
        $class.on('change', function () {
            var cid = $class.val();
            $section.prop('disabled', true);
            if (!cid || cid === 'all') {
                $section.empty().append('<option value="">All sections</option>');
                refresh();
                return;
            }
            $.getJSON(sectionsUrl, { class_id: cid }).done(function (list) {
                $section.empty().append('<option value="">All sections</option>');
                $.each(list, function (i, s) {
                    $section.append($('<option>', { value: s.id, text: s.name }));
                });
                $section.prop('disabled', false);
            }).always(refresh);
        });
        $section.on('change', refresh);
        $('#ar_month, #ar_months').on('change', refresh);
        $('.att-doc-btn').on('click', function (e) {
            if ($(this).hasClass('disabled')) { e.preventDefault(); }
        });
        $('#ar-filter-form').on('submit', function (e) { e.preventDefault(); refresh(); });
        syncActions(params());
    });
</script>
@endsection
