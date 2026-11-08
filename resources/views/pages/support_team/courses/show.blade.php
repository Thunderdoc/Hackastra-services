@extends('layouts.master')
@section('page_title', $subject->name)
@section('own_page_back', true)
@section('page_breadcrumb')
    <a href="{{ route('courses.index') }}">My Courses</a> &middot; {{ $subject->name }}
@endsection
@section('page_actions')
    <a href="{{ route('courses.index') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Back</a>
@endsection
@section('content')

    {{-- One course page for students and teachers (2026-11-08,
         owner-approved sample): the sequence never changes —
         notices, materials, assignments, quizzes — pinned first,
         then the teacher's order. The teacher's page is the
         student's page plus management controls, so what she
         arranges is exactly what the student sees. --}}
    @php $color = \App\Support\SubjectColor::forName($subject->name); @endphp

    <div class="card course-hero">
        <div class="card-body">
            <div class="course-hero-main">
                <span class="course-hero-icon" style="background: {{ $color }}"><i class="icon-book"></i></span>
                <div>
                    <h4 class="course-hero-title">{{ $subject->name }}</h4>
                    <div class="text-muted">
                        {{ optional($my_class)->name }}@if($section) &middot; Section {{ $section->name }}@endif
                        &middot; {{ $teacher_name ?: 'No teacher assigned' }}
                        @if($subject->needs_lab) &middot; Practical (lab)@endif
                    </div>
                </div>
                @if($mode === 'student' && $attendance_percent !== null)
                    <span class="course-hero-att">
                        <strong>{{ $attendance_percent }}%</strong> &middot; My attendance
                        <span class="mw-pct-bar"><span class="{{ $attendance_percent >= 75 ? 'ok' : ($attendance_percent >= 50 ? 'warn' : 'bad') }}" style="width: {{ $attendance_percent }}%"></span></span>
                    </span>
                @endif
            </div>

            @if($mode === 'teacher' && isset($my_sections) && $my_sections->count() > 1)
                <div class="course-switcher">
                    <span class="text-muted">Section:</span>
                    @foreach($my_sections as $sec)
                        <a href="{{ route('courses.show', ['subject' => $subject->id, 'section' => $sec->id]) }}" class="btn btn-sm {{ $section && $section->id === $sec->id ? 'btn-primary' : 'btn-light' }}">{{ optional($sec->my_class)->name }} &middot; {{ $sec->name }}</a>
                    @endforeach
                </div>
            @endif

            <div class="course-chiprow">
                @if($notices->where('is_pinned', true)->isNotEmpty())<span>Pinned notice on top</span>@endif
                <span>Materials {{ $counts['materials'] }}</span>
                <span>Assignments {{ $counts['assignments'] }}</span>
                <span>Quizzes {{ $counts['quizzes'] }}</span>
                <span>In this order, always &middot; pinned first</span>
            </div>

            @if($read_only)
                <div class="alert alert-info" style="margin:12px 0 0">
                    You are covering this class today as a substitute. This course is read-only — the class teacher keeps ownership of its notices, pins and order.
                </div>
            @endif
        </div>
    </div>

    {{-- ======================= Notices ======================= --}}
    <div class="card" id="notices">
        <div class="card-body">
            <div class="course-section-head">
                <h5><span class="course-sec-icon" style="background: {{ $color }}"><i class="icon-megaphone"></i></span> Notices</h5>
            </div>

            @if($can_manage)
                <form method="POST" action="{{ route('courses.notices.store', $subject->id) }}" class="course-composer">
                    @csrf
                    <input type="hidden" name="section_id" value="{{ $section->id ?? '' }}">
                    <textarea name="body" class="form-control" rows="2" maxlength="2000" required placeholder="Post a notice to {{ $subject->name }}@if($section) (Section {{ $section->name }})@endif — it appears here for your students."></textarea>
                    <div class="course-composer-row">
                        <label class="course-pin-toggle"><input type="checkbox" name="is_pinned" value="1"> Pin to top <span class="text-muted">— a pinned notice sits above everything until you unpin it</span></label>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="icon-megaphone mr-1"></i> Post notice</button>
                    </div>
                </form>
            @endif

            @if($notices->isEmpty())
                <p class="text-muted mb-0">No notices in this course yet.</p>
            @else
                <div class="course-items" @if($can_manage) data-kind="notice" data-reorder-url="{{ route('courses.reorder') }}" data-csrf="{{ csrf_token() }}" @endif>
                    @foreach($notices as $notice)
                        <div class="course-item course-notice {{ $notice->is_pinned ? 'pinned' : '' }}" @if($can_manage) draggable="true" data-id="{{ $notice->id }}" @endif>
                            @if($can_manage)<span class="course-grip" title="Drag to re-order"><i class="icon-drag"></i></span>@endif
                            <div class="course-item-main">
                                @if($notice->is_pinned)<span class="course-pin-badge"><i class="icon-pin"></i> Pinned notice</span>@endif
                                <p class="course-notice-body">{{ $notice->body }}</p>
                                <small class="text-muted">{{ optional($notice->teacher)->name }} &middot; {{ $notice->created_at->format('d M Y') }}</small>
                            </div>
                            @if($can_manage)
                                <span class="course-item-actions">
                                    @include('pages.support_team.courses.partials.pin_button', ['kind' => 'notice', 'item' => $notice])
                                    <form method="POST" action="{{ route('courses.notices.destroy', $notice->id) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-light btn-sm text-danger" title="Delete notice" data-confirm="Delete this notice? &quot;{{ \Illuminate\Support\Str::limit($notice->body, 50) }}&quot;" data-confirm-title="Delete Notice" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash"></i></button>
                                    </form>
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ======================= Study Materials ======================= --}}
    <div class="card" id="materials">
        <div class="card-body">
            <div class="course-section-head">
                <h5><span class="course-sec-icon" style="background: {{ $color }}"><i class="icon-folder-open"></i></span> Study Materials</h5>
                @if($can_manage)<a href="{{ route('materials.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> Add material</a>@endif
            </div>

            @if($materials->isEmpty())
                <p class="text-muted mb-0">No study materials in this course yet.</p>
            @else
                <div class="course-items" @if($can_manage) data-kind="material" data-reorder-url="{{ route('courses.reorder') }}" data-csrf="{{ csrf_token() }}" @endif>
                    @foreach($materials as $material)
                        @php
                            $isLink = !empty($material->link_url);
                            $ext = $isLink ? 'LINK' : strtoupper(pathinfo((string) parse_url($material->file_path, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'FILE');
                        @endphp
                        <div class="course-item {{ $material->is_pinned ? 'pinned' : '' }}" @if($can_manage) draggable="true" data-id="{{ $material->id }}" @endif>
                            @if($can_manage)<span class="course-grip" title="Drag to re-order"><i class="icon-drag"></i></span>@endif
                            <span class="course-file-chip">{{ $ext }}</span>
                            <div class="course-item-main">
                                <strong>{{ $material->title }}</strong>
                                @if($material->is_pinned)<span class="course-pin-badge"><i class="icon-pin"></i> Pinned</span>@endif
                                <small class="d-block text-muted">{{ optional($material->teacher)->name }} &middot; {{ $material->created_at->format('d M Y') }}@if($material->description) &middot; {{ \Illuminate\Support\Str::limit($material->description, 80) }}@endif</small>
                            </div>
                            <span class="course-item-actions">
                                @if($isLink)
                                    <a href="{{ $material->link_url }}" target="_blank" rel="noopener" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                @else
                                    <a href="{{ route('materials.viewer', $material->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                    <a href="{{ route('materials.download', $material->id) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download</a>
                                @endif
                                @if($can_manage) @include('pages.support_team.courses.partials.pin_button', ['kind' => 'material', 'item' => $material]) @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ======================= Assignments ======================= --}}
    <div class="card" id="assignments">
        <div class="card-body">
            <div class="course-section-head">
                <h5><span class="course-sec-icon" style="background: {{ $color }}"><i class="icon-file-text2"></i></span> Assignments</h5>
                @if($can_manage)<a href="{{ route('assignments.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New assignment</a>@endif
            </div>

            @if($assignments->isEmpty())
                <p class="text-muted mb-0">No assignments in this course yet.</p>
            @else
                <div class="course-items" @if($can_manage) data-kind="assignment" data-reorder-url="{{ route('courses.reorder') }}" data-csrf="{{ csrf_token() }}" @endif>
                    @foreach($assignments as $assignment)
                        <div class="course-item {{ $assignment->is_pinned ? 'pinned' : '' }}" @if($can_manage) draggable="true" data-id="{{ $assignment->id }}" @endif>
                            @if($can_manage)<span class="course-grip" title="Drag to re-order"><i class="icon-drag"></i></span>@endif
                            <div class="course-item-main">
                                <strong>{{ $assignment->title }}</strong>
                                @if($assignment->is_pinned)<span class="course-pin-badge"><i class="icon-pin"></i> Pinned</span>@endif
                                <small class="d-block text-muted">
                                    @if($assignment->due_at)Due {{ $assignment->due_at->format('d M Y') }} @else No due date @endif
                                    &middot; {{ number_format((float) $assignment->max_marks, 2) }} marks
                                </small>
                            </div>
                            <span class="course-item-actions">
                                @if($mode === 'student')
                                    @php $sub = $assignment->my_submission ?? null; @endphp
                                    @if($sub && $sub->marks_awarded !== null)
                                        <span class="badge badge-success">Graded</span>
                                        <span class="course-score-chip">{{ number_format((float) $sub->marks_awarded, 2) }} / {{ number_format((float) $assignment->max_marks, 2) }}</span>
                                    @elseif($sub)
                                        <span class="badge badge-info">Submitted &middot; grading in progress</span>
                                    @elseif($assignment->isPastDue() && !$assignment->allow_late)
                                        <span class="badge badge-danger">Overdue</span>
                                    @else
                                        <span class="badge badge-warning">To submit</span>
                                        <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-primary btn-sm">Submit</a>
                                    @endif
                                    <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-light btn-sm">Details</a>
                                @else
                                    <span class="text-muted">{{ $assignment->submissions_count ?? 0 }} submissions</span>
                                    <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-light btn-sm">Submissions</a>
                                @endif
                                @if($can_manage) @include('pages.support_team.courses.partials.pin_button', ['kind' => 'assignment', 'item' => $assignment]) @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ======================= Quizzes ======================= --}}
    <div class="card" id="quizzes">
        <div class="card-body">
            <div class="course-section-head">
                <h5><span class="course-sec-icon" style="background: {{ $color }}"><i class="icon-help"></i></span> Quizzes</h5>
                @if($can_manage)<a href="{{ route('quizzes.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New quiz</a>@endif
            </div>

            @if($quizzes->isEmpty())
                <p class="text-muted mb-0">No quizzes in this course yet.</p>
            @else
                <div class="course-items" @if($can_manage) data-kind="quiz" data-reorder-url="{{ route('courses.reorder') }}" data-csrf="{{ csrf_token() }}" @endif>
                    @foreach($quizzes as $quiz)
                        <div class="course-item {{ $quiz->is_pinned ? 'pinned' : '' }}" @if($can_manage) draggable="true" data-id="{{ $quiz->id }}" @endif>
                            @if($can_manage)<span class="course-grip" title="Drag to re-order"><i class="icon-drag"></i></span>@endif
                            <div class="course-item-main">
                                <strong>{{ $quiz->title }}</strong>
                                @if($quiz->is_pinned)<span class="course-pin-badge"><i class="icon-pin"></i> Pinned</span>@endif
                                <small class="d-block text-muted">
                                    {{ $quiz->questions_count ?? $quiz->questions->count() }} questions
                                    @if($quiz->time_limit_minutes) &middot; {{ $quiz->time_limit_minutes }} min @endif
                                    @if($quiz->available_until) &middot; until {{ $quiz->available_until->format('d M Y') }}@endif
                                </small>
                            </div>
                            <span class="course-item-actions">
                                @if($mode === 'student')
                                    @php $attempt = $attempts->get($quiz->id); @endphp
                                    @if($attempt)
                                        <span class="badge badge-success">Completed</span>
                                        <span class="course-score-chip">{{ $attempt->score }} / {{ $attempt->total }}</span>
                                        <a href="{{ route('quizzes.review', $quiz->id) }}" class="btn btn-light btn-sm">Review</a>
                                    @elseif($quiz->available_from && now()->lt($quiz->available_from))
                                        <span class="badge badge-info">Opens {{ $quiz->available_from->format('d M') }}</span>
                                    @else
                                        <span class="badge badge-warning">Upcoming</span>
                                        <a href="{{ route('quizzes.attempt', $quiz->id) }}" class="btn btn-primary btn-sm">Take quiz</a>
                                    @endif
                                @else
                                    @if($quiz->status === 'published')<span class="badge badge-success">Published</span>@else<span class="badge badge-secondary">Draft</span>@endif
                                    <a href="{{ route('quizzes.show', $quiz->id) }}" class="btn btn-light btn-sm">Open</a>
                                @endif
                                @if($can_manage) @include('pages.support_team.courses.partials.pin_button', ['kind' => 'quiz', 'item' => $quiz]) @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if($mode === 'teacher' && $can_manage)
        <p class="course-scope-note">You manage only your own subjects and sections — this course is {{ $subject->name }} for {{ optional($my_class)->name }}@if($section), Section {{ $section->name }}@endif. Students see only their own section's course, in exactly this order.</p>
    @endif

