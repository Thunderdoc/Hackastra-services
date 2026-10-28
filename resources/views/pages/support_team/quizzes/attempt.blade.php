@extends('layouts.master')
@section('page_title', $quiz->title)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('quizzes.index') }}">Quizzes</a> &nbsp;/&nbsp; {{ $quiz->title }}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">{{ $quiz->title }}</h5>
            @if ($quiz->time_limit_minutes)
                <span class="float-right">Time left: <strong id="quiz-countdown" data-seconds="{{ $quiz->time_limit_minutes * 60 }}">{{ $quiz->time_limit_minutes }}:00</strong></span>
            @endif
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('quizzes.submit', $quiz->id) }}" id="attempt-form">
                @csrf
                @foreach ($quiz->questions as $n => $question)
                    <div class="card card-body mb-3">
                        <p><strong>Q{{ $n + 1 }}.</strong> {{ $question->question }} <span class="text-muted">({{ $question->marks }} mark(s))</span></p>
                        @foreach (($question->options ?: []) as $i => $opt)
                            <div class="form-check">
                                <label class="form-check-label">
                                    <input type="radio" name="answers[{{ $question->id }}]" value="{{ $i }}" class="form-check-input">
                                    {{ $opt }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary">Submit Quiz</button>
            </form>
        </div>
    </div>
@endsection
@section('scripts')
    <script>
        (function () {
            var el = document.getElementById('quiz-countdown');
            if (!el) { return; }
            var left = parseInt(el.getAttribute('data-seconds'), 10);
            var form = document.getElementById('attempt-form');
            var timer = setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    clearInterval(timer);
                    el.textContent = '0:00';
                    form.submit();
                    return;
                }
                var m = Math.floor(left / 60);
                var s = left % 60;
                el.textContent = m + ':' + (s < 10 ? '0' + s : s);
            }, 1000);
        })();
    </script>
@endsection
