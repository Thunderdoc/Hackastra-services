@extends('layouts.master')
@section('page_title', 'Manage Timetables')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; Timetables
@endsection
@if(Qs::userIsTeamSAWrite())
@section('page_actions')
    <a href="{{ route('tt.generate.form') }}" class="btn btn-success"><i class="icon-magic-wand mr-1"></i> Auto-Generate</a>
    <a href="#tt-create" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> Create Timetable</a>
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

    <p class="ma-intro">View and manage class timetables for the current academic session.</p>

    <div class="row dashboard-stats">
        @foreach([
            ['label' => 'Total Timetables', 'value' => $stats['total'], 'sub' => '', 'icon' => 'icon-table2', 'tone' => 'blue'],
            ['label' => 'Active Timetables', 'value' => $stats['active'], 'sub' => '', 'icon' => 'icon-checkmark-circle2', 'tone' => 'green'],
            ['label' => 'Classes', 'value' => $stats['classes'], 'sub' => '', 'icon' => 'icon-users', 'tone' => 'purple'],
            ['label' => 'Current Session', 'value' => $stats['session'], 'sub' => '', 'icon' => 'icon-calendar52', 'tone' => 'orange'],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                    <span class="dashboard-stat-top">
                        <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                    </span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                        <strong>{{ $stat['value'] }}</strong>
                        <small>{{ $stat['sub'] }}</small>
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="pat-filters">
        <form method="get" action="{{ route('tt.index') }}" class="pat-filter-form">
            <div class="pat-filter-field" style="flex:2 1 240px">
                <label for="tt-q">Search</label>
                <input type="text" id="tt-q" name="q" value="{{ $f_q }}" class="form-control" placeholder="Search timetables by class name&hellip;">
            </div>
            <div class="pat-filter-field">
                <label for="tt-class">Class</label>
                <select id="tt-class" name="class_id" class="form-control">
                    <option value="">All Classes</option>
                    @foreach($my_classes as $mc)
                        <option value="{{ $mc->id }}" {{ (string) $f_class === (string) $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-filter-field">
                <label for="tt-year">Year</label>
                <select id="tt-year" name="year" class="form-control">
                    <option value="">All Years</option>
                    @foreach($years as $y)
                        <option value="{{ $y }}" {{ (string) $f_year === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-filter-action">
                <button class="btn btn-primary" type="submit"><i class="icon-filter3 mr-1"></i> Filter</button>
                <a href="{{ route('tt.index') }}" class="btn btn-light"><i class="icon-reset mr-1"></i> Reset</a>
            </div>
        </form>
    </div>

    @php
        $ttFiltersActive = $f_q !== '' || ($f_class !== null && $f_class !== '') || ($f_year !== null && $f_year !== '');
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
                <a href="{{ route('tt.generate.form') }}" class="btn btn-success"><i class="icon-magic-wand mr-1"></i> Auto-Generate Timetables</a>
            </div></div>
        @endif

        <div class="ttc-grid">
        @foreach($my_classes as $mc)
            @php
                $records = $tt_records->where('my_class_id', $mc->id)->values();
                $primary = $records->first(function ($r) use ($stats) { return !$r->exam_id && $r->year === $stats['session']; }) ?: $records->first();
                $sections = $sections_by_class[$mc->id] ?? collect();
            @endphp
            @if($records->count() || !$ttFiltersActive)
                @php $ttVisibleGroups++; @endphp
                <div class="card ttc-card">
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
                        @php $rs = $record_stats[$ttr->id] ?? ['periods' => 0, 'subjects' => 0]; @endphp
                        <div class="ttc-record">
                            <div class="ttc-record-copy">
                                <strong>{{ $ttr->name }}</strong>
                                <span class="ttc-record-meta">{{ $ttr->exam_id ? $ttr->exam->name : 'Class TimeTable' }} · {{ $ttr->year }} · {{ $rs['periods'] }} periods · {{ $rs['subjects'] }} subjects</span>
                            </div>
                            @if($ttr->year === $stats['session'])
                                <span class="l81-status l81-status-active"><span class="l81-dot"></span> Active</span>
                            @else
                                <span class="l81-status l81-status-past"><span class="l81-dot"></span> Past Session</span>
                            @endif
                            <div class="ttx-actions l81-actions">
                                <a href="{{ route('ttr.show', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                <a target="_blank" href="{{ route('ttr.print', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Print</a>
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
                        <div class="ttc-empty">No timetable yet for this class.</div>
                    @endforelse
                    </div>
                </div>
            @endif
        @endforeach
        </div>

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
    <div id="tt-create">
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
                    <select required data-placeholder="Select Class" class="form-control select" name="my_class_id" id="my_class_id">
                        @foreach($my_classes as $mc)
                            <option {{ old('my_class_id') == $mc->id ? 'selected' : '' }} value="{{ $mc->id }}">{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="exam_id">Type (Class or Exam)</label>
                    <select class="select form-control" name="exam_id" id="exam_id">
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