@endsection

@if($can_manage)
@section('scripts')
<script>
    // Drag to re-sequence within a kind (LMS courses, 2026-11-08):
    // plain HTML5 drag on the item rows; the drop posts the new
    // id order to courses.reorder, which re-checks the teacher's
    // scope server-side before persisting seq.
    (function () {
        document.querySelectorAll('.course-items[data-kind]').forEach(function (list) {
            var dragged = null;
            list.querySelectorAll('.course-item').forEach(function (item) {
                item.addEventListener('dragstart', function () { dragged = item; item.classList.add('dragging'); });
                item.addEventListener('dragend', function () {
                    item.classList.remove('dragging');
                    var ids = Array.prototype.map.call(list.querySelectorAll('.course-item'), function (el) { return el.getAttribute('data-id'); });
                    fetch(list.getAttribute('data-reorder-url'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': list.getAttribute('data-csrf') },
                        body: JSON.stringify({ kind: list.getAttribute('data-kind'), ids: ids })
                    }).then(function (r) { if (!r.ok) { window.location.reload(); } });
                });
                item.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    if (!dragged || dragged === item) { return; }
                    var rect = item.getBoundingClientRect();
                    var after = (e.clientY - rect.top) > rect.height / 2;
                    list.insertBefore(dragged, after ? item.nextSibling : item);
                });
            });
        });
    })();
</script>
@endsection
@endif
