@extends('layouts.master')
@section('page_title', 'Manage Timetables')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; Timetables
@endsection
@if(Qs::userIsTeamSAWrite())
@section('page_actions')
    <a href="{{ route('tt.generate.form') }}" class="btn btn-primary"><i class="icon-magic-wand mr-1"></i> Auto-Generate</a>
    <a href="{{ route('rooms.index') }}" class="btn btn-light"><i class="icon-home4 mr-1"></i> Rooms &amp; Labs</a>
    <a href="#tt-create" class="btn btn-light"><i class="icon-plus2 mr-1"></i> Create Timetable</a>
@endsection
@endif
@section('content')

    {{-- Loop 81 — Manage Timetables redesign: summary cards, a real
         server-side filter toolbar (search / class / year), and the
         class groups as collapsible cards. Status is DERIVED — the
         records table has no status column: a record is "Active"
         when its year is the current session, else "Past Session".
         Data + routes are the pre-redesign ones; writes stay
         teamSAWrite-gated exactly as before. --}}

    {{-- Item 3 visual pass (owner-approved tt-v3, site style) built
         this slim headbar; Beauty Wave 1 (2026-11-06) formalises it
         into the SHARED slim module banner (partials/module_banner):
         same pastel icon, same role-aware subtitle, the SAME $stats
         figures (scoped for teachers by the controller) and the
         session chip. No new data. The right side keeps the
         tt-index-stats class so the phone strip rule below still
         hides it on small screens. --}}
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-table2',
        'hkModTone' => 'blue',
        'hkModSub' => Qs::userIsTeacher() ? 'Showing only the classes you teach in' : 'View and manage class timetables for the current academic session.',
        'hkModStats' => [
            ['value' => $stats['classes'], 'label' => Qs::userIsTeacher() ? 'My Classes' : 'Classes'],
            ['value' => $stats['sections'], 'label' => 'Sections'],
            ['value' => $stats['periods'], 'label' => 'Periods / Week'],
        ],
        'hkModChip' => 'Session ' . $stats['session'],
        'hkModRightClass' => 'tt-index-stats',
    ])

    {{-- Batch 2026-10-26 (admin phone UX): on a phone the four
         summary cards stacked into a full screen of stats before
         a single timetable appeared — the list is the page, not
         the stats. Phones get one slim strip with the same four
         numbers; desktops keep the cards unchanged. --}}
    <style>
        .tt-stats-strip { display: none; }
        @media (max-width: 767px) {
            .tt-index-stats { display: none; }
            .tt-stats-strip { display: block; }
        }
    </style>
    <div class="card tt-stats-strip"><div class="card-body py-2">
        <span class="text-muted">{{ $stats['classes'] }} classes &middot; {{ $stats['sections'] }} sections &middot; {{ $stats['periods'] }} periods/week across {{ $stats['total'] }} timetables &middot; {{ $stats['session'] }}</span>
    </div></div>

    {{-- Parity (tt-v3 sample, 2026-11-01): the filter bar carries
         ONLY Class + Section and applies on change — no Search, no
         Year, no Filter/Reset buttons. The controller still honours
         q/year when they arrive in a URL; they are simply no longer
         offered here. Once a class is chosen, the controller narrows
         the Section options to that class. --}}
    <div class="pat-filters">
        <form method="get" action="{{ route('tt.index') }}" class="pat-filter-form">
            <div class="pat-filter-field">
                <label for="tt-class">Class</label>
                <select id="tt-class" name="class_id" class="form-control" data-placeholder="{{ Qs::userIsTeacher() ? 'All my classes' : 'All Classes' }}" onchange="document.getElementById('tt-section').value=''; this.form.submit()">
                    <option value="">{{ Qs::userIsTeacher() ? 'All my classes' : 'All Classes' }}</option>
                    @foreach($my_classes as $mc)
                        <option value="{{ $mc->id }}" {{ (string) $f_class === (string) $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-filter-field">
                <label for="tt-section">Section</label>
                <select id="tt-section" name="section_id" class="form-control" data-placeholder="{{ $f_class ? 'All Sections' : 'Select a class first' }}" onchange="this.form.submit()">
                    <option value="">{{ $f_class ? 'All Sections' : 'Select a class first' }}</option>
                    @foreach($filter_sections as $sec)
                        <option value="{{ $sec->id }}" data-class="{{ $sec->my_class_id }}" {{ (string) $f_section === (string) $sec->id ? 'selected' : '' }}>{{ $sec->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    @php
        $ttFiltersActive = $f_q !== '' || ($f_class !== null && $f_class !== '') || ($f_year !== null && $f_year !== '') || ($f_section !== null && $f_section !== '');
        $ttVisibleGroups = 0;
    @endphp

    {{-- Owner redesign (2026-10-23): CLASS CARDS — every class is
         a card with its sections and timetable state; clicking a
         class opens its timetable. Records (class + exam) list
         inside the card with all their existing actions. --}}
    @if($my_classes->count() === 0)
        <div class="card"><div class="card-body">
            <div class="ttx-empty-state">
                <i class="icon-table2"></i>
                <p>No classes exist yet — add classes first, then generate their timetables.</p>
            </div>
        </div></div>
    @else
        @if($tt_records_all->count() === 0 && Qs::userIsTeamSAWrite())
            <div class="card ttc-cta"><div class="card-body">
                <div class="ttc-cta-copy">
                    <strong>No timetables yet.</strong>
                    <span>Let the system build every class timetable for you — you only tell it the school hours, lunch and breaks.</span>
                </div>
                <a href="{{ route('tt.generate.form') }}" class="btn btn-primary"><i class="icon-magic-wand mr-1"></i> Auto-Generate Timetables</a>
            </div></div>
        @endif

        @php $ttLedClassIds = Qs::userIsTeacher() ? \App\Models\Section::where('teacher_id', Auth::id())->pluck('my_class_id')->unique()->all() : []; @endphp
        <div class="ttc-grid{{ Qs::userIsTeacher() ? ' tt3-teacher' : '' }}">
        @foreach($my_classes as $mc)
            @if($list_class_id && (int) $mc->id === (int) $list_class_id) @continue @endif
            @php
                $records = $tt_records->where('my_class_id', $mc->id)->values();
                $primary = $records->first(function ($r) use ($stats) { return !$r->exam_id && $r->year === $stats['session'] && !$r->section_id; }) ?: $records->first(function ($r) use ($stats) { return !$r->exam_id && $r->year === $stats['session']; }) ?: $records->first();
                $sections = $chip_sections_by_class[$mc->id] ?? collect();
            @endphp
            @if($records->count() || !$ttFiltersActive)
                @php $ttVisibleGroups++; @endphp
                <div class="card ttc-card">
                    <div class="ttc-head">
                        <span class="l81-group-tile"><i class="icon-graduation2"></i></span>
                        <div class="ttc-head-copy">
                            <span class="ttc-name">{{ $mc->name }}</span>
                            @if(Qs::userIsTeacher())
                                <span class="ttc-sub">{{ in_array($mc->id, $ttLedClassIds) ? 'You are class teacher' : 'Your sections only' }}</span>
                            @else
                                <span class="ttc-sub">{{ $records->count() }} timetable{{ $records->count() === 1 ? '' : 's' }} · {{ $sections->count() }} section{{ $sections->count() === 1 ? '' : 's' }}</span>
                            @endif
                        </div>
                    </div>
                    @php
                        $ttCardPeriods = 0; $ttCardSubjects = 0;
                        foreach ($records as $ttRec) { $ttRs = $record_stats[$ttRec->id] ?? ['periods' => 0, 'subjects' => 0]; $ttCardPeriods += $ttRs['periods']; $ttCardSubjects += $ttRs['subjects']; }
                        $ttActiveCount = $records->where('year', $stats['session'])->count();
                    @endphp
                    <div class="tt3-counts">
                        <div><b>{{ $records->count() }}</b><span>Timetables</span></div>
                        <div><b>{{ $ttCardPeriods }}</b><span>Periods</span></div>
                        <div><b>{{ $ttCardSubjects }}</b><span>Subjects</span></div>
                    </div>
                    @if($sections->count())
                        {{-- Beauty Batch 3 (audit B13): chips cap at
                             one row (6 + a "+N more" marker) so a
                             13-section class no longer makes its card
                             taller than its row neighbours. The full
                             list stays on the single-class view. --}}
                        <div class="ttc-sections">
                            @foreach($sections->take(6) as $sec)<span class="ttc-chip">{{ $sec->name }}</span>@endforeach
                            @if($sections->count() > 6)<span class="ttc-chip ttc-chip-more">+{{ $sections->count() - 6 }} more</span>@endif
                        </div>
                    @endif
                    @if($primary)
                        <a href="{{ route('ttr.show', $primary->id) }}" class="btn btn-primary tt3-open">Open Timetable</a>
                    @elseif(Qs::userIsTeamSAWrite())
                        <a href="{{ route('tt.generate.form', ['class_id' => $mc->id]) }}" class="btn btn-success tt3-open"><i class="icon-magic-wand mr-1"></i> Generate</a>
                    @endif
                    <div class="tt3-foot"><span class="tt3-dot"></span>@if($records->count() === 0) No timetable yet @elseif($ttActiveCount === $records->count()) All active @else {{ $ttActiveCount }} active · {{ $records->count() - $ttActiveCount }} past session @endif</div>
                </div>
            @endif
        @endforeach
        </div>

        {{-- Filtered single-class view (owner, 2026-11-01): the
             chosen class renders full-width as a LIST with its
             complete record rows and every action; the grid above
             carries summary cards only, so no card can outgrow the
             others. --}}
        @if($list_class_id)
            @foreach($my_classes as $mc)
                @if((int) $mc->id === (int) $list_class_id)
                    @php
                        $records = $tt_records->where('my_class_id', $mc->id)->values();
                        $primary = $records->first(function ($r) use ($stats) { return !$r->exam_id && $r->year === $stats['session'] && !$r->section_id; }) ?: $records->first(function ($r) use ($stats) { return !$r->exam_id && $r->year === $stats['session']; }) ?: $records->first();
                        $sections = $chip_sections_by_class[$mc->id] ?? collect();
                        $ttVisibleGroups++;
                    @endphp
                    <div class="card ttc-list-card">
                    <div class="ttc-head">
                        <span class="l81-group-tile"><i class="icon-graduation2"></i></span>
                        <div class="ttc-head-copy">
                            <span class="ttc-name">{{ $mc->name }}</span>
                            <span class="ttc-sub">{{ $records->count() }} timetable{{ $records->count() === 1 ? '' : 's' }} · {{ $sections->count() }} section{{ $sections->count() === 1 ? '' : 's' }}</span>
                        </div>
                        @if($primary)
                            <a href="{{ route('ttr.show', $primary->id) }}" class="btn btn-primary btn-sm">Open Timetable</a>
                        @elseif(Qs::userIsTeamSAWrite())
                            <a href="{{ route('tt.generate.form', ['class_id' => $mc->id]) }}" class="btn btn-success btn-sm"><i class="icon-magic-wand mr-1"></i> Generate</a>
                        @endif
                    </div>
                    @if($sections->count())
                        <div class="ttc-sections">
                            @foreach($sections as $sec)<span class="ttc-chip">{{ $sec->name }}</span>@endforeach
                        </div>
                    @endif
                    <div class="ttc-records">
                    @forelse($records as $ttr)
                        @php $rs = $record_stats[$ttr->id] ?? ['periods' => 0, 'subjects' => 0, 'free' => 0]; @endphp
                        <div class="ttc-record">
                            <div class="ttc-record-copy">
                                <strong>{{ $ttr->name }}</strong>
                                <span class="ttc-record-meta">@if($ttr->section_id) Section {{ $ttr->section->name ?? '' }} · @endif{{ $ttr->exam_id ? $ttr->exam->name : 'Class TimeTable' }} · {{ $ttr->year }} · {{ $rs['periods'] }} periods · {{ $rs['subjects'] }} subjects · {{ $rs['free'] }} free</span>
                            </div>
                            @if($ttr->year === $stats['session'])
                                <span class="l81-status l81-status-active"><span class="l81-dot"></span> Active</span>
                            @else
                                <span class="l81-status l81-status-past"><span class="l81-dot"></span> Past Session</span>
                            @endif
                            {{-- Wave 7 — owner order 2026-10-25: a clean
                                 validation turns the timetable green
                                 here. Live-computed, admin-only, and
                                 only for the current session — a past
                                 record never claims a fresh green. --}}
                            @if(Qs::userIsTeamSAWrite() && $ttr->year === $stats['session'] && in_array($ttr->id, $validated_ids ?? []))
                                <span class="l81-status l81-status-validated"><i class="icon-checkmark-circle"></i> Validated</span>
                            @endif
                            {{-- Timetable approval state (owner order
                                 2026-10-29): only the office roles see
                                 it — teachers/students only ever see
                                 in-force timetables here at all. --}}
                            @if((Qs::userIsAdmin() || Qs::userIsPrincipal()) && ($ttr->approval_status ?? 'approved') !== 'approved')
                                @if($ttr->approval_status === 'submitted')
                                    <span class="l81-status" style="background:#e8f0fe;color:#1a56db;"><i class="icon-hour-glass"></i> With Principal</span>
                                @else
                                    <span class="l81-status" style="background:#fef3c7;color:#92400e;"><i class="icon-pencil"></i> Draft</span>
                                @endif
                            @endif
                            <div class="ttx-actions l81-actions">
                                <a href="{{ route('ttr.show', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                <a target="_blank" href="{{ route('ttr.print', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Print</a>
                                @if(Qs::userIsTeamSAWrite())
                                <a href="{{ route('ttr.validate', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-checkmark-circle mr-1"></i> Validate</a>
                                @endif
                                @if(Qs::userIsTeamSAWrite())
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-light btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="More actions for {{ $ttr->name }}"><i class="icon-more2"></i></button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            <a class="dropdown-item" href="{{ route('ttr.manage', $ttr->id) }}"><i class="icon-plus-circle2 mr-2"></i> Manage</a>
                                            <a class="dropdown-item" href="{{ route('ttr.edit', $ttr->id) }}"><i class="icon-pencil mr-2"></i> Edit</a>
                                            <a class="dropdown-item text-danger" id="{{ $ttr->id }}" onclick="confirmDelete(this.id)" href="#"><i class="icon-trash mr-2"></i> Delete</a>
                                        </div>
                                    </div>
                                    <form method="post" id="item-delete-{{ $ttr->id }}" action="{{ route('ttr.destroy', $ttr->id) }}" class="hidden">@csrf @method('delete')</form>
                                @endif
                            </div>
                        </div>
                    @empty
                        {{-- Beauty Wave 3: the shared pat-empty
                             pattern with its illustration plate. --}}
                        @include('partials.patterns.empty_state', [
                            'icon' => 'icon-calendar2',
                            'message' => 'No timetable yet for this class.',
                            'hkEmptyArt' => true,
                        ])
                    @endforelse
                    </div>
                    </div>
                @endif
            @endforeach
        @endif

        @if($ttVisibleGroups === 0)
            <div class="card"><div class="card-body">
                <div class="ttx-empty-state">
                    <i class="icon-search4"></i>
                    <p>No timetables match your filters. <a href="{{ route('tt.index') }}">Reset the filters</a> to see all timetables.</p>
                </div>
            </div></div>
        @elseif($ttFiltersActive)
            <p class="text-muted l81-filter-note">Showing {{ $tt_records->count() }} of {{ $stats['total'] }} timetables — filters are on. <a href="{{ route('tt.index') }}">Reset</a></p>
        @endif
    @endif

    @if(Qs::userIsTeamSAWrite())
    {{-- Workstream BW: create form rebuilt on form_page. Same
         .ajax-store POST to ttr.store, same fields/bindings. Loop 81
         moved it below the list and anchored it (#tt-create) so the
         header's Create Timetable button lands on the real flow. --}}
    <div id="tt-create" style="scroll-margin-top:90px; margin-top:20px;">
    @component('partials.patterns.form_page', [
        'form_action' => route('ttr.store'),
        'form_class' => 'ajax-store',
        'submit_label' => 'Create',
    ])
        @slot('title')
            <i class="icon-table2 mr-2"></i> Create Timetable
        @endslot

        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of TimeTable">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class <span class="text-danger">*</span></label>
                    <select required class="form-control" name="my_class_id" id="my_class_id" onchange="getClassSections(this.value, '#section_id')">
                        @foreach($my_classes as $mc)
                            <option {{ old('my_class_id') == $mc->id ? 'selected' : '' }} value="{{ $mc->id }}">{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="section_id">Section</label>
                    <select class="form-control" name="section_id" id="section_id">
                        <option value="">Whole class (all sections)</option>
                        @foreach(($sections_by_class[old('my_class_id') ?: ($my_classes->first()->id ?? null)] ?? collect()) as $sec)
                            <option {{ old('section_id') == $sec->id ? 'selected' : '' }} value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="exam_id">Type (Class or Exam)</label>
                    <select class="form-control" name="exam_id" id="exam_id">
                        <option value="">Class Timetable</option>
                        @foreach($exams as $ex)
                            <option {{ old('exam_id') == $ex->id ? 'selected' : '' }} value="{{ $ex->id }}">{{ $ex->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    @endcomponent
    </div>
    @endif

    {{--TimeTable Ends--}}

@endsection
