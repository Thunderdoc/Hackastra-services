@extends('layouts.master')
@section('page_title', 'Student Information – '.$my_class->name)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('students.index') }}">Students</a> &nbsp;/&nbsp; Student Information
@endsection
@section('content')

    {{-- Loop 31 (Fix 4): print-only document header/footer — hidden on
         screen (Loop 31 CSS), shown by window.print() so the student
         list prints as a professional school document. --}}
    {{-- Document design v2 (Workstream DB, Loop 71): the head carries
         the uploaded logo, or a navy monogram tile when none is
         uploaded (never the theme's light fallback logo), and the
         page carries the watermark — logo or app-code monogram —
         behind the content. --}}
    @php
        $dbInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $dbWord) {
            if ($dbWord !== '') {
                $dbInitials .= strtoupper(substr($dbWord, 0, 1));
                if (strlen($dbInitials) >= 2) { break; }
            }
        }
        if ($dbInitials === '') { $dbInitials = 'S'; }
    @endphp
    <div class="print-doc-head">
        @if(Qs::getSetting('logo'))
            <img src="{{ Qs::getSetting('logo') }}" alt="">
        @else
            <span class="print-doc-mono" aria-hidden="true">{{ $dbInitials }}</span>
        @endif
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Student Information &mdash; {{ $my_class->name }} &middot; Session: {{ Qs::getCurrentSession() }} &middot; Total Students: {{ $students->count() }}</p>
        </div>
    </div>
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    {{-- Loop 83: stat cards describe $listed — the set the user is
         actually looking at (full roster when unfiltered, the
         filtered set when a section/search is active). Gender counts
         use only records whose gender is stored; when none is
         recorded the cards say so instead of inventing a split. --}}
    @php
        $l83Total = $listed->count();
        $l83Boys = 0; $l83Girls = 0; $l83GenderRecorded = 0;
        foreach ($listed as $l83s) {
            $l83g = strtolower(trim((string) optional($l83s->user)->gender));
            if ($l83g !== '') { $l83GenderRecorded++; }
            if ($l83g === 'male') { $l83Boys++; }
            if ($l83g === 'female') { $l83Girls++; }
        }
        $l83BoysPct = $l83GenderRecorded ? number_format($l83Boys / $l83GenderRecorded * 100, 1) : null;
        $l83GirlsPct = $l83GenderRecorded ? number_format($l83Girls / $l83GenderRecorded * 100, 1) : null;
        $l83SectionNames = [];
        foreach ($sections as $l83sec) {
            if ($listed->contains('section_id', $l83sec->id)) { $l83SectionNames[] = $l83sec->name; }
        }
        $l83GenderSub = function ($pct) use ($l83GenderRecorded, $l83Total) {
            if ($pct === null) { return 'Gender not recorded'; }
            return $pct.'%'.($l83GenderRecorded < $l83Total ? ' of '.$l83GenderRecorded.' recorded' : '');
        };
        $l83PrintQuery = array_filter([
            'section_id' => $selected_section ? $selected_section->id : null,
            'q' => $q !== '' ? $q : null,
            'sort' => $sort !== 'name_asc' ? $sort : null,
            'print' => 1,
        ]);
        $l83PrintUrl = route('students.list', $my_class->id).'?'.http_build_query($l83PrintQuery);
        $l83ExportFiltered = route('students.export', $selected_section ? [$my_class->id, $selected_section->id] : $my_class->id).($q !== '' ? '?q='.urlencode($q) : '');
        $l83ViewBase = request()->query();
        unset($l83ViewBase['page']);
    @endphp

    {{-- Batch 3B: rebuilt on the shared list_page pattern (Loop 28).
         Loop 83 (redesign pass): premium header actions, stat cards,
         sort + list/grid toolbar, server pagination, gender column,
         initials-or-photo avatars (standing photo rule), and a
         print mode (?print=1) that renders the FULL filtered set so
         the printed document always matches the active filters. The
         print contract is unchanged — print-doc-head/foot above and
         below, print-hide on photo/action/checkbox columns,
         print-nowrap on S/N + ADM_No — and the bulk-delete bar +
         script remain the Workstream AA originals, with the
         confirmation flowing through the styled modal helper
         (Workstream AY) exactly as on master. --}}
    <div class="l83-wrap">
    @component('partials.patterns.list_page')
        @slot('subtitle')
            View and manage all students in {{ $my_class->name }}. Total {{ $students->count() }} {{ $students->count() == 1 ? 'student' : 'students' }} &middot; Session {{ Qs::getCurrentSession() }}@if($selected_section || $q !== '') &middot; Showing {{ $listed->count() }}@endif
        @endslot
        @slot('action')
            <a href="{{ $l83PrintUrl }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Print</a>
            <div class="btn-group">
                <button type="button" class="btn btn-light btn-sm dropdown-toggle" data-toggle="dropdown"><i class="icon-download mr-1"></i> Export</button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="{{ route('students.export', $my_class->id) }}"><i class="icon-file-text2"></i> CSV — Whole class</a>
                    <a class="dropdown-item" href="{{ $l83ExportFiltered }}"><i class="icon-file-text2"></i> CSV — Current filter</a>
                </div>
            </div>
            @if(Qs::userIsTeamSAWrite())
                <a href="{{ route('students.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus3 mr-1"></i> Add Student</a>
                <a href="{{ route('students.import') }}" class="btn btn-success btn-sm"><i class="icon-upload mr-1"></i> Import CSV</a>
            @endif
        @endslot
        @slot('stats')
            <div class="row dashboard-stats">
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-blue">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-users"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Total Students</span>
                            <strong>{{ number_format($l83Total) }}</strong>
                            <small>{{ ($selected_section || $q !== '') ? 'Matching current filter' : 'in '.$my_class->name }}</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-green">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-man"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Boys</span>
                            <strong>{{ $l83GenderRecorded ? number_format($l83Boys) : '—' }}</strong>
                            <small>{{ $l83GenderSub($l83BoysPct) }}</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-pink">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-woman"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Girls</span>
                            <strong>{{ $l83GenderRecorded ? number_format($l83Girls) : '—' }}</strong>
                            <small>{{ $l83GenderSub($l83GirlsPct) }}</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-orange">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-graduation2"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Sections</span>
                            <strong>{{ number_format(count($l83SectionNames)) }}</strong>
                            <small>{{ $l83SectionNames ? implode(', ', $l83SectionNames) : 'No sections' }}</small>
                        </span>
                    </div>
                </div>
            </div>
        @endslot
        @slot('filters')
            <form method="get" action="{{ route('students.list', $my_class->id) }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="section_id">Section</label>
                    <select id="section_id" name="section_id" class="form-control">
                        <option value="">All Sections</option>
                        @foreach($sections as $s)
                            <option {{ $selected_section && $selected_section->id == $s->id ? 'selected' : '' }} value="{{ $s->id }}">{{ $my_class->name.' '.$s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="q">Search</label>
                    <input id="q" name="q" type="text" class="form-control" value="{{ $q }}" placeholder="Search by name, ADM No or email&hellip;">
                </div>
                @if($sort !== 'name_asc')<input type="hidden" name="sort" value="{{ $sort }}">@endif
                @if($view_mode === 'grid')<input type="hidden" name="view" value="grid">@endif
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    @if($selected_section || $q !== '')
                        <a href="{{ route('students.list', $my_class->id) }}" class="btn btn-light ml-2">Reset</a>
                    @endif
                </div>
            </form>
        @endslot

        @if(Qs::userIsTeamSAWrite())
            {{-- Bulk delete (Workstream AA). The form deliberately does
                 NOT wrap the table: every row already contains its own
                 (hidden) delete form, and nested forms are invalid HTML.
                 The checkboxes carry no name — the script section collects
                 the checked ids and appends them to this form as
                 student_ids[] on submit, after the styled confirmation
                 modal (Workstream AY) approves. --}}
            <form id="bulk-delete-form" class="print-hide" method="post" action="{{ route('students.bulk_delete') }}">
                @csrf
                <div class="d-flex align-items-center mb-2">
                    <span id="bulk-selected-count" class="mr-2 text-muted">0 selected</span>
                    <button type="submit" id="bulk-delete-btn" class="btn btn-danger btn-sm" disabled><i class="icon-trash mr-1"></i> Delete Selected</button>
                </div>
            </form>
        @endif

        <div id="all-students">
            @if($listed->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => ($selected_section || $q !== '')
                        ? 'No students match this filter. Clear the filter to see the full '.$my_class->name.' roster.'
                        : 'No students in '.$my_class->name.' yet.',
                ])
            @else
                {{-- Loop 83: table toolbar — result count, sort, view toggle. --}}
                <div class="l83-toolbar print-hide">
                    <div class="l83-toolbar-title"><i class="icon-users mr-2"></i> Students ({{ $listed->count() }})</div>
                    <div class="l83-toolbar-controls">
                        @if(Qs::userIsTeamSAWrite() && $view_mode === 'grid' && !$print_mode)
                            <label class="l83-checkall"><input type="checkbox" id="bulk-check-all"> Select page</label>
                        @endif
                        <form method="get" action="{{ route('students.list', $my_class->id) }}" class="l83-view-form">
                            @if($selected_section)<input type="hidden" name="section_id" value="{{ $selected_section->id }}">@endif
                            @if($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
                            @if($view_mode === 'grid')<input type="hidden" name="view" value="grid">@endif
                            <label for="l83-sort">Sort by</label>
                            <select id="l83-sort" name="sort" class="form-control" onchange="this.form.submit()">
                                <option value="name_asc" {{ $sort === 'name_asc' ? 'selected' : '' }}>Name A – Z</option>
                                <option value="name_desc" {{ $sort === 'name_desc' ? 'selected' : '' }}>Name Z – A</option>
                                <option value="adm_no" {{ $sort === 'adm_no' ? 'selected' : '' }}>Admission No.</option>
                            </select>
                        </form>
                        @if(!$print_mode)
                            <div class="btn-group l83-view-toggle" role="group" aria-label="View mode">
                                <a href="{{ route('students.list', $my_class->id).'?'.http_build_query(array_merge($l83ViewBase, ['view' => 'list'])) }}" class="btn btn-light btn-sm {{ $view_mode === 'list' ? 'active' : '' }}" title="List view"><i class="icon-list"></i></a>
                                <a href="{{ route('students.list', $my_class->id).'?'.http_build_query(array_merge($l83ViewBase, ['view' => 'grid'])) }}" class="btn btn-light btn-sm {{ $view_mode === 'grid' ? 'active' : '' }}" title="Grid view"><i class="icon-grid6"></i></a>
                            </div>
                        @endif
                    </div>
                </div>

                @if($print_mode || $view_mode === 'list')
                    <table class="table">
                        <thead>
                        <tr>
                            @if(Qs::userIsTeamSAWrite())
                                <th class="print-hide"><input type="checkbox" id="bulk-check-all" title="Select all on this page"></th>
                            @endif
                            <th class="print-nowrap">S/N</th>
                            <th class="print-hide">Photo</th>
                            <th>Name</th>
                            <th>ADM_No</th>
                            <th>Gender</th>
                            <th>Section</th>
                            <th>Email</th>
                            <th class="print-hide">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach(($print_mode ? $listed : $paged) as $s)
                            @php
                                $l83Words = preg_split('/\s+/', trim((string) $s->user->name));
                                $l83Ini = strtoupper(substr($l83Words[0] ?? '', 0, 1).(isset($l83Words[1]) ? substr($l83Words[1], 0, 1) : ''));
                                $l83Gender = strtolower(trim((string) $s->user->gender));
                            @endphp
                            <tr>
                                @if(Qs::userIsTeamSAWrite())
                                    <td class="print-hide"><input type="checkbox" class="bulk-check" value="{{ $s->id }}"></td>
                                @endif
                                <td class="print-nowrap">{{ $print_mode ? $loop->iteration : $paged->firstItem() + $loop->index }}</td>
                                <td class="print-hide"><span class="l7-avatar l7-avatar-md" aria-hidden="true">{{ $l83Ini }}@if(Qs::hasRealPhoto($s->user->photo))<img src="{{ $s->user->photo }}" alt="" onerror="this.remove()">@endif</span></td>
                                <td>{{ $s->user->name }}</td>
                                <td class="print-nowrap">{{ $s->adm_no }}</td>
                                <td>@if($l83Gender === 'male')<span class="l83-pill l83-pill-male">Male</span>@elseif($l83Gender === 'female')<span class="l83-pill l83-pill-female">Female</span>@elseif($l83Gender !== '')<span class="l83-pill">{{ ucfirst($s->user->gender) }}</span>@else &mdash; @endif</td>
                                <td><span class="l83-chip">{{ $my_class->name.' '.$s->section->name }}</span></td>
                                <td class="l83-email" title="{{ $s->user->email }}">{{ $s->user->email ?: '—' }}</td>
                                <td class="text-center print-hide">
                                    @include('pages.support_team.students.partials.row_actions', ['s' => $s])
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="row l83-grid">
                        @foreach($paged as $s)
                            @php
                                $l83Words = preg_split('/\s+/', trim((string) $s->user->name));
                                $l83Ini = strtoupper(substr($l83Words[0] ?? '', 0, 1).(isset($l83Words[1]) ? substr($l83Words[1], 0, 1) : ''));
                                $l83Gender = strtolower(trim((string) $s->user->gender));
                            @endphp
                            <div class="col-sm-6 col-lg-4 col-xl-3">
                                <div class="l83-card">
                                    @if(Qs::userIsTeamSAWrite())
                                        <label class="l83-card-check"><input type="checkbox" class="bulk-check" value="{{ $s->id }}"> Select</label>
                                    @endif
                                    <span class="l7-avatar l83-avatar-lg" aria-hidden="true">{{ $l83Ini }}@if(Qs::hasRealPhoto($s->user->photo))<img src="{{ $s->user->photo }}" alt="" onerror="this.remove()">@endif</span>
                                    <a class="l83-card-name" href="{{ route('students.show', Qs::hash($s->id)) }}">{{ $s->user->name }}</a>
                                    <div class="l83-card-adm">{{ $s->adm_no }}</div>
                                    <div class="l83-card-chips">
                                        <span class="l83-chip">{{ $my_class->name.' '.$s->section->name }}</span>
                                        @if($l83Gender === 'male')<span class="l83-pill l83-pill-male">Male</span>@elseif($l83Gender === 'female')<span class="l83-pill l83-pill-female">Female</span>@endif
                                    </div>
                                    <div class="l83-card-email" title="{{ $s->user->email }}">{{ $s->user->email ?: '—' }}</div>
                                    <div class="l83-card-actions">
                                        <a href="{{ route('students.show', Qs::hash($s->id)) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                        @include('pages.support_team.students.partials.row_actions', ['s' => $s])
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if(!$print_mode && $paged->lastPage() > 1)
                    @php
                        $l83Cur = $paged->currentPage(); $l83Last = $paged->lastPage();
                        $l83From = max(1, $l83Cur - 2); $l83To = min($l83Last, $l83Cur + 2);
                    @endphp
                    <div class="l83-pagination print-hide">
                        <span class="l83-pagination-info">Showing {{ $paged->firstItem() }} to {{ $paged->lastItem() }} of {{ $paged->total() }} students</span>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item {{ $l83Cur <= 1 ? 'disabled' : '' }}"><a class="page-link" href="{{ $l83Cur > 1 ? $paged->url($l83Cur - 1) : '#' }}">&laquo; Previous</a></li>
                            @if($l83From > 1)
                                <li class="page-item"><a class="page-link" href="{{ $paged->url(1) }}">1</a></li>
                                @if($l83From > 2)<li class="page-item disabled"><span class="page-link">&hellip;</span></li>@endif
                            @endif
                            @for($l83i = $l83From; $l83i <= $l83To; $l83i++)
                                <li class="page-item {{ $l83i === $l83Cur ? 'active' : '' }}"><a class="page-link" href="{{ $paged->url($l83i) }}">{{ $l83i }}</a></li>
                            @endfor
                            @if($l83To < $l83Last)
                                @if($l83To < $l83Last - 1)<li class="page-item disabled"><span class="page-link">&hellip;</span></li>@endif
                                <li class="page-item"><a class="page-link" href="{{ $paged->url($l83Last) }}">{{ $l83Last }}</a></li>
                            @endif
                            <li class="page-item {{ $l83Cur >= $l83Last ? 'disabled' : '' }}"><a class="page-link" href="{{ $l83Cur < $l83Last ? $paged->url($l83Cur + 1) : '#' }}">Next &raquo;</a></li>
                        </ul>
                    </div>
                @elseif(!$print_mode)
                    <div class="l83-pagination print-hide">
                        <span class="l83-pagination-info">Showing {{ $paged->firstItem() }} to {{ $paged->lastItem() }} of {{ $paged->total() }} students</span>
                    </div>
                @endif
            @endif
        </div>
    @endcomponent
    </div>

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Student Information &mdash; {{ $my_class->name }} &middot; Generated by Hackstra</span>
    </div>

    {{--Student List Ends--}}

@endsection

@section('scripts')
@if($print_mode ?? false)
{{-- Loop 111 (owner order 2026-10-23): this document prints on ONE
     A4 LANDSCAPE page. The scoped @page below belongs to this print
     view only — every other document keeps its own page setup. It
     also overrides the two global @page traps that produced the
     owner's phantom second page on his phone: Bootstrap's print
     block (@page size a3 + body min-width 992px) and the ID-card
     block's global size:auto. --}}
<style>
    @page { size: A4 landscape; margin: 10mm; }
    @media print {
        body { min-width: 0 !important; }
    }
</style>
<script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 350);
        });
</script>
@endif
@if(Qs::userIsTeamSAWrite())
<script>
        (function () {
            function bulkTable() {
                try {
                    var $t = $('#all-students table.datatable-button-html5-columns');
                    return ($.fn.DataTable && $.fn.DataTable.isDataTable($t)) ? $t.DataTable() : null;
                } catch (e) { return null; }
            }
            function checkedBoxes() {
                var dt = bulkTable();
                return dt ? dt.$('input.bulk-check:checked') : $('#all-students input.bulk-check:checked');
            }
            function allBoxes() {
                var dt = bulkTable();
                return dt ? dt.$('input.bulk-check') : $('#all-students input.bulk-check');
            }
            function refreshCount() {
                var n = checkedBoxes().length;
                $('#bulk-selected-count').text(n + ' selected');
                $('#bulk-delete-btn').prop('disabled', n === 0);
            }
            $(document).on('change', '#all-students input.bulk-check', refreshCount);
            $(document).on('change', '#bulk-check-all', function () {
                allBoxes().prop('checked', this.checked);
                refreshCount();
            });
            $(document).on('draw.dt', '#all-students table.datatable-button-html5-columns', refreshCount);
            $('#bulk-delete-form').on('submit', function (e) {
                var form = this;
                var ids = [];
                checkedBoxes().each(function () { ids.push(this.value); });
                if (!ids.length) { e.preventDefault(); return; }
                if (form.dataset.hxcConfirmed !== '1') {
                    e.preventDefault();
                    window.hackstraConfirm('Delete ' + ids.length + ' selected student(s)? Their login accounts will be removed too. This cannot be undone.', { title: 'Delete Students', action: 'Delete', danger: true }).then(function (ok) {
                        if (!ok) { return; }
                        form.dataset.hxcConfirmed = '1';
                        if (form.requestSubmit) { form.requestSubmit(); }
                        else {
                            $(form).find('input[type=hidden][data-bulk-id]').remove();
                            ids.forEach(function (id) {
                                $('<input>').attr({ type: 'hidden', name: 'student_ids[]', value: id, 'data-bulk-id': '1' }).appendTo(form);
                            });
                            form.submit();
                        }
                    });
                    return;
                }
                delete form.dataset.hxcConfirmed;
                $(form).find('input[type=hidden][data-bulk-id]').remove();
                ids.forEach(function (id) {
                    $('<input>').attr({ type: 'hidden', name: 'student_ids[]', value: id, 'data-bulk-id': '1' }).appendTo(form);
                });
            });
            refreshCount();
        })();
</script>
@endif
@endsection
