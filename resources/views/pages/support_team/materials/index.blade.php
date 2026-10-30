@extends('layouts.master')
@section('page_title', 'Study Materials')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Learning &nbsp;/&nbsp; Study Materials
@endsection
@section('content')
    {{-- Loop 82 (LMS redesign): study materials grouped by subject —
         summary cards + server-side filters (search / subject /
         class / type / sort) over CS1's role-scoped set. Groups are
         collapsible; in the unfiltered view every subject in the
         viewer's scope renders, with an honest empty state where a
         subject has no materials yet. Type follows the model: a
         material is a Document when it carries a file, a Link when
         it carries a URL (both pills if it carries both). There is
         no edit route for materials (CS1) — none is offered.
         NOTE: the duplicate inline flash alerts were removed here —
         the app-wide banner renders each message exactly once. --}}
    @php
        $isTeacher = Qs::userIsTeacher();
        $isAdmin = Qs::userIsAdmin();
        $grouped = $materials->groupBy('subject_id');
        // Ordered group subjects: everything the filtered set holds,
        // plus (unfiltered view only) the viewer's scope subjects so
        // zero-material subjects still render their empty state.
        $groupSubjects = collect();
        foreach ($grouped as $subjectId => $items) {
            $groupSubjects->push($items->first()->subject);
        }
        if (!($filters_active ?? false)) {
            foreach ($scope_subjects ?? [] as $subject) {
                if (!$groupSubjects->contains('id', $subject->id)) {
                    $groupSubjects->push($subject);
                }
            }
        }
        $groupSubjects = $groupSubjects->filter()->sortBy(function ($s) { return $s->name; })->values();
    @endphp

    @component('partials.patterns.list_page')
        @slot('subtitle')
            Notes, slides, videos and links shared by teachers — everything for study, in one place.
        @endslot
        @if($isTeacher)
            @slot('action')
                <a href="{{ route('materials.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> Add Material</a>
            @endslot
        @endif
        @slot('stats')
            <div class="lms82-stats">
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-blue"><i class="icon-book"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_total ?? 0 }}</strong><span>Total Materials</span><small>All subjects</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-green"><i class="icon-stack2"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_subjects ?? 0 }}</strong><span>Subjects</span><small>With materials</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-amber"><i class="icon-users"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_classes ?? 0 }}</strong><span>Classes</span><small>With materials</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-violet"><i class="icon-alarm"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_recent ?? 0 }}</strong><span>Recent Materials</span><small>Latest uploads</small></span>
                </div>
            </div>
        @endslot
        @slot('filters')
            <form method="get" action="{{ route('materials.index') }}" class="pat-filter-form lms82-filter-form">
                <div class="pat-filter-field lms82-filter-search">
                    <label for="lms82m-q">Search</label>
                    <input type="text" id="lms82m-q" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search materials by title, description or subject&hellip;">
                </div>
                <div class="pat-filter-field">
                    <label for="lms82m-subject">Subject</label>
                    <select id="lms82m-subject" name="subject_id" class="form-control">
                        <option value="">All Subjects</option>
                        @foreach($filter_subjects ?? [] as $subject)
                            <option value="{{ $subject->id }}" {{ (string) request('subject_id') === (string) $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="lms82m-class">Class</label>
                    <select id="lms82m-class" name="class_id" class="form-control">
                        <option value="">All Classes</option>
                        @foreach($filter_classes ?? [] as $class)
                            <option value="{{ $class->id }}" {{ (string) request('class_id') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="lms82m-type">Type</label>
                    <select id="lms82m-type" name="type" class="form-control">
                        <option value="">All Types</option>
                        <option value="document" {{ request('type') === 'document' ? 'selected' : '' }}>Document</option>
                        <option value="link" {{ request('type') === 'link' ? 'selected' : '' }}>Link</option>
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="lms82m-sort">Sort</label>
                    <select id="lms82m-sort" name="sort" class="form-control">
                        <option value="">Default (by class &amp; subject)</option>
                        <option value="newest" {{ request('sort') === 'newest' ? 'selected' : '' }}>Newest first</option>
                        <option value="oldest" {{ request('sort') === 'oldest' ? 'selected' : '' }}>Oldest first</option>
                        <option value="title" {{ request('sort') === 'title' ? 'selected' : '' }}>Title A–Z</option>
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Filter</button>
                    <a href="{{ route('materials.index') }}" class="btn btn-light"><i class="icon-reset mr-1"></i> Reset</a>
                </div>
            </form>
        @endslot

        @if(($filters_active ?? false) && $materials->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-search4',
                'message' => 'No study materials match these filters. Try a different search or clear the filters.',
                'action_url' => route('materials.index'),
                'action_label' => 'Clear Filters',
            ])
        @elseif($materials->isEmpty() && $groupSubjects->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-book',
                'message' => $isTeacher
                    ? 'No study materials yet. Add the first notes, slides or video link for your classes.'
                    : 'No study materials yet. When your teachers share notes or links, they will appear here.',
                'action_url' => $isTeacher ? route('materials.create') : null,
                'action_label' => 'Add Material',
            ])
        @else
            @foreach($groupSubjects as $subject)
                @php $items = $grouped->get($subject->id) ?? collect(); @endphp
                <div class="lms82-group">
                    <button type="button" class="lms82-group-head" data-lms82-group aria-expanded="true">
                        <span class="lms82-group-icon"><i class="icon-book"></i></span>
                        <span class="lms82-group-name">{{ $subject->name }}</span>
                        <span class="lms-chip">{{ $items->count() }} {{ \Illuminate\Support\Str::plural('material', $items->count()) }}</span>
                        <i class="icon-chevron-down lms82-group-chev"></i>
                    </button>
                    <div class="lms82-group-body">
                        @forelse($items as $material)
                            <div class="lms82-mat-row">
                                <span class="lms-mat-icon"><i class="{{ $material->file_path ? 'icon-file-text2' : 'icon-link' }}"></i></span>
                                <span class="lms-mat-copy">
                                    @if($material->file_path)
                                        <a href="{{ route('materials.download', $material->id) }}" class="lms82-mat-title">{{ $material->title }}</a>
                                    @elseif($material->link_url)
                                        <a href="{{ $material->link_url }}" target="_blank" rel="noopener" class="lms82-mat-title">{{ $material->title }}</a>
                                    @else
                                        <strong>{{ $material->title }}</strong>
                                    @endif
                                    @if($material->description)
                                        <span class="lms-mat-desc lms82-clamp2">{{ \Illuminate\Support\Str::limit($material->description, 160) }}</span>
                                    @endif
                                    <span class="lms82-mat-meta">
                                        <span class="lms-chip"><i class="icon-users mr-1"></i>{{ optional($material->myClass)->name ?? 'Class —' }}{{ $material->section ? ' · ' . optional($material->section)->name : ' · Whole class' }}@if(isset($cover_pairs) && ($cover_pairs[$material->my_class_id] ?? null) && in_array((int) $material->subject_id, $cover_pairs[$material->my_class_id], true) && (int) $material->teacher_id !== (int) Auth::id()) <span class="badge badge-warning ml-1">(Substitute)</span>@endif</span>
                                        <span class="lms-chip"><i class="icon-user mr-1"></i>by {{ optional($material->teacher)->name ?? 'Teacher' }}</span>
                                        @if($material->created_at)
                                            <span class="lms-chip"><i class="icon-calendar mr-1"></i>{{ \Carbon\Carbon::parse($material->created_at)->format('d M Y') }}</span>
                                        @endif
                                        @if($material->file_path)
                                            <span class="lms-chip lms-chip-subject"><i class="icon-file-text2 mr-1"></i>Document</span>
                                        @endif
                                        @if($material->link_url)
                                            <span class="lms-chip lms-chip-submitted"><i class="icon-link mr-1"></i>Link</span>
                                        @endif
                                    </span>
                                </span>
                                <span class="lms-mat-actions">
                                    @if($material->file_path)
                                        <a href="{{ route('materials.download', $material->id) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download</a>
                                    @endif
                                    @if($material->link_url)
                                        <a href="{{ $material->link_url }}" target="_blank" rel="noopener" class="btn btn-light btn-sm"><i class="icon-link mr-1"></i> Open Link</a>
                                    @endif
                                    @if($isTeacher && $material->teacher_id == auth()->id() && $material->section_id)
                                        <a href="{{ route('materials.share', $material->id) }}" class="btn btn-light btn-sm"><i class="icon-share2 mr-1"></i> Share</a>
                                    @endif
                                    @if(($isTeacher && $material->teacher_id == auth()->id()) || $isAdmin)
                                        <form method="post" action="{{ route('materials.destroy', $material->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this study material?" data-confirm-title="Delete Material" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button></form>
                                    @endif
                                </span>
                            </div>
                        @empty
                            <div class="lms82-group-empty">
                                <div><i class="icon-book mr-1"></i> No study materials yet</div>
                                <div>Materials for this subject will appear here when teachers add them.</div>
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        @endif
    @endcomponent

    <script>
        (function () {
            document.querySelectorAll('[data-lms82-group]').forEach(function (head) {
                head.addEventListener('click', function () {
                    var body = head.parentElement.querySelector('.lms82-group-body');
                    if (!body) { return; }
                    var open = head.getAttribute('aria-expanded') === 'true';
                    head.setAttribute('aria-expanded', open ? 'false' : 'true');
                    body.style.display = open ? 'none' : '';
                    head.classList.toggle('lms82-group-closed', open);
                });
            });
        })();
    </script>
@endsection
