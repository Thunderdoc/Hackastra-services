@extends('layouts.master')
@section('page_title', 'Assignment → Internal Marks')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('assignments.index') }}">Assignments</a> &nbsp;/&nbsp; <a href="{{ route('assignments.show', $assignment->id) }}">{{ \Illuminate\Support\Str::limit($assignment->title, 32) }}</a> &nbsp;/&nbsp; Internal Marks
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): internal-marks panel — the bridge from
         assignment grading to exam marks (owner directive: "the
         teacher can directly use the assignment average to give
         internal marks, any way the teacher wants").
         Step 1: pick an exam (and optionally an average mode: all,
         best N, or latest N, with N typed freely) → GET
         assignments.internal_preview reloads this panel with $preview
         rows (the controller computes, per student: graded_count,
         counted, average_percent, internal_marks = average scaled
         onto the exam's Internal component max). The average runs
         over the student's graded submissions across ALL of this
         teacher's assignments for the class + subject — or only the
         student's best N / latest N of them, when the teacher picks
         a mode and N.
         Step 2: POST assignments.push_internal writes those values
         into the exam's Internal component — behind an explicit
         confirm, because it writes real marks. --}}
    @php
        $exams = \App\Models\Exam::orderBy('year', 'desc')->orderBy('name')->get();
        $selectedExamId = $exam_id ?? request('exam_id');
        $selectedExam = $selectedExamId ? $exams->firstWhere('id', (int) $selectedExamId) : null;
    @endphp
    {{-- Loop 82: duplicate inline flash alerts removed — the app-wide banner renders each message exactly once. --}}

    <div class="card">
        <div class="card-body">
            <h4 class="mb-2">{{ $assignment->title }}</h4>
            <div class="mb-2">
                <span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($assignment->subject)->name ?? 'Subject —' }}</span>
                <span class="lms-chip"><i class="icon-windows2 mr-1"></i>{{ optional($assignment->myClass)->name ?? 'Class —' }}{{ $assignment->section ? ' · ' . optional($assignment->section)->name : ' · Whole class' }}</span>
                <span class="lms-chip"><i class="icon-pencil7 mr-1"></i>Max marks: {{ $assignment->max_marks }}</span>
            </div>
            <p class="text-muted mb-0">
                Pick the exam these marks should feed. The preview averages each student&rsquo;s
                <strong>graded</strong> submissions across <strong>all your assignments for this
                {{ optional($assignment->myClass)->name ?? 'class' }} {{ optional($assignment->subject)->name ?? 'subject' }}</strong>
                and scales the average onto the exam&rsquo;s Internal component — or, if you choose a
                mode below, only each student&rsquo;s best N or latest N graded assignments count.
                Review the preview first: pushing writes real marks.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h6 class="card-title">Step 1 — Choose the exam &amp; preview</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('assignments.internal_preview', $assignment->id) }}" class="pat-filter-form">
                <div class="pat-filter-field" style="flex:2 1 260px">
                    <label for="exam_id">Exam</label>
                    <select name="exam_id" id="exam_id" class="form-control" required>
                        <option value="">Select exam</option>
                        @foreach($exams as $exam)
                            <option value="{{ $exam->id }}" {{ (string) $selectedExamId === (string) $exam->id ? 'selected' : '' }}>{{ $exam->name }}@if($exam->term) · {{ $exam->term }}@endif @if($exam->year) · {{ $exam->year }}@endif</option>
                        @endforeach
                    </select>
                </div>
                @php $population = (int) ($population_count ?? 0); $chosenMode = $avg_mode ?? request('mode', 'all'); $chosenN = $avg_n ?? request('avg_n'); @endphp
                <div class="pat-filter-field" style="flex:1 1 220px">
                    <label for="mode">Average mode</label>
                    <select name="mode" id="mode" class="form-control">
                        <option value="all" {{ $chosenMode === 'all' ? 'selected' : '' }}>All graded assignments @if($population)({{ $population }} in this set)@endif</option>
                        <option value="best" {{ $chosenMode === 'best' ? 'selected' : '' }}>Average of best N</option>
                        <option value="latest" {{ $chosenMode === 'latest' ? 'selected' : '' }}>Average of latest N</option>
                    </select>
                </div>
                <div class="pat-filter-field" id="avg_n_wrap" style="flex:0 1 130px">
                    <label for="avg_n">N (for Best / Latest)</label>
                    <input name="avg_n" id="avg_n" type="number" min="1" step="1" value="{{ $chosenN ?? '' }}" class="form-control" placeholder="e.g. 3">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-search4 mr-1"></i> Load Preview</button>
                    <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-light ml-1"><i class="icon-arrow-left13 mr-1"></i> Back to Submissions</a>
                </div>
            </form>
            <script>
                // Batch fix 2026-10-25: N is meaningless for "All"
                // (the server ignores it) yet it sat on the form,
                // tall with its help text, shoving the other fields
                // down via the filter row's bottom alignment. Hide
                // it for All, show it only for Best / Latest.
                // jQuery on purpose: this select is Select2-enhanced,
                // and Select2 notifies via jQuery trigger, which a
                // vanilla change listener never receives.
                (function () {
                    var mode = document.getElementById('mode');
                    var wrap = document.getElementById('avg_n_wrap');
                    var input = document.getElementById('avg_n');
                    if (!mode || !wrap || !input) { return; }
                    function syncN() {
                        var show = mode.value === 'best' || mode.value === 'latest';
                        wrap.style.display = show ? '' : 'none';
                        input.disabled = !show;
                    }
                    if (window.jQuery) { jQuery(mode).on('change', syncN); }
                    else { mode.addEventListener('change', syncN); }
                    syncN();
                })();
            </script>

            @isset($preview)
                <div class="table-responsive mt-3">
                    <table class="table lms-table">
                        <thead>
                        <tr>
                            <th>Student</th>
                            <th>Graded Submissions</th>
                            <th>Counted in Average</th>
                            <th>Average</th>
                            <th>Internal Marks (scaled)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($preview as $row)
                            @php
                                $rowStudent = data_get($row, 'student');
                                $rowName = data_get($row, 'student_name') ?? optional($rowStudent)->name ?? 'Student';
                                $rowAdm = data_get($row, 'adm_no') ?? optional(optional($rowStudent)->student_record)->adm_no;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $rowName }}</strong>
                                    @if($rowAdm)<div class="text-muted">Adm No: {{ $rowAdm }}</div>@endif
                                </td>
                                <td>{{ data_get($row, 'graded_count', 0) }}</td>
                                <td>{{ data_get($row, 'counted', data_get($row, 'graded_count', 0)) }} of {{ data_get($row, 'graded_count', 0) }}</td>
                                <td>{{ data_get($row, 'average_percent') !== null ? rtrim(rtrim(number_format((float) data_get($row, 'average_percent'), 1), '0'), '.') . '%' : '—' }}</td>
                                <td><strong>{{ data_get($row, 'internal_marks', '—') }}</strong></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    @include('partials.patterns.empty_state', [
                                        'icon' => 'icon-stats-bars2',
                                        'message' => 'No students in this assignment&rsquo;s class yet.',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($selectedExam)
                    <div class="lms-push-bar">
                        <div class="text-muted">
                            Step 2 — write these internal marks into
                            <strong>{{ $selectedExam->name }}</strong> for
                            {{ optional($assignment->subject)->name ?? 'this subject' }}.
                            @if(!empty($avg_mode) && $avg_mode === 'best')
                                Averages use each student&rsquo;s <strong>best {{ $avg_n ?? 1 }}</strong> graded assignments only.
                            @elseif(!empty($avg_mode) && $avg_mode === 'latest')
                                Averages use each student&rsquo;s <strong>latest {{ $avg_n ?? 1 }}</strong> graded assignments only.
                            @else
                                Averages use <strong>all</strong> graded assignments.
                            @endif
                            Students with no graded submission are skipped.
                        </div>
                        <form method="post" action="{{ route('assignments.push_internal', $assignment->id) }}">
                            @csrf
                            <input type="hidden" name="exam_id" value="{{ $selectedExam->id }}">
                            <input type="hidden" name="mode" value="{{ $avg_mode ?? 'all' }}">
                            <input type="hidden" name="avg_n" value="{{ $avg_n ?? '' }}">
                            <button type="submit" class="btn btn-primary"
                                    data-confirm="This writes the previewed averages into the Internal component of {{ $selectedExam->name }} for every listed student. Existing internal marks for this subject will be overwritten. Continue?"
                                    data-confirm-title="Push Internal Marks"
                                    data-confirm-action="Push Marks">
                                <i class="icon-paperplane mr-1"></i> Confirm Push to {{ $selectedExam->name }}
                            </button>
                        </form>
                    </div>
                @endif
            @endisset
        </div>
    </div>
@endsection
