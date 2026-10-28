@extends('layouts.master')
@section('page_title', 'Results: ' . $quiz->title)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('quizzes.index') }}">Quizzes</a> &nbsp;/&nbsp; <a href="{{ route('quizzes.show', $quiz->id) }}">{{ $quiz->title }}</a> &nbsp;/&nbsp; Results
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Results: {{ $quiz->title }}</h5>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif
            <p class="text-muted">
                {{ $quiz->subject ? $quiz->subject->name : '' }}
                @if ($quiz->my_class)
                    &middot; {{ $quiz->my_class->name }}
                @endif
                @if ($quiz->section)
                    &middot; Section {{ $quiz->section->name }}
                @endif
                &middot; {{ $attempts->count() }} attempt(s)
            </p>
            @if ($attempts->isEmpty())
                <p class="text-muted">No attempts yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Score</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attempts as $attempt)
                                <tr>
                                    <td>{{ $attempt->student_record && $attempt->student_record->user ? $attempt->student_record->user->name : 'Student #' . $attempt->student_record_id }}</td>
                                    <td>{{ $attempt->score }} / {{ $attempt->total }}</td>
                                    <td>{{ $attempt->submitted_at ? $attempt->submitted_at->format('d M Y, h:i A') : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @foreach ($attempts as $attempt)
                    <h6 class="mt-4">Per-question correctness: {{ $attempt->student_record && $attempt->student_record->user ? $attempt->student_record->user->name : 'Student #' . $attempt->student_record_id }} ({{ $attempt->score }} / {{ $attempt->total }})</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Question</th>
                                    <th>Selected</th>
                                    <th>Correct</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($attempt->answers as $answer)
                                    @php
                                        $q = $answer->question;
                                        $opts = $q ? ($q->options ?: []) : [];
                                    @endphp
                                    <tr>
                                        <td>{{ $q ? $q->question : '-' }}</td>
                                        <td>{{ $answer->selected_index !== null && isset($opts[$answer->selected_index]) ? $opts[$answer->selected_index] : 'Not answered' }}</td>
                                        <td>{{ $q && isset($opts[$q->correct_index]) ? $opts[$q->correct_index] : '-' }}</td>
                                        <td>{{ $answer->is_correct ? 'Correct' : 'Wrong' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endif
            <a href="{{ route('quizzes.show', $quiz->id) }}" class="btn btn-light">Back to Quiz</a>
            @if ($canPush)
                <div class="card mt-4">
                    <div class="card-header"><h6 class="card-title mb-0">Use for Internal</h6></div>
                    <div class="card-body">
                        <p class="text-muted">Pushes each attempted student's quiz percentage onto the Internal component of the chosen exam (scaled to that component's max). Re-pushing overwrites — never duplicates. Blocked when the exam's results are published, and unavailable for legacy-scheme subjects with no configured Internal component.</p>
                        <form method="post" action="{{ route('quizzes.push_internal', $quiz->id) }}">
                            @csrf
                            <div class="form-group">
                                <label for="exam_id">Exam</label>
                                <select name="exam_id" id="exam_id" class="form-control" required>
                                    <option value="">Select exam</option>
                                    @foreach ($exams as $exam)
                                        @php $comp = $internalAvailability[$exam->id] ?? null; @endphp
                                        <option value="{{ $exam->id }}">{{ $exam->name }} ({{ $exam->year }}) — {{ $comp ? 'Internal available (max ' . $comp['max_marks'] . ')' : 'legacy scheme — no Internal component' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Push to Internal</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
