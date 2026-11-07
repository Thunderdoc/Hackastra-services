@extends('layouts.master')
@section('page_title', 'Question Paper')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('question_papers.index') }}">Question Papers</a> &nbsp;/&nbsp; {{ $paper->title }}
@endsection
@section('content')
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    @include('pages.support_team.question_papers.partials.paper_document')
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Paper Details</h5>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>Title:</strong> {{ $paper->title }}</p>
                    <p class="mb-1"><strong>Status:</strong>
                        @if($paper->status === 'final')<span class="badge badge-success">Final</span>@else<span class="badge badge-warning">Draft</span>@endif
                    </p>
                    <p class="mb-1"><strong>Class:</strong> {{ optional($paper->my_class)->name }}{{ $paper->section ? ' · Section ' . $paper->section->name : ' · All sections' }}</p>
                    <p class="mb-1"><strong>Subject:</strong> {{ optional($paper->subject)->name }}</p>
                    <p class="mb-1"><strong>Exam:</strong> {{ $paper->examName() ?: '—' }}</p>
                    <p class="mb-1"><strong>Total Marks:</strong> {{ $paper->total_marks }}</p>
                    <p class="mb-1"><strong>Seed:</strong> <code>{{ $paper->seed }}</code></p>
                    <p class="mb-1"><strong>Repeat questions allowed:</strong> {{ $paper->allow_repeat_questions ? 'Yes (audited)' : 'No' }}</p>
                    <p class="mb-1"><strong>Source:</strong> {{ ($paper->spec['ai_assisted'] ?? false) ? 'Bank + AI (teacher-approved)' : 'Bank only' }}</p>
                    <p class="mb-1"><strong>Filed PDF:</strong> {{ $filed ? 'Filed ✓ ' . $paper->pdf_generated_at->format('d M Y') : 'Not filed' }}</p>

                    <h6 class="mt-3">Spec</h6>
                    @foreach(($paper->spec['sections'] ?? []) as $s)
                        <p class="mb-1">Section {{ $s['key'] }} — {{ $s['label'] }}: {{ $s['count'] }} × {{ strtoupper($s['type']) }}
                            @if(!empty($s['marks_each'])) ({{ $s['marks_each'] }} marks each) @endif
                            @if(!empty($s['marks_total'])) ({{ $s['marks_total'] }} marks total) @endif
                            @if(!empty($s['chapters'])) · Chapters: {{ implode(', ', $s['chapters']) }} @endif
                        </p>
                    @endforeach

                    @if($paper->hasShortfall())
                        <div class="alert alert-danger mt-3">
                            <strong>Shortfall recorded — this draft cannot be finalised.</strong>
                            @foreach($paper->spec['shortfall'] as $sf)
                                <p class="mb-1">{{ $sf['message'] ?? '' }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-3">
                        <a href="{{ route('question_papers.pdf', $paper->id) }}" class="btn btn-info btn-sm mb-2">Paper PDF</a>
                        <a href="{{ route('question_papers.answer_key', $paper->id) }}" class="btn btn-secondary btn-sm mb-2">Answer Key PDF</a>
                        <a href="{{ route('question_papers.print', $paper->id) }}" class="btn btn-light btn-sm mb-2" target="_blank">Print View</a>
                    </div>
                    @if($is_owner)
                        <div class="mt-2">
                            <form method="post" action="{{ route('question_papers.save_pdf', $paper->id) }}" class="mb-2">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm">Save / File PDF</button>
                            </form>
                            @if($paper->isDraft())
                                <form method="post" action="{{ route('question_papers.regenerate', $paper->id) }}" class="mb-2" data-confirm="Regenerate the bank selection with a new seed? Questions you approved from AI stay; bank questions are re-drawn." data-confirm-title="Regenerate Paper" data-confirm-action="Regenerate">
                                    @csrf
                                    <button type="submit" class="btn btn-warning btn-sm">Regenerate (new seed)</button>
                                </form>
                                <form method="post" action="{{ route('question_papers.finalise', $paper->id) }}" class="mb-2" data-confirm="Finalise this paper? The snapshot freezes and the PDF is filed." data-confirm-title="Finalise Paper" data-confirm-action="Finalise">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">Finalise Paper</button>
                                </form>
                            @endif
                            <form method="post" action="{{ route('question_papers.destroy', $paper->id) }}" data-confirm="Delete this question paper, its snapshot and its filed PDFs?" data-confirm-title="Delete Question Paper" data-confirm-action="Delete" data-confirm-danger>
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete Paper</button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
