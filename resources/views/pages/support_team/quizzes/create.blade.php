@extends('layouts.master')
@section('page_title', 'New Quiz')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('quizzes.index') }}">Quizzes</a> &nbsp;/&nbsp; New Quiz
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">New Quiz</h5>
        </div>
        <div class="card-body">
            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="post" action="{{ route('quizzes.store') }}" id="quiz-create-form">
                @csrf
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" name="title" id="title" class="form-control" value="{{ old('title') }}" required>
                </div>
                <div class="form-group">
                    <label for="subject_id">Subject (only subjects you teach)</label>
                    <select name="subject_id" id="subject_id" class="form-control" required>
                        <option value="">Select subject</option>
                        @foreach ($subjects as $subject)
                            <option value="{{ $subject->id }}" data-class-id="{{ $subject->my_class_id }}" {{ old('subject_id') == $subject->id ? 'selected' : '' }}>{{ $subject->name }} ({{ $subject->my_class ? $subject->my_class->name : '' }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="section_id">Section (leave blank for the whole class)</label>
                    <select name="section_id" id="section_id" class="form-control">
                        <option value="">All sections</option>
                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}" data-class-id="{{ $section->my_class_id }}" {{ old('section_id') == $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="time_limit_minutes">Time limit (minutes, optional)</label>
                    <input type="number" name="time_limit_minutes" id="time_limit_minutes" class="form-control" value="{{ old('time_limit_minutes') }}" min="1" max="600">
                    <small class="form-text text-muted">When set, the attempt page shows a countdown that auto-submits at zero. The server records when the attempt was submitted but does not reject late submissions.</small>
                </div>

                <h6>Bulk questions — paste many at once</h6>
                <p class="text-muted">Fill <strong>either</strong> this box <strong>or</strong> the inline questions below, not both. Blocks are separated by a blank line. Each block: question line(s), then options as "A) text", then an "Answer:" line (letter or number). The marks below apply to every parsed question. Nothing is saved until every block parses — bad blocks are shown back for fixing.</p>
                <div class="form-group">
                    <label for="bulk_text">Paste questions</label>
                    <textarea name="bulk_text" id="bulk_text" class="form-control" rows="10">{{ old('bulk_text') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="bulk_marks">Marks per parsed question</label>
                    <input type="number" name="bulk_marks" id="bulk_marks" class="form-control" value="{{ old('bulk_marks', 1) }}" min="1" max="100">
                </div>
                <pre>What is 2 + 2?
A) 3
B) 4
C) 5
Answer: B

Pick the vowel.
A) B
B) A
C) C
Answer: 2</pre>

                <h6>From Question Bank</h6>
                <div class="form-group">
                    <label for="bank-question">Pick a saved question</label>
                    <div class="input-group">
                        <select id="bank-question" class="form-control">
                            <option value="">Load bank questions first</option>
                        </select>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-light" id="load-bank">Load</button>
                        </div>
                    </div>
                    <small class="form-text text-muted">Loads your own bank questions for the class + subject picked above. Pick one, then "Add from bank" inserts it as an editable question row below — or write new ones inline.</small>
                </div>
                <button type="button" class="btn btn-light mb-3" id="add-from-bank" disabled>Add from bank</button>

                <h6>Questions</h6>
                <div id="question-rows">
                    <div class="question-row card card-body mb-3" data-row="0">
                        <div class="form-group">
                            <label>Question</label>
                            <textarea name="questions[0][question]" class="form-control" rows="2" required></textarea>
                        </div>
                        @for ($o = 0; $o < 4; $o++)
                            <div class="form-group">
                                <label>Option {{ $o + 1 }}</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <input type="radio" name="questions[0][correct_index]" value="{{ $o }}" {{ $o === 0 ? 'checked' : '' }} title="Mark as correct">
                                        </div>
                                    </div>
                                    <input type="text" name="questions[0][options][{{ $o }}]" class="form-control" {{ $o < 2 ? 'required' : '' }}>
                                </div>
                            </div>
                        @endfor
                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="questions[0][marks]" class="form-control" value="1" min="1" max="100">
                        </div>
                        <button type="button" class="btn btn-light btn-sm remove-question">Remove question</button>
                    </div>
                </div>
                <button type="button" class="btn btn-light mb-3" id="add-question">Add question</button>
                <div>
                    <a href="{{ route('quizzes.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create Quiz</button>
                </div>
            </form>
        </div>
    </div>
