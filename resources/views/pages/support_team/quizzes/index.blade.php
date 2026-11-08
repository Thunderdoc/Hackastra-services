@extends('layouts.master')
@section('page_title', 'Quizzes')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Learning &nbsp;/&nbsp; Quizzes
@endsection
@section('content')
    {{-- R3 LMS P3: quizzes index rebuilt on the shared list_page
         pattern with the lms82 language — stats cards, server-side
         search + status/subject filters, a proper lms-table with
         status chips and l108-stack labels, pagination, and the
         shared empty state. The duplicate inline flash alerts are
         gone — the layout renders flashes once. --}}
    @php
        $isTeacher = Qs::userIsTeacher();
        $isAdmin = Qs::userIsAdmin();
        $isStudent = Qs::userIsStudent();
        $now = now();
    @endphp

    @component('partials.patterns.list_page')
        @slot('subtitle')
            Online quizzes for your classes — attempt, review and track results in one place.
        @endslot
        @if($isTeacher)
            @slot('action')
                <a href="{{ route('quizzes.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Quiz</a>
            @endslot
        @endif
        @slot('stats')
            <div class="lms82-stats">
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-blue"><i class="icon-quill4"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_total ?? 0 }}</strong><span>Total Quizzes</span><small>All subjects</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-green"><i class="icon-checkmark3"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_published ?? 0 }}</strong><span>Published</span><small>Live for students</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-grey"><i class="icon-pencil7"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_draft ?? 0 }}</strong><span>Drafts</span><small>Not yet published</small></span>
                </div>
                <div class="lms82-stat">
                    <span class="lms82-stat-icon lms82-tone-amber"><i class="icon-book"></i></span>
                    <span class="lms82-stat-copy"><strong>{{ $card_subjects ?? 0 }}</strong><span>Subjects</span><small>With quizzes</small></span>
                </div>
            </div>
        @endslot
        @slot('filters')
            <form method="get" action="{{ route('quizzes.index') }}" class="pat-filter-form lms82-filter-form">
                <div class="pat-filter-field lms82-filter-search">
                    <label for="lms82q-q">Search</label>
                    <input type="text" id="lms82q-q" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search quizzes by title, subject or class...">
                </div>
                <div class="pat-filter-field">
                    <label for="lms82q-subject">Subject</label>
                    <select id="lms82q-subject" name="subject_id" class="form-control">
                        <option value="">All Subjects</option>
                        @foreach($filter_subjects ?? [] as $subject)
                            <option value="{{ $subject->id }}" {{ (string) request('subject_id') === (string) $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="lms82q-status">Status</label>
                    <select id="lms82q-status" name="status" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="published" {{ request('status') === 'published' ? 'selected' : '' }}>Published</option>
                        <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Filter</button>
                    <a href="{{ route('quizzes.index') }}" class="btn btn-light"><i class="icon-reset mr-1"></i> Reset</a>
                </div>
            </form>
        @endslot

        <div class="table-responsive">
        <table class="table hk-std hk-std-1080 l108-stack">
            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
            @if($isStudent)<colgroup><col style="width:22%"><col style="width:12%"><col style="width:10%"><col style="width:9%"><col style="width:9%"><col style="width:8%"><col style="width:10%"><col style="width:10%"><col style="width:10%"></colgroup>@else<colgroup><col style="width:24%"><col style="width:14%"><col style="width:11%"><col style="width:10%"><col style="width:10%"><col style="width:9%"><col style="width:11%"><col style="width:11%"></colgroup>@endif
            <thead>
            <tr>
                <th>Title</th>
                <th>Subject</th>
                <th>Class</th>
                <th>Section</th>
                <th>Questions</th>
                <th>Time</th>
                <th>Status</th>
                @if($isStudent)
                    <th>Your Result</th>
                @endif
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($quizzes as $quiz)
                @php
                    $isPublished = $quiz->status === 'published';
                    $windowChip = null;
                    if ($isPublished && $quiz->available_from && $now->lt($quiz->available_from)) {
                        $windowChip = ['Scheduled', 'lms-chip-pending'];
                    } elseif ($isPublished && $quiz->available_until && $now->gt($quiz->available_until)) {
                        $windowChip = ['Window closed', 'lms-chip-closed'];
                    } elseif ($isPublished) {
                        $windowChip = ['Open now', 'lms-chip-open'];
                    }
                    $myAttempt = $isStudent ? ($attempts[$quiz->id] ?? null) : null;
                @endphp
                <tr>
                    <td data-label="Title"><a href="{{ route('quizzes.show', $quiz->id) }}"><strong>{{ $quiz->title }}</strong></a></td>
                    <td data-label="Subject"><span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ $quiz->subject ? $quiz->subject->name : '—' }}</span></td>
                    <td data-label="Class">{{ $quiz->my_class ? $quiz->my_class->name : '—' }}</td>
                    <td data-label="Section">{{ $quiz->section ? $quiz->section->name : 'All sections' }}</td>
                    <td data-label="Questions">{{ $quiz->questions_count ?? 0 }}</td>
                    <td data-label="Time">{{ $quiz->time_limit_minutes ? $quiz->time_limit_minutes . ' min' : 'No limit' }}</td>
                    <td data-label="Status">
                        @if($isPublished)
                            <span class="lms-chip lms-chip-submitted">Published</span>
                        @else
                            <span class="lms-chip lms-chip-closed">Draft</span>
                        @endif
                        @if($windowChip)
                            <span class="lms-chip {{ $windowChip[1] }}">{{ $windowChip[0] }}</span>
                        @endif
                    </td>
                    @if($isStudent)
                        <td data-label="Your Result">
                            @if($myAttempt)
                                <strong>{{ $myAttempt->score }} / {{ $myAttempt->total }}</strong>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    @endif
                    <td data-label="Actions" style="white-space:nowrap">
                        <a href="{{ route('quizzes.show', $quiz->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                        @if($isStudent && !$myAttempt)
                            <a href="{{ route('quizzes.attempt', $quiz->id) }}" class="btn btn-primary btn-sm">Attempt</a>
                        @endif
                        @if($isTeacher || $isAdmin)
                            <a href="{{ route('quizzes.results', $quiz->id) }}" class="btn btn-light btn-sm">Results</a>
                            <a href="{{ route('quizzes.review', $quiz->id) }}" class="btn btn-light btn-sm">Review</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $isStudent ? 9 : 8 }}">
                        @if($filters_active ?? false)
                            @include('partials.patterns.empty_state', [
                                'icon' => 'icon-search4',
                                'message' => 'No quizzes match these filters. Try a different search or clear the filters.',
                                'action_url' => route('quizzes.index'),
                                'action_label' => 'Clear Filters',
                            ])
                        @else
                            @include('partials.patterns.empty_state', [
                                'icon' => 'icon-quill4',
                                'message' => $isTeacher
                                    ? 'No quizzes yet. Create the first one for your classes with the button above.'
                                    : 'No quizzes yet. When your teachers publish a quiz, it will appear here.',
                            ])
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        </div>

        @if(method_exists($quizzes, 'total') && $quizzes->total() > 0)
            <p class="text-muted mb-2"><small>Showing {{ $quizzes->firstItem() }} to {{ $quizzes->lastItem() }} of {{ $quizzes->total() }} {{ $quizzes->total() == 1 ? 'quiz' : 'quizzes' }}</small></p>
        @endif
        @if(method_exists($quizzes, 'links'))
            {{ $quizzes->links() }}
        @endif
    @endcomponent
@endsection
