@extends('layouts.master')
@section('page_title', 'Lesson Plan')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('lesson_plans.index') }}">Lesson Plans</a> &nbsp;/&nbsp; Topic
@endsection
@section('page_actions')
    @if($isTeacher)
        <form method="post" action="{{ route('lesson_plans.destroy', $plan->id) }}" onsubmit="return confirm('Delete this lesson plan?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
    @endif
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">{{ $plan->topic }}</h5>
        </div>
        <div class="card-body">
            <p><strong>Class:</strong> {{ optional($plan->my_class)->name }}@if($plan->section) - Section {{ $plan->section->name }}@endif</p>
            <p><strong>Subject:</strong> {{ optional($plan->subject)->name }}</p>
            <p><strong>Week starting:</strong> {{ optional($plan->week_start)->format('d M Y') }} (Monday)</p>
            <p><strong>Teacher:</strong> {{ optional($plan->teacher)->name }}</p>
            @if($plan->notes)
                <p><strong>Notes:</strong> {{ $plan->notes }}</p>
            @endif
            <p>
                <strong>Taught status:</strong>
                @if($covered)<span class="badge badge-success">Covered</span>
                @else<span class="badge badge-secondary">Not covered yet</span>@endif
            </p>
            @if($covered && $matchingTopic)
                <p class="text-muted">Matched taught topic from attendance: {{ $matchingTopic }}</p>
            @endif
            <hr>
            <p class="mb-1">Syllabus completion for this class and subject: {{ $stats['percent'] }}% ({{ $stats['covered'] }} of {{ $stats['planned'] }} topics covered)</p>
            @if(!$stats['has_sessions'])
                <p class="text-muted mb-0">No attendance recorded yet for this class and subject.</p>
            @endif
        </div>
    </div>
@endsection
