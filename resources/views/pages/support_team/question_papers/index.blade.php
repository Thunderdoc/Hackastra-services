@extends('layouts.master')
@section('page_title', 'Question Papers')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Question Papers
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Question Papers</h5>
            @unless($is_admin)
                <div class="header-elements">
                    <a href="{{ route('question_papers.ai_index') }}" class="btn btn-info btn-sm mr-2"><i class="icon-magic-wand mr-2"></i> AI Review Queue</a>
                    <a href="{{ route('question_papers.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-2"></i> Generate Question Paper</a>
                </div>
            @endunless
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('question_papers.index') }}" class="form-inline mb-3">
                <select name="class_id" class="form-control mr-2 mb-2">
                    <option value="">All classes</option>
                    @foreach($my_classes as $c)
                        <option value="{{ $c->id }}" {{ (string) $selected_class_id === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                <select name="subject_id" class="form-control mr-2 mb-2">
                    <option value="">All subjects</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}" {{ (string) $selected_subject_id === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
                <select name="exam_id" class="form-control mr-2 mb-2">
                    <option value="">All exams</option>
                    @foreach($exams as $e)
                        <option value="{{ $e->id }}" {{ (string) $selected_exam_id === (string) $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="form-control mr-2 mb-2">
                    <option value="">Any status</option>
                    <option value="draft" {{ $selected_status === 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="final" {{ $selected_status === 'final' ? 'selected' : '' }}>Final</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm mb-2">Filter</button>
            </form>

            @if($papers->count())
                <div class="table-responsive">
                    <table class="table hk-std hk-std-qpapers">
                        {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                        @if($is_admin)<colgroup><col style="width:18%"><col style="width:10%"><col style="width:7%"><col style="width:10%"><col style="width:11%"><col style="width:7%"><col style="width:8%"><col style="width:6%"><col style="width:7%"><col style="width:4%"><col style="width:5%"><col style="width:7%"></colgroup>@else<colgroup><col style="width:20%"><col style="width:11%"><col style="width:8%"><col style="width:12%"><col style="width:8%"><col style="width:9%"><col style="width:7%"><col style="width:8%"><col style="width:5%"><col style="width:5%"><col style="width:7%"></colgroup>@endif
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Exam</th>
                                <th>Class</th>
                                <th>Subject</th>
                                @if($is_admin)<th>Teacher</th>@endif
                                <th>Questions</th>
                                <th>Total Marks</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Filed</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($papers as $p)
                                <tr>
                                    <td>{{ $p->title }}</td>
                                    <td>{{ $p->examName() ?: '—' }}</td>
                                    <td>{{ optional($p->my_class)->name }}{{ $p->section ? ' · ' . $p->section->name : '' }}</td>
                                    <td>{{ optional($p->subject)->name }}</td>
                                    @if($is_admin)<td>{{ optional($p->teacher)->name }}</td>@endif
                                    <td>{{ $p->questions_count }}</td>
                                    <td>{{ $p->total_marks }}</td>
                                    <td>{{ ($p->spec['ai_assisted'] ?? false) ? 'Bank + AI' : 'Bank' }}</td>
                                    <td>
                                        @if($p->status === 'final')
                                            <span class="badge badge-success">Final</span>
                                        @else
                                            <span class="badge badge-warning">Draft</span>
                                        @endif
                                        @if($p->hasShortfall())
                                            <span class="badge badge-danger">Shortfall</span>
                                        @endif
                                    </td>
                                    <td>{{ $p->pdf_path ? 'Filed ✓ ' . $p->pdf_generated_at->format('d M Y') : 'Not filed' }}</td>
                                    <td>{{ $p->created_at->format('d M Y') }}</td>
                                    <td class="text-nowrap">
                                        <a href="{{ route('question_papers.show', $p->id) }}" class="btn btn-sm btn-primary">View</a>
                                        <a href="{{ route('question_papers.pdf', $p->id) }}" class="btn btn-sm btn-info">Paper PDF</a>
                                        <a href="{{ route('question_papers.answer_key', $p->id) }}" class="btn btn-sm btn-secondary">Answer Key</a>
                                        @if(!$is_admin)
                                            <form method="post" action="{{ route('question_papers.destroy', $p->id) }}" style="display:inline" data-confirm="Delete question paper {{ $p->title }} and its snapshot? This cannot be undone." data-confirm-title="Delete Question Paper" data-confirm-action="Delete" data-confirm-danger>
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('partials.patterns.empty_state', ['icon' => 'icon-file-text2', 'message' => 'No papers yet — generate one from your Question Bank.'])
            @endif
        </div>
    </div>
@endsection
