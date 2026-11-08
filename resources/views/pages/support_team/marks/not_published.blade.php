@extends('layouts.master')
@section('page_title', 'Results Not Published')
@section('content')

    {{-- Workstream BH — honest empty state for the Result Publish
         gate: a student/parent lands here when the exam's results
         have not been published by the school yet. No marks data is
         passed to this view at all.
         Publication gate (owner order 2026-11-08): the body is now
         the ONE shared not-published partial every gated document
         uses (marksheet, admit card, exam date sheet) — same look,
         same wording. The heading keeps its established text. --}}
    <div class="card">
        <div class="card-body py-4">
            @include('partials.patterns.not_published', [
                'document' => 'Marksheet',
                'icon' => 'icon-eye-blocked',
                'heading' => 'Results Not Published Yet',
                'detail' => !empty($exam)
                    ? 'Results for ' . $exam->name . ' (' . $year . ')' . (!empty($sr) && !empty($sr->user) ? ' for ' . $sr->user->name : '') . ' have not been published yet.'
                    : 'No results have been published for the ' . $year . ' session yet.',
            ])
            <div class="text-center">
                <a href="{{ route('dashboard') }}" class="btn btn-primary mt-2">Back to Dashboard</a>
            </div>
        </div>
    </div>

@endsection