@endsection
@section('scripts')
    <script>
        (function () {
            var rowsBox = document.getElementById('question-rows');
            var subjectSel = document.getElementById('subject_id');
            var sectionSel = document.getElementById('section_id');
            function filterSections() {
                var opt = subjectSel.options[subjectSel.selectedIndex];
                var classId = opt ? opt.getAttribute('data-class-id') : null;
                Array.prototype.forEach.call(sectionSel.options, function (o) {
                    if (!o.value) { o.style.display = ''; return; }
                    o.style.display = (!classId || o.getAttribute('data-class-id') === classId) ? '' : 'none';
                });
                if (sectionSel.selectedOptions.length && sectionSel.selectedOptions[0].style.display === 'none') {
                    sectionSel.value = '';
                }
            }
            subjectSel.addEventListener('change', filterSections);
            filterSections();
            function rowHtml(idx) {
                var html = '<div class="question-row card card-body mb-3" data-row="' + idx + '">';
                html += '<div class="form-group"><label>Question</label><textarea name="questions[' + idx + '][question]" class="form-control" rows="2" required></textarea></div>';
                for (var o = 0; o < 4; o++) {
                    html += '<div class="form-group"><label>Option ' + (o + 1) + '</label><div class="input-group"><div class="input-group-prepend"><div class="input-group-text"><input type="radio" name="questions[' + idx + '][correct_index]" value="' + o + '"' + (o === 0 ? ' checked' : '') + ' title="Mark as correct"></div></div><input type="text" name="questions[' + idx + '][options][' + o + ']" class="form-control"' + (o < 2 ? ' required' : '') + '></div></div>';
                }
                html += '<div class="form-group"><label>Marks</label><input type="number" name="questions[' + idx + '][marks]" class="form-control" value="1" min="1" max="100"></div>';
                html += '<button type="button" class="btn btn-light btn-sm remove-question">Remove question</button></div>';
                return html;
            }
            document.getElementById('add-question').addEventListener('click', function () {
                rowsBox.insertAdjacentHTML('beforeend', rowHtml(nextIdx()));
            });
            rowsBox.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('remove-question')) {
                    var rows = rowsBox.querySelectorAll('.question-row');
                    if (rows.length > 1) {
                        e.target.closest('.question-row').remove();
                    }
                }
            });
            function esc(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            function nextIdx() {
                var max = -1;
                Array.prototype.forEach.call(rowsBox.querySelectorAll('.question-row'), function (r) {
                    var v = parseInt(r.getAttribute('data-row'), 10);
                    if (!isNaN(v) && v > max) { max = v; }
                });
                return max + 1;
            }
            var bankSel = document.getElementById('bank-question');
            var bankCache = [];
            document.getElementById('load-bank').addEventListener('click', function () {
                var opt = subjectSel.options[subjectSel.selectedIndex];
                var classId = opt ? opt.getAttribute('data-class-id') : '';
                var subjectId = subjectSel.value;
                if (!subjectId) {
                    bankSel.innerHTML = '<option value="">Pick a subject first</option>';
                    document.getElementById('add-from-bank').disabled = true;
                    return;
                }
                var url = '/question-bank/picker?class_id=' + encodeURIComponent(classId) + '&subject_id=' + encodeURIComponent(subjectId);
                if (sectionSel.value) { url += '&section_id=' + encodeURIComponent(sectionSel.value); }
                fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function (r) { return r.json(); }).then(function (data) {
                    bankCache = data;
                    if (!data.length) {
                        bankSel.innerHTML = '<option value="">No bank questions for this class + subject</option>';
                    } else {
                        bankSel.innerHTML = data.map(function (q, i) {
                            return '<option value="' + i + '">' + esc(q.question) + ' (' + q.marks + ' marks)</option>';
                        }).join('');
                    }
                    document.getElementById('add-from-bank').disabled = !data.length;
                });
            });
            document.getElementById('add-from-bank').addEventListener('click', function () {
                var q = bankCache[parseInt(bankSel.value, 10)];
                if (!q) { return; }
                var idx = nextIdx();
                rowsBox.insertAdjacentHTML('beforeend', rowHtml(idx));
                var row = rowsBox.querySelector('.question-row[data-row="' + idx + '"]');
                row.querySelector('textarea[name="questions[' + idx + '][question]"]').value = q.question;
                for (var o = 0; o < 4; o++) {
                    var inp = row.querySelector('input[name="questions[' + idx + '][options][' + o + ']"]');
                    if (inp) { inp.value = q.options[o] || ''; }
                }
                Array.prototype.forEach.call(row.querySelectorAll('input[name="questions[' + idx + '][correct_index]"]'), function (r) {
                    r.checked = (parseInt(r.value, 10) === parseInt(q.correct_index, 10));
                });
                var marks = row.querySelector('input[name="questions[' + idx + '][marks]"]');
                if (marks) { marks.value = q.marks; }
            });
            // Bulk mode: the inline rows carry required attributes for
            // inline mode, but a filled bulk box must still submit —
            // drop those requirements (server validation still runs).
            document.getElementById('quiz-create-form').addEventListener('submit', function () {
                var bulk = document.getElementById('bulk_text');
                if (bulk && bulk.value.trim() !== '') {
                    Array.prototype.forEach.call(rowsBox.querySelectorAll('[required]'), function (el) {
                        el.removeAttribute('required');
                    });
                }
            });
        })();
    </script>
@endsection
