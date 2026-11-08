@extends('layouts.master')
@section('page_title', 'Lesson Plan')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('lesson_plans.index') }}">Lesson Plans</a> &nbsp;/&nbsp; Topic
@endsection
@section('page_actions')
    @if($isTeacher)
        <form method="post" action="{{ route('lesson_plans.destroy', $plan->id) }}" data-confirm="Delete lesson plan {{ $plan->topic }}? This cannot be undone." data-confirm-title="Delete Lesson Plan" data-confirm-action="Delete" data-confirm-danger>
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
            <div class="table-responsive">
                <table class="table hk-std hk-std-980">
            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
            <colgroup><col style="width:11%"><col style="width:13%"><col style="width:13%"><col style="width:15%"><col style="width:22%"><col style="width:12%"><col style="width:14%"></colgroup>
                    <tbody>
                        <tr>
                            <th style="width:220px">Class</th>
                            <td>{{ optional($plan->my_class)->name }}@if($plan->section) - Section {{ $plan->section->name }}@endif</td>
                        </tr>
                        <tr>
                            <th>Subject</th>
                            <td>{{ optional($plan->subject)->name }}</td>
                        </tr>
                        <tr>
                            <th>Week starting</th>
                            <td>{{ optional($plan->week_start)->format('d M Y') }} (Monday)</td>
                        </tr>
                        <tr>
                            <th>Teacher</th>
                            <td>{{ optional($plan->teacher)->name }}</td>
                        </tr>
                        @if($plan->notes)
                            <tr>
                                <th>Notes</th>
                                <td>{{ $plan->notes }}</td>
                            </tr>
                        @endif
                        <tr>
                            <th>Taught status</th>
                            <td>
                                @if($covered)<span class="badge badge-success">Covered</span>
                                @else<span class="badge badge-secondary">Not covered yet</span>@endif
                                @if($covered && $matchingTopic)
                                    <div class="text-muted mt-1">Matched taught topic from attendance: {{ $matchingTopic }}</div>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Syllabus completion</th>
                            <td>
                                {{ $stats['percent'] }}% ({{ $stats['covered'] }} of {{ $stats['planned'] }} topics covered)
                                <div class="progress mt-1" style="max-width:320px">
                                    <div class="progress-bar" role="progressbar" style="width: {{ $stats['percent'] }}%" aria-valuenow="{{ $stats['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                @if(!$stats['has_sessions'])
                                    <div class="text-muted mt-1">No attendance recorded yet for this class and subject.</div>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
