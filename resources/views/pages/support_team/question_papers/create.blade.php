@extends('layouts.master')
@section('page_title', 'Generate Question Paper')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('question_papers.index') }}">Question Papers</a> &nbsp;/&nbsp; Generate
@endsection
@section('content')
    @php
        $qpSections = [
            ['key' => 'A', 'label' => 'Objective', 'type' => 'mcq', 'count' => 10, 'marks_each' => 1],
            ['key' => 'B', 'label' => 'Short Answer', 'type' => 'short', 'count' => 5, 'marks_each' => 3],
            ['key' => 'C', 'label' => 'Long Answer', 'type' => 'long', 'count' => 3, 'marks_each' => 5],
        ];
    @endphp
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Generate a Question Paper from Your Bank</h5>
        </div>
        <div class="card-body">
            @if(session('paper_shortfalls'))
                <div class="alert alert-danger">
                    @foreach(session('paper_shortfalls') as $msg)<p class="mb-1">{{ $msg }}</p>@endforeach
                </div>
            @endif
            <form id="qp-form" method="post" action="{{ route('question_papers.store') }}">
                @csrf
                <div class="form-group">
                    <label for="title">Paper Title</label>
                    <input id="title" type="text" name="title" class="form-control" required value="{{ old('title') }}" placeholder="e.g. Unit Test 2 — English">
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="my_class_id">Class</label>
                            <select id="my_class_id" name="my_class_id" class="form-control" required>
                                <option value="">Select Class</option>
                                @foreach($my_classes as $c)
                                    <option value="{{ $c->id }}" {{ (string) old('my_class_id') === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="section_id">Section (optional — blank = whole class)</label>
                            <select id="section_id" name="section_id" class="form-control">
                                <option value="">Whole class</option>
                                @foreach($sections as $sec)
                                    <option value="{{ $sec->id }}" {{ (string) old('section_id') === (string) $sec->id ? 'selected' : '' }}>{{ optional($sec->my_class)->name ?? '' }} {{ $sec->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="subject_id">Subject</label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                @foreach($subjects as $s)
                                    <option value="{{ $s->id }}" {{ (string) old('subject_id') === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="exam_id">Exam (optional)</label>
                            <select id="exam_id" name="exam_id" class="form-control">
                                <option value="">No formal exam (class test / practice)</option>
                                @foreach($exams as $e)
                                    <option value="{{ $e->id }}" {{ (string) old('exam_id') === (string) $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="exam_label">Exam Label (when no exam is chosen)</label>
                            <input id="exam_label" type="text" name="exam_label" class="form-control" value="{{ old('exam_label') }}" placeholder="e.g. Unit Test 2">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="duration_minutes">Duration (minutes)</label>
                            <input id="duration_minutes" type="number" name="duration_minutes" class="form-control" min="1" max="600" value="{{ old('duration_minutes') }}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="exam_date">Exam Date (as printed)</label>
                            <input id="exam_date" type="text" name="exam_date" class="form-control" value="{{ old('exam_date') }}" placeholder="e.g. 12/12/2026">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="instructions">General Instructions (printed verbatim)</label>
                    <textarea id="instructions" name="instructions" class="form-control" rows="2">{{ old('instructions', 'Answer all questions. Marks are shown against each question.') }}</textarea>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="answer_space_lines">Ruled answer lines after short/long questions</label>
                            <input id="answer_space_lines" type="number" name="answer_space_lines" class="form-control" min="0" max="20" value="{{ old('answer_space_lines', 0) }}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="seed">Seed (same spec + same seed = same paper)</label>
                            <input id="seed" type="text" name="seed" class="form-control" value="{{ old('seed', \Illuminate\Support\Str::random(16)) }}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check mt-4">
                            <label class="form-check-label">
                                <input type="checkbox" name="allow_repeat_questions" value="1" class="form-check-input" {{ old('allow_repeat_questions') ? 'checked' : '' }}>
                                Allow questions already used in a final paper for this exam
                            </label>
                        </div>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input type="checkbox" name="create_partial" value="1" class="form-check-input" {{ old('create_partial') ? 'checked' : '' }}>
                                Create a partial draft anyway if a section falls short (it cannot be finalised)
                            </label>
                        </div>
                    </div>
                </div>

                @foreach($qpSections as $secDef)
                    @php
                        $secDisabled = ($secDef['type'] === 'short' && $bank_type_counts['short'] === 0)
                            || ($secDef['type'] === 'long' && $bank_type_counts['long'] === 0);
                    @endphp
                    <fieldset {{ $secDisabled ? 'disabled' : '' }} class="border p-3 mb-3">
                        <legend class="w-auto px-2" style="font-size:15px;font-weight:700">Section {{ $secDef['key'] }} — {{ $secDef['label'] }} ({{ strtoupper($secDef['type']) }})</legend>
                        @if($secDisabled)
                            <p class="text-danger">Your bank has no {{ $secDef['type'] }} questions yet — add them in the Question Bank to enable this section.</p>
                        @endif
                        <input type="hidden" name="sections[{{ $secDef['key'] }}][key]" value="{{ $secDef['key'] }}">
                        <input type="hidden" name="sections[{{ $secDef['key'] }}][type]" value="{{ $secDef['type'] }}">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Section Label</label>
                                    <input type="text" name="sections[{{ $secDef['key'] }}][label]" class="form-control" value="{{ old('sections.' . $secDef['key'] . '.label', $secDef['label']) }}">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Questions</label>
                                    <input type="number" name="sections[{{ $secDef['key'] }}][count]" class="form-control" min="0" max="100" value="{{ old('sections.' . $secDef['key'] . '.count', $secDef['count']) }}">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Marks Each</label>
                                    <input type="number" name="sections[{{ $secDef['key'] }}][marks_each]" class="form-control" min="1" value="{{ old('sections.' . $secDef['key'] . '.marks_each', $secDef['marks_each']) }}">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>or Marks Total</label>
                                    <input type="number" name="sections[{{ $secDef['key'] }}][marks_total]" class="form-control" min="1" value="{{ old('sections.' . $secDef['key'] . '.marks_total') }}" placeholder="instead of each">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Difficulty Mix % (easy / medium / hard / unrated)</label>
                                    <div class="d-flex">
                                        @foreach(['easy', 'medium', 'hard', 'unrated'] as $bucket)
                                            <input type="number" name="sections[{{ $secDef['key'] }}][difficulty_mix][{{ $bucket }}]" class="form-control mr-1" min="0" max="100" placeholder="{{ ucfirst(substr($bucket, 0, 1)) }}" title="{{ ucfirst($bucket) }} %" value="{{ old('sections.' . $secDef['key'] . '.difficulty_mix.' . $bucket) }}">
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label>Chapters (none ticked = all chapters)</label>
                            <div class="qp-chapters" data-section="{{ $secDef['key'] }}">
                                <span class="text-muted">Choose a subject to list your chapters.</span>
                            </div>
                        </div>
                    </fieldset>
                @endforeach

                <div id="qp-preview-results" class="mb-3"></div>
                <button type="button" id="qp-preview-btn" class="btn btn-info">Preview Selection</button>
                <button type="submit" class="btn btn-primary">Create Draft Paper</button>
                <a href="{{ route('question_papers.index') }}" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>

    {{-- AI panel (design §4.3): a separate card; Mode A above works
         identically in every AI state. --}}
    <div class="card mt-3">
        <div class="card-header">
            <h5 class="card-title">AI Question Generation (optional)</h5>
        </div>
        <div class="card-body">
            @if($ai_state === 'off')
                <p class="mb-2">AI question generation is switched off. Bank-only generation works fully without it.</p>
                <a href="{{ route('question_papers.ai_index') }}" class="btn btn-light btn-sm">Open the AI Review Queue</a>
            @elseif($ai_state === 'no_key')
                <p class="mb-2">AI is switched on but no API key is configured yet, so real AI generation isn't available. Bank-only generation works fully without it.</p>
                <a href="{{ route('question_papers.ai_index') }}" class="btn btn-light btn-sm">Open the AI Review Queue</a>
            @else
                <p class="text-muted">AI suggests questions; nothing is saved to a paper or your bank until you review and approve each one. When you generate, 2–3 of your own bank questions on the chapter may be sent as style examples.</p>
                <form method="post" action="{{ route('question_papers.ai_generate') }}">
                    @csrf
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Class</label>
                                <select name="my_class_id" class="form-control" required>
                                    <option value="">Select Class</option>
                                    @foreach($my_classes as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Subject</label>
                                <select name="subject_id" class="form-control" required>
                                    <option value="">Select Subject</option>
                                    @foreach($subjects as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Section (optional)</label>
                                <select name="section_id" class="form-control">
                                    <option value="">Whole class</option>
                                    @foreach($sections as $sec)<option value="{{ $sec->id }}">{{ optional($sec->my_class)->name ?? '' }} {{ $sec->name }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Target Draft Paper (optional)</label>
                                <select name="question_paper_id" class="form-control">
                                    <option value="">None — generate for the bank</option>
                                    @foreach($my_papers as $p)<option value="{{ $p->id }}">{{ $p->title }} ({{ $p->status }})</option>@endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Type</label>
                                <select name="question_type" class="form-control">
                                    <option value="mcq">MCQ</option>
                                    <option value="short">Short Answer</option>
                                    <option value="long">Long Answer</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Count</label>
                                <input type="number" name="count" class="form-control" min="1" max="20" value="5" required>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Marks Each</label>
                                <input type="number" name="marks" class="form-control" min="1" value="1" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Chapter / Topic</label>
                                <input type="text" name="chapter" class="form-control" maxlength="120">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Difficulty</label>
                                <select name="difficulty" class="form-control">
                                    <option value="">Any</option>
                                    <option value="easy">Easy</option>
                                    <option value="medium">Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-info">Generate for Review</button>
                    <a href="{{ route('question_papers.ai_index') }}" class="btn btn-light">Open the AI Review Queue</a>
                </form>
            @endif
        </div>
    </div>

    <script>
        (function () {
            var chaptersBySubject = @json($chapters_by_subject);
            var subjectSel = document.getElementById('subject_id');
            function renderChapters() {
                var chapters = chaptersBySubject[subjectSel.value] || [];
                document.querySelectorAll('.qp-chapters').forEach(function (box) {
                    var key = box.getAttribute('data-section');
                    if (!chapters.length) {
                        box.innerHTML = '<span class="text-muted">No chapters in your bank for this subject yet — all questions are eligible.</span>';
                        return;
                    }
                    box.innerHTML = chapters.map(function (ch) {
                        var id = 'ch-' + key + '-' + ch.replace(/[^a-z0-9]+/gi, '-');
                        return '<label class="form-check form-check-inline" for="' + id + '">'
                            + '<input class="form-check-input" type="checkbox" id="' + id + '" name="sections[' + key + '][chapters][]" value="' + ch.replace(/"/g, '&quot;') + '"> '
                            + ch.replace(/</g, '&lt;') + '</label>';
                    }).join('');
                });
            }
            subjectSel.addEventListener('change', renderChapters);
            renderChapters();

            var previewBtn = document.getElementById('qp-preview-btn');
            previewBtn.addEventListener('click', function () {
                var form = document.getElementById('qp-form');
                var results = document.getElementById('qp-preview-results');
                results.innerHTML = '<p class="text-muted">Running the selection…</p>';
                fetch('{{ route('question_papers.preview') }}', {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json'}
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var html = '';
                    if (data.duplicate_warning) { html += '<div class="alert alert-warning">' + data.duplicate_warning + '</div>'; }
                    if (data.excluded_total > 0) {
                        html += '<div class="alert alert-info">' + data.excluded_total + ' questions excluded — already used in ' + (data.excluded_from || []).join(', ') + '.</div>';
                    }
                    Object.keys(data.sections || {}).forEach(function (key) {
                        var s = data.sections[key];
                        html += '<p class="mb-1"><strong>Section ' + key + ':</strong> ' + s.picked + ' of the requested questions will be picked from a pool of ' + s.pool + ' (' + s.marks + ' marks).'
                            + (s.excluded_duplicates ? ' ' + s.excluded_duplicates + ' excluded as duplicates.' : '')
                            + (s.difficulty_note ? ' ' + s.difficulty_note : '') + '</p>';
                        if (s.shortfall) { html += '<div class="alert alert-danger">' + s.shortfall.message + '</div>'; }
                    });
                    if (!html) { html = '<p class="text-muted">Fill the form, then preview again.</p>'; }
                    results.innerHTML = html;
                }).catch(function () {
                    results.innerHTML = '<div class="alert alert-danger">Preview failed — check the form fields (class, subject and section counts are required).</div>';
                });
            });
        })();
    </script>
@endsection
