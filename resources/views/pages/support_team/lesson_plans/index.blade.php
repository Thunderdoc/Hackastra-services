@extends('layouts.master')
@section('page_title', 'Lesson Plans')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Lesson Plans
@endsection
@section('page_actions')
    @if($isTeacher)
        <a href="{{ route('lesson_plans.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus2 mr-1"></i> New Lesson Plan</a>
    @endif
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Syllabus Progress</h5>
        </div>
        <div class="card-body">
            @if(empty($groups))
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-book',
                    'message' => 'No lesson plans yet.',
                    'action_html' => $isTeacher ? '<a href="' . route('lesson_plans.create') . '">Create your first plan</a> to start tracking syllabus coverage.' : null,
                ])
            @else
                <div class="table-responsive">
                    <table class="table lms-table l108-stack">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Section</th>
                                <th>Planned</th>
                                <th>Covered</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($groups as $group)
                                <tr>
                                    <td data-label="Class">{{ optional($group['class'])->name }}</td>
                                    <td data-label="Subject">{{ optional($group['subject'])->name }}</td>
                                    <td data-label="Section">{{ $group['section'] ? $group['section']->name : 'Whole class' }}</td>
                                    <td data-label="Planned">{{ $group['planned'] }}</td>
                                    <td data-label="Covered">{{ $group['covered'] }}</td>
                                    <td data-label="Progress">
                                        <div class="progress" style="min-width:120px">
                                            <div class="progress-bar" role="progressbar" style="width: {{ $group['percent'] }}%" aria-valuenow="{{ $group['percent'] }}" aria-valuemin="0" aria-valuemax="100">{{ $group['percent'] }}%</div>
                                        </div>
                                        <div class="mt-1">Completion: {{ $group['percent'] }}% ({{ $group['covered'] }} of {{ $group['planned'] }} topics covered)</div>
                                        @if(!$group['has_sessions'])
                                            <div class="text-muted mt-1">No attendance recorded yet for this class and subject, so no topic can be marked covered yet.</div>
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

    @if($plans->count())
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">All Plans</h5>
            </div>
            <div class="table-responsive">
                <table class="table lms-table l108-stack">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Topic</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($plans as $plan)
                            <tr>
                                <td data-label="Week">{{ optional($plan->week_start)->format('d M Y') }}</td>
                                <td data-label="Class">{{ optional($plan->my_class)->name }}@if($plan->section) - {{ $plan->section->name }}@endif</td>
                                <td data-label="Subject">{{ optional($plan->subject)->name }}</td>
                                <td data-label="Topic">{{ $plan->topic }}</td>
                                <td data-label="Status">
                                    @if(!empty($coveredOf[$plan->id]))<span class="badge badge-success">Covered</span>
                                    @else<span class="badge badge-secondary">Not covered yet</span>@endif
                                </td>
                                <td data-label="Actions"><a href="{{ route('lesson_plans.show', $plan->id) }}" class="btn btn-light btn-sm">View</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
