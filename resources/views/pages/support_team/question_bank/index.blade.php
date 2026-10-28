@extends('layouts.master')
@section('page_title', 'Question Bank')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Question Bank
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">My Question Bank</h5>
            <div class="header-elements">
                <a href="{{ route('question_bank.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-2"></i> Add Question</a>
            </div>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('question_bank.index') }}" class="form-inline mb-3">
                <select name="class_id" class="form-control mr-2 mb-2">
                    <option value="">All my classes</option>
                    @foreach($my_classes as $c)
                        <option value="{{ $c->id }}" {{ (string) $selected_class_id === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                <select name="subject_id" class="form-control mr-2 mb-2">
                    <option value="">All my subjects</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}" {{ (string) $selected_subject_id === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary btn-sm mb-2">Filter</button>
            </form>
            @if($questions->count())
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Subject</th>
                                <th>Marks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($questions as $q)
                                <tr>
                                    <td>{{ $q->question }}</td>
                                    <td>{{ optional($q->my_class)->name }}</td>
                                    <td>{{ optional($q->section)->name ?? 'Whole class' }}</td>
                                    <td>{{ optional($q->subject)->name }}</td>
                                    <td>{{ $q->marks }}</td>
                                    <td class="text-nowrap">
                                        <a href="{{ route('question_bank.edit', $q->id) }}" class="btn btn-sm btn-primary">Edit</a>
                                        <form method="post" action="{{ route('question_bank.destroy', $q->id) }}" style="display:inline" onsubmit="return confirm('Delete this question from your bank?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted mb-0">Your bank is empty. Add your first question to reuse it in quizzes later.</p>
            @endif
        </div>
    </div>
@endsection
