@extends('layouts.master')
@section('page_title', 'Add Bank Question')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('question_bank.index') }}">Question Bank</a> &nbsp;/&nbsp; Add Question
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Add Question to Your Bank</h5>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('question_bank.store') }}">
                @csrf
                <div class="form-group">
                    <label for="my_class_id">Class</label>
                    <select id="my_class_id" name="my_class_id" class="form-control" required>
                        <option value="">Select Class</option>
                        @foreach($my_classes as $c)
                            <option value="{{ $c->id }}" {{ (string) old('my_class_id') === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="section_id">Section (optional — leave blank for the whole class)</label>
                    <select id="section_id" name="section_id" class="form-control">
                        <option value="">Whole class</option>
                        @foreach($sections as $sec)
                            <option value="{{ $sec->id }}" {{ (string) old('section_id') === (string) $sec->id ? 'selected' : '' }}>{{ optional($sec->my_class)->name ?? '' }} {{ $sec->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="subject_id">Subject</label>
                    <select id="subject_id" name="subject_id" class="form-control" required>
                        <option value="">Select Subject</option>
                        @foreach($subjects as $s)
                            <option value="{{ $s->id }}" {{ (string) old('subject_id') === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="question">Question</label>
                    <textarea id="question" name="question" class="form-control" rows="3" required>{{ old('question') }}</textarea>
                </div>
                @for($i = 0; $i < 4; $i++)
                    <div class="form-group">
                        <label>Option {{ $i + 1 }}</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><input type="radio" name="correct_index" value="{{ $i }}" {{ (string) old('correct_index', '0') === (string) $i ? 'checked' : '' }}></span>
                            </div>
                            <input type="text" name="options[]" class="form-control" value="{{ old('options.' . $i) }}" placeholder="Option {{ $i + 1 }} — tick the radio for the correct one">
                        </div>
                    </div>
                @endfor
                <div class="form-group">
                    <label for="marks">Marks</label>
                    <input id="marks" type="number" name="marks" class="form-control" value="{{ old('marks', 1) }}" min="1" required>
                </div>
                <a href="{{ route('question_bank.index') }}" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary">Add to Bank</button>
            </form>
        </div>
    </div>
@endsection
