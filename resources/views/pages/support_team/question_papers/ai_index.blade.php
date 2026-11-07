@extends('layouts.master')
@section('page_title', 'AI Question Review Queue')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('question_papers.index') }}">Question Papers</a> &nbsp;/&nbsp; AI Review Queue
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">AI Suggestions — Review Queue</h5>
            <div class="header-elements">
                <a href="{{ route('question_papers.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-2"></i> Generate Question Paper</a>
            </div>
        </div>
        <div class="card-body">
            @if($ai_state === 'off')
                <div class="alert alert-info">AI question generation is switched off. Bank-only generation works fully without it.</div>
            @elseif($ai_state === 'no_key')
                <div class="alert alert-info">AI is switched on but no API key is configured yet, so real AI generation isn't available. Bank-only generation works fully without it.</div>
            @endif
            <p class="text-muted">AI suggests, you decide. A pending suggestion is never printed and never enters a paper or your bank until you approve it here — one question at a time.</p>

            @forelse($pending as $s)
                <div class="card border mb-3">
                    <div class="card-body">
                        <p class="mb-1">
                            <span class="badge badge-info">{{ strtoupper($s->question_type) }}</span>
                            <span class="badge badge-light">{{ optional($s->my_class)->name }} · {{ optional($s->subject)->name }}</span>
                            @if($s->chapter)<span class="badge badge-light">Chapter: {{ $s->chapter }}</span>@endif
                            @if($s->difficulty)<span class="badge badge-light">{{ ucfirst($s->difficulty) }}</span>@endif
                            @if($s->paper)<span class="badge badge-light">For: {{ $s->paper->title }}</span>@endif
                            <span class="text-muted" style="font-size:12px">via {{ $s->ai_model }}</span>
                        </p>
                        <form method="post" action="{{ route('question_papers.ai_update', $s->id) }}">
                            @csrf
                            @method('PUT')
                            <div class="form-group">
                                <label>Question</label>
                                <textarea name="question" class="form-control" rows="2" required>{{ $s->question }}</textarea>
                            </div>
                            @if($s->question_type === 'mcq')
                                @for($i = 0; $i < 4; $i++)
                                    <div class="form-group">
                                        <label>Option {{ $i + 1 }}</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><input type="radio" name="correct_index" value="{{ $i }}" {{ (int) $s->correct_index === $i ? 'checked' : '' }}></span>
                                            </div>
                                            <input type="text" name="options[]" class="form-control" value="{{ $s->options[$i] ?? '' }}">
                                        </div>
                                    </div>
                                @endfor
                            @else
                                <div class="form-group">
                                    <label>Model Answer</label>
                                    <textarea name="model_answer" class="form-control" rows="3">{{ $s->model_answer }}</textarea>
                                </div>
                            @endif
                            <div class="row">
                                <div class="col-md-2">
                                    <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" min="1" value="{{ $s->marks }}"></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group"><label>Chapter</label><input type="text" name="chapter" class="form-control" maxlength="120" value="{{ $s->chapter }}"></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Difficulty</label>
                                        <select name="difficulty" class="form-control">
                                            <option value="">Unrated</option>
                                            @foreach(['easy','medium','hard'] as $d)<option value="{{ $d }}" {{ $s->difficulty === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>@endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-light btn-sm mb-3">Save Edit (stays pending)</button>
                                </div>
                            </div>
                        </form>
                        <form method="post" action="{{ route('question_papers.ai_approve', $s->id) }}" class="form-inline">
                            @csrf
                            <select name="target" class="form-control mr-2 mb-2">
                                <option value="bank">Approve → save to my Question Bank</option>
                                <option value="paper">Approve → add to a draft paper</option>
                                <option value="both">Approve → both</option>
                            </select>
                            <select name="question_paper_id" class="form-control mr-2 mb-2">
                                <option value="">Draft paper (for paper/both)…</option>
                                @foreach($my_papers as $p)
                                    @if($p->status === 'draft')<option value="{{ $p->id }}" {{ (int) $s->question_paper_id === (int) $p->id ? 'selected' : '' }}>{{ $p->title }}</option>@endif
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-success btn-sm mb-2">Approve</button>
                        </form>
                        <form method="post" action="{{ route('question_papers.ai_reject', $s->id) }}" data-confirm="Reject this suggestion? Rejection is final for this generation." data-confirm-title="Reject Suggestion" data-confirm-action="Reject" data-confirm-danger>
                            @csrf
                            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                        </form>
                    </div>
                </div>
            @empty
                @include('partials.patterns.empty_state', ['icon' => 'icon-file-text2', 'message' => 'No pending suggestions. Generate questions from the Question Paper create page, then review them here.'])
            @endforelse
        </div>
    </div>

    @if($history->count())
        <div class="card mt-3">
            <div class="card-header"><h5 class="card-title">Reviewed History</h5></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table hk-std hk-std-qpapers">
                        <thead>
                            <tr><th>Question</th><th>Type</th><th>Class</th><th>Subject</th><th>Status</th><th>Reviewed</th><th>Bank Row</th></tr>
                        </thead>
                        <tbody>
                            @foreach($history as $s)
                                <tr>
                                    <td>{{ \Illuminate\Support\Str::limit($s->question, 90) }}</td>
                                    <td>{{ strtoupper($s->question_type) }}</td>
                                    <td>{{ optional($s->my_class)->name }}</td>
                                    <td>{{ optional($s->subject)->name }}</td>
                                    <td>@if($s->status === 'approved')<span class="badge badge-success">Approved</span>@else<span class="badge badge-danger">Rejected</span>@endif</td>
                                    <td>{{ $s->reviewed_at ? $s->reviewed_at->format('d M Y') : '—' }}</td>
                                    <td>{{ $s->bank_question_id ? '#' . $s->bank_question_id : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
