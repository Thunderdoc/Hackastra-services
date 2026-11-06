@extends('layouts.master')
@section('page_title', 'Low Attendance (Below 75%)')
@section('hide_page_header', true)
@section('content')

    {{-- Loop 78 — Low Attendance premium uplift. The screen side
         stays on the shared list_page pattern; the stats slot
         carries four summary cards computed from the CURRENT
         filter set, and the filter row gains Section (limited to
         the chosen class). Print uses the v2 document system
         (Loop 31/71): the print-doc head/foot below + a print-only
         table with the FULL filtered set, so the paper figures
         always match the screen cards even when the screen table
         is paginated.
         Loop 117 — replica pass: reference header (breadcrumb +
         icon tile), one Export dropdown, the reference KPI set,
         and the search moved into the table card. --}}
    @php
        $filterLine = $month->format('F Y')
            . ($selected_class ? ' · Class: ' . $selected_class->name : (Qs::userIsTeacher() ? ' · Assigned Classes' : ' · All Classes'))
            . ($selected_section ? ' · Section: ' . $selected_section->name : '')
            . ($search !== '' ? ' · Search: "' . $search . '"' : '');
        $avgLabel = $summary['average'] !== null
            ? rtrim(rtrim(number_format($summary['average'], 1), '0'), '.') . '%'
            : '—';
        $dbInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $dbWord) {
            if ($dbWord !== '') {
                $dbInitials .= strtoupper(substr($dbWord, 0, 1));
                if (strlen($dbInitials) >= 2) { break; }
            }
        }
        if ($dbInitials === '') { $dbInitials = 'S'; }
    @endphp
    {{-- Print band (owner order 2026-10-29: header on EVERY page).
         With rows present, the band rides the print table's thead
         (see the print table below), which the browser repeats on
         every page; this standalone copy covers only the
         empty-result print, where there is no table to carry it. --}}
    @if($rows->isEmpty())
    <div class="print-doc-head">
        @if(Qs::getSetting('logo'))
            <img src="{{ Qs::getSetting('logo') }}" alt="">
        @else
            <span class="print-doc-mono" aria-hidden="true">{{ $dbInitials }}</span>
        @endif
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p id="ad-band-line">Low Attendance (Below {{ $threshold }}%) &mdash; {{ $filterLine }} &middot; {{ $summary['below'] }} student(s) below the line &middot; {{ $summary['critical'] }} critical (below 50%) &middot; Average {{ $avgLabel }}</p>
        </div>
    </div>
    @endif
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    <div class="att-low">
        {{-- Loop 117 (replica pass): the reference header — breadcrumb,
             icon tile, title + subtitle, and ONE Export dropdown beside
             View Full Report (the build had split Export CSV / Print
             into separate buttons). Screen-only; print keeps the
             document head above. --}}
        <div class="l117-page-head print-hide">
            <div class="l117-page-head-main">
                <span class="l117-page-head-icon"><i class="icon-users"></i></span>
                <div>
                    <div class="l117-crumb">Attendance &nbsp;/&nbsp; Low Attendance</div>
                    <h4 class="l117-page-title">Low Attendance (Below {{ $threshold }}%)</h4>
                    <p class="l117-page-sub">Students whose overall attendance is below the selected threshold.</p>
                </div>
            </div>
            <div class="l117-page-head-actions">
                <div class="dropdown d-inline-block">
                    <button type="button" class="btn btn-light btn-sm" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="icon-download mr-1"></i> Export <i class="icon-chevron-down ml-1"></i></button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="{{ route('attendance.defaulters.export', request()->query()) }}" data-base="{{ route('attendance.defaulters.export') }}" class="dropdown-item ad-export-link"><i class="icon-file-text2"></i> Export CSV</a>
                        <a href="{{ route('attendance.defaulters.pdf', request()->query()) }}" data-base="{{ route('attendance.defaulters.pdf') }}" class="dropdown-item ad-export-link"><i class="icon-file-pdf"></i> Download PDF</a>
                        <button type="button" class="dropdown-item" onclick="window.print()"><i class="icon-printer"></i> Print</button>
                    </div>
                </div>

            </div>
        </div>

        @component('partials.patterns.list_page')
            @slot('stats')
                {{-- Loop 117: the reference KPI set, value-led — Students
                     below threshold, Classes carrying them, their average,
                     and total enrolment in scope. Every figure is computed
                     from the same filtered rows in defaulterData(). --}}
                <div id="ad-stats">
                    @include('pages.support_team.attendance.partials.defaulters_stats')
                </div>
            @endslot

            @slot('filters')
                <form method="GET" action="{{ route('attendance.defaulters') }}" class="pat-filter-form print-hide" id="ad-filter-form">
                    <div class="pat-filter-field">
                        <label for="ad_class_id">Class</label>
                        <select name="class_id" id="ad_class_id" class="form-control" >
                            <option value="">{{ Qs::userIsTeacher() ? 'All assigned classes' : 'All Classes' }}</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-field">
                        <label for="ad_section_id">Section</label>
                        <select name="section_id" id="ad_section_id" class="form-control"  {{ $selected_class ? '' : 'disabled' }}>
                            <option value="">All Sections</option>
                            @foreach($sections as $section)
                                <option value="{{ $section->id }}" {{ $selected_section && $selected_section->id === $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-field">
                        <label for="ad_month">Month</label>
                        <input type="month" name="month" id="ad_month" class="form-control" value="{{ $month->format('Y-m') }}" >
                    </div>
                    <div class="pat-filter-field">
                        <label for="ad_threshold">Below (%)</label>
                        <select name="threshold" id="ad_threshold" class="form-control" >
                            @foreach($thresholds as $option)
                                <option value="{{ $option }}" {{ $threshold === $option ? 'selected' : '' }}>{{ $option }}%</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-action">
                        <a href="{{ route('attendance.defaulters') }}" class="btn btn-light" id="ad-reset">Reset</a>
                        <span id="ad-updating" class="text-muted ml-2" style="display:none" role="status"><i class="icon-spinner2 spinner mr-1"></i> Updating&hellip;</span>
                    </div>
                </form>
            @endslot

            {{-- Loop 117: the table card gains its reference header —
                 title + subtext, and the search box lives here now
                 (it sat in the filter card). The search still posts
                 the same GET contract; the active filters ride along
                 as hidden fields so searching never resets them. --}}
            <div id="ad-results">
                @include('pages.support_team.attendance.partials.defaulters_results')
            </div>
        @endcomponent

        {{-- Print-only: the FULL filtered set (the screen table is
             paginated). Shown only on paper by Loop 78's print block;
             figures are the same $rows the cards are computed from. --}}
        <div id="ad-print">
            @include('pages.support_team.attendance.partials.defaulters_print')
        </div>
    </div>

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span id="ad-foot-line">Low Attendance (Below {{ $threshold }}%) &mdash; {{ $filterLine }} &middot; Generated by Hackstra</span>
    </div>

@section('scripts')
<script>
    /* Item 7 (owner, 2026-11-01): filter changes refresh ONLY the
       KPI strip + table + print regions via AJAX — no Filter
       button, no page reload. Empty until class + section. */
    $(function () {
        var resultsUrl = '{{ route('attendance.defaulters.results') }}',
            sectionsUrl = '{{ route('attendance.report.sections') }}',
            $class = $('#ad_class_id'), $section = $('#ad_section_id'),
            seq = 0;

        function currentSearch() {
            var $s = $('.l117-table-search input[name="search"]');
            return $s.length ? ($s.val() || '') : @json($search);
        }
        function params() {
            return {
                class_id: $class.val() || '',
                section_id: $section.val() || '',
                month: $('#ad_month').val() || '',
                threshold: $('#ad_threshold').val() || '75',
                search: currentSearch()
            };
        }
        function syncExports(p) {
            var q = {};
            $.each(p, function (k, v) { if (v !== '') { q[k] = v; } });
            $('.ad-export-link').each(function () {
                $(this).attr('href', $(this).attr('data-base') + '?' + $.param(q));
            });
        }
        function refresh() {
            var p = params(), my = ++seq;
            syncExports(p);
            $('#ad-updating').show();
            $.getJSON(resultsUrl, p).done(function (d) {
                if (my !== seq) { return; }
                $('#ad-stats').html(d.stats);
                $('#ad-results').html(d.results);
                $('#ad-print').html(d.print);
                var $band = $('#ad-band-line');
                if ($band.length) {
                    $band.text(d.band_line);
                    $band.closest('.print-doc-head').toggle(!d.has_rows);
                }
                var $foot = $('#ad-foot-line');
                if ($foot.length) { $foot.text(d.filter_line); }
            }).always(function () {
                if (my === seq) { $('#ad-updating').hide(); }
            });
        }
        $class.on('change', function () {
            var cid = $class.val();
            $section.prop('disabled', true);
            if (!cid) {
                $section.empty().append('<option value="">All Sections</option>');
                refresh();
                return;
            }
            $.getJSON(sectionsUrl, { class_id: cid }).done(function (list) {
                $section.empty().append('<option value="">All Sections</option>');
                $.each(list, function (i, s) {
                    $section.append($('<option>', { value: s.id, text: s.name }));
                });
                $section.prop('disabled', false);
            }).always(refresh);
        });
        $section.on('change', refresh);
        $('#ad_month, #ad_threshold').on('change', refresh);
        $(document).on('submit', '.l117-table-search', function (e) {
            e.preventDefault();
            refresh();
        });
        $('#ad-filter-form').on('submit', function (e) { e.preventDefault(); refresh(); });
        $('#ad-reset').on('click', function (e) {
            e.preventDefault();
            $class.val('');
            $section.empty().append('<option value="">All Sections</option>').prop('disabled', true);
            $('#ad_month').val('{{ now()->format('Y-m') }}');
            $('#ad_threshold').val('75');
            refresh();
        });
        syncExports(params());
    });
</script>
@endsection
