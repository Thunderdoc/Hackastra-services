@extends('layouts.master')
@section('page_title', 'Quizzes')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Quizzes
@endsection
@section('page_actions')
    @if (Qs::userIsTeacher())
        <a href="{{ route('quizzes.create') }}" class="btn btn-primary">New Quiz</a>
    @endif
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Quizzes</h5>
            @if (Qs::userIsTeacher())
                <a href="{{ route('quizzes.create') }}" class="btn btn-primary btn-sm float-right">New Quiz</a>
            @endif
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif
            @if ($quizzes->isEmpty())
                <p class="text-muted">No quizzes yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Questions</th>
                                <th>Time Limit</th>
                                <th>Status</th>
                                <th>Your Result</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quizzes as $quiz)
                                <tr>
                                    <td>{{ $quiz->title }}</td>
                                    <td>{{ $quiz->subject ? $quiz->subject->name : '-' }}</td>
                                    <td>{{ $quiz->my_class ? $quiz->my_class->name : '-' }}</td>
                                    <td>{{ $quiz->section ? $quiz->section->name : 'All sections' }}</td>
                                    <td>{{ $quiz->questions_count }}</td>
                                    <td>{{ $quiz->time_limit_minutes ? $quiz->time_limit_minutes . ' min' : 'No limit' }}</td>
                                    <td>{{ ucfirst($quiz->status) }}</td>
                                    <td>
                                        @if (isset($attempts[$quiz->id]))
                                            {{ $attempts[$quiz->id]->score }} / {{ $attempts[$quiz->id]->total }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('quizzes.show', $quiz->id) }}" class="btn btn-light btn-sm">View</a>
                                        @if (Qs::userIsStudent() && !isset($attempts[$quiz->id]))
                                            <a href="{{ route('quizzes.attempt', $quiz->id) }}" class="btn btn-primary btn-sm">Attempt</a>
                                        @endif
                                        @if ((Qs::userIsTeacher() || Qs::userIsAdmin()))
                                            <a href="{{ route('quizzes.results', $quiz->id) }}" class="btn btn-light btn-sm">Results</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
