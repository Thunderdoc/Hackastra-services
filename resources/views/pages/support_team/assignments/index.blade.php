@extends('layouts.master')
@section('page_title', 'Assignments')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Learning &nbsp;/&nbsp; Assignments
@endsection
@section('content')
    {{-- Loop 82 (LMS redesign): teacher/admin assignment list on the
         shared list_page pattern — summary cards + server-side
         filters + list/grid renderings of the same paginated set.
         Status follows the model's actual submission rule (CS1
         submit()): the window is OPEN while the due moment has not
         passed, or late submissions are allowed; CLOSED only when
         past due with late submissions off. Counts come from CS1's
         withCount (submissions_count / graded_count) + students_count
         (eligible students in the assignment's scope).
         NOTE: the duplicate inline flash alerts were removed here —
         the app-wide banner renders each message exactly once. --}}
    @php
        $isTeacher = Qs::userIsTeacher();
        $isAdmin = Qs::userIsAdmin();
        $viewMode = request('view') === 'grid' ? 'grid' : 'list';
        $statusOf = function ($assignment) {
            $due = $assignment->due_at ? \Carbon\Carbon::parse($assignment->due_at) : null;
            $open = !$due || $due->isFuture() || $assignment->allow_late;
            return [$due, $open];
        };
    @endphp

    @component('partials.patterns.list_page')
        @slot('subtitle')
            Create assignments, collect submissions online and grade them — the averages can flow straight into internal marks.
        @endslot
        @if($isTeacher)
            @slot('action')
                <a href="{{ route('assignments.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Assignment</a>
            @endslot
        @endif
        @slot('stats')
            <div class="lms82-stats">
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-blue"><i class="icon-file-text2"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_total ?? 0 }}</strong><span>Total Assignments</span><small>{{ $isTeacher ? 'Created by you' : 'All classes' }}</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-green"><i class="icon-paperplane"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_open ?? 0 }}</strong><span>Open Assignments</span><small>Currently accepting</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-grey"><i class="icon-lock5"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_closed ?? 0 }}</strong><span>Closed Assignments</span><small>Submission period ended</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-amber"><i class="icon-users"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_classes ?? 0 }}</strong><span>Classes</span><small>With assignments</small></span>
                </div>
            </div>
        @endslot
        @slot('filters')
            <form method="get" action="{{ route('assignments.index') }}" class="pat-filter-form lms82-filter-form">
                <div class="pat-filter-field lms82-filter-search">
                    <label for="lms82-q">Search</label>
                    <input type="text" id="lms82-q" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search assignments by title, subject or class...">
                </div>
                <div class="pat-filter-field">
                    <label for="lms82-class">Class</label>
                    <select id="lms82-class" name="class_id" class="form-control">
                        <option value="">All Classes</option>
                        @foreach($filter_classes ?? [] as $class)
                            <option value="{{ $class->id }}" {{ (string) request('class_id') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="lms82-subject">Subject</label>
                    <select id="lms82-subject" name="subject_id" class="form-control">
                        <option value="">All Subjects</option>
                        @foreach($filter_subjects ?? [] as $subject)
                            <option value="{{ $subject->id }}" {{ (string) request('subject_id') === (string) $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="lms82-status">Status</label>
                    <select id="lms82-status" name="status" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="open" {{ request('status') === 'open' ? 'selected' : '' }}>Open</option>
                        <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Closed</option>
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="lms82-sort">Sort</label>
                    <select id="lms82-sort" name="sort" class="form-control">
                        <option value="due_desc" {{ request('sort', 'due_desc') === 'due_desc' ? 'selected' : '' }}>Due Date (latest first)</option>
                        <option value="due_asc" {{ request('sort') === 'due_asc' ? 'selected' : '' }}>Due Date (earliest first)</option>
                        <option value="newest" {{ request('sort') === 'newest' ? 'selected' : '' }}>Newest first</option>
                        <option value="title" {{ request('sort') === 'title' ? 'selected' : '' }}>Title A–Z</option>
                    </select>
                </div>
                @if($viewMode === 'grid')
                    <input type="hidden" name="view" value="grid">
                @endif
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Filter</button>
                    <a href="{{ route('assignments.index') }}" class="btn btn-light"><i class="icon-reset mr-1"></i> Reset</a>
                </div>
            </form>
        @endslot

        <div class="lms82-viewbar">
            <span class="lms82-viewbar-count"><i class="icon-file-text2 mr-1"></i> Assignments ({{ method_exists($assignments, 'total') ? $assignments->total() : $assignments->count() }})</span>
            <span class="btn-group lms82-toggle" role="group" aria-label="Switch list or grid view">
                <button type="button" class="btn btn-sm {{ $viewMode === 'list' ? 'btn-primary' : 'btn-light' }}" data-lms82-view="list" title="List view"><i class="icon-list2"></i></button>
                <button type="button" class="btn btn-sm {{ $viewMode === 'grid' ? 'btn-primary' : 'btn-light' }}" data-lms82-view="grid" title="Grid view"><i class="icon-grid6"></i></button>
            </span>
        </div>

        <div id="lms82-list" @if($viewMode === 'grid') style="display:none" @endif>
            <table class="table lms-table">
                <thead>
                <tr>
                    <th>Assignment</th>
                    <th>Subject</th>
                    <th>Class</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Submissions</th>
                    <th>Graded</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($assignments as $assignment)
                    @php
                        [$due, $isOpen] = $statusOf($assignment);
                        $mine = $isTeacher && $assignment->teacher_id == auth()->id();
                        $waiting = max(0, ($assignment->submissions_count ?? 0) - ($assignment->graded_count ?? 0));
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('assignments.show', $assignment->id) }}"><strong>{{ $assignment->title }}</strong></a>
                            @if($assignment->description)
                                <div class="text-muted lms82-clamp2">{{ \Illuminate\Support\Str::limit($assignment->description, 130) }}</div>
                            @endif
                            @if($assignment->attachment_path)
                                <div class="mt-1"><span class="lms-chip"><i class="icon-attachment mr-1"></i>File attached</span></div>
                            @endif
                        </td>
                        <td><span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($assignment->subject)->name ?? 'Subject —' }}</span></td>
                        <td>
                            {{ optional($assignment->myClass)->name ?? '—' }}
                            <div class="text-muted">{{ $assignment->section ? optional($assignment->section)->name : 'Whole class' }}</div>
                        </td>
                        <td>
                            @if($due)
                                {{ $due->format('d M Y, h:i A') }}
                                @if($due->isPast())
                                    <div class="lms-due lms-due-overdue">Overdue · {{ $due->diffForHumans() }}</div>
                                @else
                                    <div class="text-muted">{{ $due->diffForHumans() }}</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($isOpen)
                                <span class="lms-chip lms-chip-open">Open</span>
                                @if($due && $due->isPast())
                                    <div class="text-muted mt-1">Late submissions open</div>
                                @endif
                            @else
                                <span class="lms-chip lms-chip-closed">Closed</span>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $assignment->submissions_count ?? 0 }} / {{ $assignment->students_count ?? 0 }}</strong> submitted
                        </td>
                        <td>
                            <strong>{{ $assignment->graded_count ?? 0 }}</strong> graded
                            @if($waiting > 0)
                                <div class="text-muted">{{ $waiting }} waiting</div>
                            @endif
                        </td>
                        <td style="white-space:nowrap">
                            <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                            @if($mine)
                                <a href="{{ route('assignments.edit', $assignment->id) }}" class="btn btn-light btn-sm"><i class="icon-pencil7 mr-1"></i> Edit</a>
                            @endif
                            @if($mine || $isAdmin)
                                <form method="post" action="{{ route('assignments.destroy', $assignment->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this assignment and all its submissions?" data-confirm-title="Delete Assignment" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            @if($filters_active ?? false)
                                @include('partials.patterns.empty_state', [
                                    'icon' => 'icon-search4',
                                    'message' => 'No assignments match these filters. Try a different search or clear the filters.',
                                    'action_url' => route('assignments.index'),
                                    'action_label' => 'Clear Filters',
                                ])
                            @else
                                @include('partials.patterns.empty_state', [
                                    'icon' => 'icon-file-text2',
                                    'message' => 'No assignments yet. Create the first one — students will see it in their portal instantly.',
                                    'action_url' => $isTeacher ? route('assignments.create') : null,
                                    'action_label' => 'New Assignment',
                                ])
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div id="lms82-grid" class="lms82-grid" @if($viewMode === 'list') style="display:none" @endif>
            @forelse($assignments as $assignment)
                @php
                    [$due, $isOpen] = $statusOf($assignment);
                    $mine = $isTeacher && $assignment->teacher_id == auth()->id();
                    $waiting = max(0, ($assignment->submissions_count ?? 0) - ($assignment->graded_count ?? 0));
                @endphp
                <div class="lms82-gcard">
                    <div class="lms82-gcard-top">
                        <span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($assignment->subject)->name ?? 'Subject —' }}</span>
                        @if($isOpen)
                            <span class="lms-chip lms-chip-open">Open</span>
                        @else
                            <span class="lms-chip lms-chip-closed">Closed</span>
                        @endif
                    </div>
                    <a href="{{ route('assignments.show', $assignment->id) }}" class="lms82-gcard-title">{{ $assignment->title }}</a>
                    @if($assignment->description)
                        <div class="text-muted lms82-clamp2">{{ \Illuminate\Support\Str::limit($assignment->description, 130) }}</div>
                    @endif
                    <div class="lms82-gcard-meta">
                        <span><i class="icon-users mr-1"></i>{{ optional($assignment->myClass)->name ?? '—' }} · {{ $assignment->section ? optional($assignment->section)->name : 'Whole class' }}</span>
                        <span>
                            <i class="icon-alarm mr-1"></i>
                            @if($due)
                                Due {{ $due->format('d M Y, h:i A') }}@if($due->isPast()) <span class="lms-due lms-due-overdue">(Overdue)</span>@endif
                            @else
                                No deadline
                            @endif
                        </span>
                        <span><i class="icon-paperplane mr-1"></i><strong>{{ $assignment->submissions_count ?? 0 }} / {{ $assignment->students_count ?? 0 }}</strong> submitted · <strong>{{ $assignment->graded_count ?? 0 }}</strong> graded @if($waiting > 0) · {{ $waiting }} waiting @endif</span>
                        @if($assignment->attachment_path)
                            <span><i class="icon-attachment mr-1"></i>File attached</span>
                        @endif
                    </div>
                    <div class="lms82-gcard-actions">
                        <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                        @if($mine)
                            <a href="{{ route('assignments.edit', $assignment->id) }}" class="btn btn-light btn-sm"><i class="icon-pencil7 mr-1"></i> Edit</a>
                        @endif
                        @if($mine || $isAdmin)
                            <form method="post" action="{{ route('assignments.destroy', $assignment->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this assignment and all its submissions?" data-confirm-title="Delete Assignment" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button></form>
                        @endif
                    </div>
                </div>
            @empty
                @if($filters_active ?? false)
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-search4',
                        'message' => 'No assignments match these filters. Try a different search or clear the filters.',
                        'action_url' => route('assignments.index'),
                        'action_label' => 'Clear Filters',
                    ])
                @else
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-file-text2',
                        'message' => 'No assignments yet. Create the first one — students will see it in their portal instantly.',
                        'action_url' => $isTeacher ? route('assignments.create') : null,
                        'action_label' => 'New Assignment',
                    ])
                @endif
            @endforelse
        </div>

        @if(method_exists($assignments, 'links'))
            {{ $assignments->links() }}
        @endif
    @endcomponent

    <script>
        (function () {
            var listEl = document.getElementById('lms82-list');
            var gridEl = document.getElementById('lms82-grid');
            if (!listEl || !gridEl) { return; }
            document.querySelectorAll('[data-lms82-view]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var view = btn.getAttribute('data-lms82-view');
                    listEl.style.display = view === 'list' ? '' : 'none';
                    gridEl.style.display = view === 'grid' ? '' : 'none';
                    document.querySelectorAll('[data-lms82-view]').forEach(function (b) {
                        var active = b.getAttribute('data-lms82-view') === view;
                        b.classList.toggle('btn-primary', active);
                        b.classList.toggle('btn-light', !active);
                    });
                    // Keep the chosen view across pagination.
                    document.querySelectorAll('.pagination a').forEach(function (a) {
                        try {
                            var url = new URL(a.href, window.location.origin);
                            url.searchParams.set('view', view);
                            a.href = url.toString();
                        } catch (e) { /* leave the link as-is */ }
                    });
                    try {
                        var here = new URL(window.location.href);
                        here.searchParams.set('view', view);
                        window.history.replaceState(null, '', here.toString());
                    } catch (e) { /* non-fatal */ }
                });
            });
        })();
    </script>
@endsection
