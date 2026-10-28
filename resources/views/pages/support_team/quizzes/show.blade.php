@extends('layouts.master')
@section('page_title', $quiz->title)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('quizzes.index') }}">Quizzes</a> &nbsp;/&nbsp; {{ $quiz->title }}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">{{ $quiz->title }}</h5>
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
                @else
                    &middot; All sections
                @endif
                @if ($quiz->time_limit_minutes)
                    &middot; Time limit: {{ $quiz->time_limit_minutes }} min
                @endif
            </p>
            <p>
                <span class="badge {{ $quiz->status === 'published' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($quiz->status) }}</span>
                @if ($quiz->available_from || $quiz->available_until)
                    &nbsp; Window: {{ $quiz->available_from ? $quiz->available_from->format('d M Y, h:i A') : 'open' }} — {{ $quiz->available_until ? $quiz->available_until->format('d M Y, h:i A') : 'open' }}
                @endif
            </p>

            @if ($myAttempt)
                <div class="alert alert-success">
                    Your score: {{ $myAttempt->score }} / {{ $myAttempt->total }}
                    (submitted {{ $myAttempt->submitted_at ? $myAttempt->submitted_at->format('d M Y, h:i A') : '' }})
                </div>
                <h6>Your answers</h6>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Your answer</th>
                                <th>Correct answer</th>
                                <th>Marks</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($myAttempt->answers as $answer)
                                @php
                                    $q = $answer->question;
                                    $opts = $q ? ($q->options ?: []) : [];
                                @endphp
                                <tr>
                                    <td>{{ $q ? $q->question : '-' }}</td>
                                    <td>{{ $answer->selected_index !== null && isset($opts[$answer->selected_index]) ? $opts[$answer->selected_index] : 'Not answered' }}</td>
                                    <td>{{ $q && isset($opts[$q->correct_index]) ? $opts[$q->correct_index] : '-' }}</td>
                                    <td>{{ $q ? ($answer->is_correct ? $q->marks : 0) . ' / ' . $q->marks : '-' }}</td>
                                    <td>{{ $answer->is_correct ? 'Correct' : 'Wrong' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif (Qs::userIsStudent())
                <p>{{ $quiz->questions->count() }} question(s), total {{ $quiz->questions->sum('marks') }} mark(s).</p>
                <a href="{{ route('quizzes.attempt', $quiz->id) }}" class="btn btn-primary">Start Attempt</a>
            @else
                <h6>Questions ({{ $quiz->questions->count() }})</h6>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Options (correct marked)</th>
                                <th>Marks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quiz->questions as $question)
                                <tr>
                                    <td>{{ $question->question }}</td>
                                    <td>
                                        <ul class="mb-0">
                                            @foreach (($question->options ?: []) as $i => $opt)
                                                <li>{{ $opt }} @if ($i === (int) $question->correct_index) <strong>(correct)</strong> @endif</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td>{{ $question->marks }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <a href="{{ route('quizzes.results', $quiz->id) }}" class="btn btn-primary">View Results</a>
                @if (Qs::userIsTeacher() && (int) $quiz->teacher_id === (int) auth()->id())
                    <a href="{{ route('quizzes.review', $quiz->id) }}" class="btn btn-primary">Review & Publish</a>
                @endif
            @endif
            <a href="{{ route('quizzes.index') }}" class="btn btn-light">Back to Quizzes</a>
        </div>
    </div>
@endsection
