@extends('layouts.master')
@section('page_title', 'Review: ' . $quiz->title)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('quizzes.index') }}">Quizzes</a> &nbsp;/&nbsp; <a href="{{ route('quizzes.show', $quiz->id) }}">{{ $quiz->title }}</a> &nbsp;/&nbsp; Review
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Review: {{ $quiz->title }}</h5>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
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
                &middot; {{ $quiz->questions->count() }} question(s)
                &middot; {{ $attemptsCount }} attempt(s)
            </p>
            <p>
                <span class="badge {{ $quiz->status === 'published' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($quiz->status) }}</span>
                @if ($quiz->available_from || $quiz->available_until)
                    &nbsp; Window: {{ $quiz->available_from ? $quiz->available_from->format('d M Y, h:i A') : 'open' }} — {{ $quiz->available_until ? $quiz->available_until->format('d M Y, h:i A') : 'open' }}
                @endif
                @if ($quiz->time_limit_minutes)
                    &nbsp; Time limit: {{ $quiz->time_limit_minutes }} min
                @endif
            </p>

            @if (!$canEdit && $attemptsCount > 0)
                <div class="alert alert-warning">Questions and timings are locked — this quiz already has {{ $attemptsCount }} attempt(s).</div>
            @endif

            <h6>Questions</h6>
            @forelse ($quiz->questions as $question)
                <div class="card card-body mb-3">
                    @if ($canEdit)
                        <form method="post" action="{{ route('quizzes.questions.update', [$quiz->id, $question->id]) }}">
                            @csrf
                            @method('PUT')
                            <div class="form-group">
                                <label>Question</label>
                                <textarea name="question" class="form-control" rows="2" required>{{ $question->question }}</textarea>
                            </div>
                            @foreach (($question->options ?: []) as $i => $opt)
                                <div class="form-group">
                                    <label>Option {{ $i + 1 }}</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <div class="input-group-text">
                                                <input type="radio" name="correct_index" value="{{ $i }}" {{ $i === (int) $question->correct_index ? 'checked' : '' }} title="Mark as correct">
                                            </div>
                                        </div>
                                        <input type="text" name="options[{{ $i }}]" class="form-control" value="{{ $opt }}" required>
                                    </div>
                                </div>
                            @endforeach
                            <div class="form-group">
                                <label>Marks</label>
                                <input type="number" name="marks" class="form-control" value="{{ $question->marks }}" min="1" max="100">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Save question</button>
                        </form>
                        <form method="post" action="{{ route('quizzes.questions.destroy', [$quiz->id, $question->id]) }}" class="mt-2">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-light btn-sm">Delete question</button>
                        </form>
                    @else
                        <p class="mb-1"><strong>{{ $question->question }}</strong> ({{ $question->marks }} mark(s))</p>
                        <ul class="mb-0">
                            @foreach (($question->options ?: []) as $i => $opt)
                                <li>{{ $opt }} @if ($i === (int) $question->correct_index) <strong>(correct)</strong> @endif</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="text-muted">No questions yet.</p>
            @endforelse

            @if ($canEdit)
                <h6 class="mt-4">Add a question</h6>
                <div class="card card-body mb-3">
                    <form method="post" action="{{ route('quizzes.questions.store', $quiz->id) }}">
                        @csrf
                        <div class="form-group">
                            <label>Question</label>
                            <textarea name="question" class="form-control" rows="2" required></textarea>
                        </div>
                        @for ($o = 0; $o < 4; $o++)
                            <div class="form-group">
                                <label>Option {{ $o + 1 }}</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <input type="radio" name="correct_index" value="{{ $o }}" {{ $o === 0 ? 'checked' : '' }} title="Mark as correct">
                                        </div>
                                    </div>
                                    <input type="text" name="options[{{ $o }}]" class="form-control" {{ $o < 2 ? 'required' : '' }}>
                                </div>
                            </div>
                        @endfor
                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="marks" class="form-control" value="1" min="1" max="100">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Add question</button>
                    </form>
                </div>

                <h6 class="mt-4">{{ $quiz->status === 'published' ? 'Update timings' : 'Publish' }}</h6>
                <div class="card card-body mb-3">
                    <form method="post" action="{{ route('quizzes.publish', $quiz->id) }}">
                        @csrf
                        <div class="form-group">
                            <label for="available_from">Available from (leave blank for immediately)</label>
                            <input type="datetime-local" name="available_from" id="available_from" class="form-control" value="{{ $quiz->available_from ? $quiz->available_from->format('Y-m-d\TH:i') : '' }}">
                        </div>
                        <div class="form-group">
                            <label for="available_until">Available until (leave blank for no end)</label>
                            <input type="datetime-local" name="available_until" id="available_until" class="form-control" value="{{ $quiz->available_until ? $quiz->available_until->format('Y-m-d\TH:i') : '' }}">
                        </div>
                        <div class="form-group">
                            <label for="time_limit_minutes">Time limit (minutes, optional — the attempt page counts down and auto-submits)</label>
                            <input type="number" name="time_limit_minutes" id="time_limit_minutes" class="form-control" value="{{ $quiz->time_limit_minutes }}" min="1" max="600">
                        </div>
                        <button type="submit" class="btn btn-primary">{{ $quiz->status === 'published' ? 'Update timings' : 'Publish quiz' }}</button>
                    </form>
                </div>

                @if ($quiz->status === 'published')
                    <form method="post" action="{{ route('quizzes.unpublish', $quiz->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-warning">Unpublish (back to draft)</button>
                    </form>
                @endif
            @endif

            <div class="mt-3">
                <a href="{{ route('quizzes.show', $quiz->id) }}" class="btn btn-light">Back to Quiz</a>
                <a href="{{ route('quizzes.results', $quiz->id) }}" class="btn btn-light">Results</a>
            </div>
        </div>
    </div>
@endsection
