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
                <p class="text-muted mb-0">No lesson plans yet. @if($isTeacher) <a href="{{ route('lesson_plans.create') }}">Create your first plan</a> to start tracking syllabus coverage. @endif</p>
            @else
                @foreach($groups as $group)
                    <div class="mb-4">
                        <h6 class="font-weight-semibold mb-1">{{ optional($group['class'])->name }} &mdash; {{ optional($group['subject'])->name }}@if($group['section']) (Section {{ $group['section']->name }})@endif</h6>
                        <p class="mb-1">Completion: {{ $group['percent'] }}% ({{ $group['covered'] }} of {{ $group['planned'] }} topics covered)</p>
                        @if(!$group['has_sessions'])
                            <p class="text-muted mb-0">No attendance recorded yet for this class and subject, so no topic can be marked covered yet.</p>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    @if($plans->count())
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">All Plans</h5>
            </div>
            <div class="table-responsive">
                <table class="table">
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
                                <td>{{ optional($plan->week_start)->format('d M Y') }}</td>
                                <td>{{ optional($plan->my_class)->name }}@if($plan->section) - {{ $plan->section->name }}@endif</td>
                                <td>{{ optional($plan->subject)->name }}</td>
                                <td>{{ $plan->topic }}</td>
                                <td>
                                    @if(!empty($coveredOf[$plan->id]))<span class="badge badge-success">Covered</span>
                                    @else<span class="badge badge-secondary">Not covered yet</span>@endif
                                </td>
                                <td><a href="{{ route('lesson_plans.show', $plan->id) }}" class="btn btn-light btn-sm">View</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
