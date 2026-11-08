@extends('layouts.master')
@section('page_title', 'Exam Seating Approvals')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Examinations &nbsp;/&nbsp; <a href="{{ route('exams.index') }}">Manage Exams</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')

    {{-- F3 — Principal's queue: submitted seating plans awaiting
         publication (mirrors the date-sheet approvals pattern). --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Seating plans the Admin has submitted. Publishing makes the hall sheets visible to the teachers who invigilate them.
        @endslot

        @if($plans->isEmpty())
            <div class="alert alert-info border-0">No seating plans are awaiting approval right now.</div>
        @else
            <div class="table-responsive">
                <table class="table hk-std hk-std-seating">
                    <thead>
                        <tr>
                            <th>Exam</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Students</th>
                            <th>Mode</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($plans as $plan)
                            <tr>
                                <td>{{ $plan->exam->name ?? '' }}</td>
                                <td>{{ \Carbon\Carbon::parse($plan->exam_date)->format('D, d M Y') }}</td>
                                <td>{{ $plan->start_time ? \Carbon\Carbon::parse($plan->start_time)->format('h:i A') : '—' }}</td>
                                <td>{{ $plan->total_students }}</td>
                                <td>{{ $plan->mode === 'mixed' ? 'Mixed' : 'Separate' }}</td>
                                <td>
                                    <a href="{{ route('exam_seating.show', $plan->id) }}" class="btn btn-light btn-sm">View Plan</a>
                                    <form method="post" action="{{ route('exam_seating.approve') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                        <button type="submit" class="btn btn-success btn-sm">Publish</button>
                                    </form>
                                    <form method="post" action="{{ route('exam_seating.return') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                        <button type="submit" class="btn btn-warning btn-sm">Return for Correction</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ONE-TIME GENERATION (owner standing rule 2026-11-08):
             published plans are listed too — returning one for
             correction is the only way it opens again, because a
             published plan can no longer be regenerated over. --}}
        @if(!empty($published) && $published->isNotEmpty())
            <h6 class="mt-4">Published Seating Plans</h6>
            <div class="table-responsive">
                <table class="table hk-std hk-std-seating">
                    <thead>
                        <tr>
                            <th>Exam</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Students</th>
                            <th>Mode</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($published as $plan)
                            <tr>
                                <td>{{ $plan->exam->name ?? '' }}</td>
                                <td>{{ \Carbon\Carbon::parse($plan->exam_date)->format('D, d M Y') }}</td>
                                <td>{{ $plan->start_time ? \Carbon\Carbon::parse($plan->start_time)->format('h:i A') : '—' }}</td>
                                <td>{{ $plan->total_students }}</td>
                                <td>{{ $plan->mode === 'mixed' ? 'Mixed' : 'Separate' }}</td>
                                <td>
                                    <a href="{{ route('exam_seating.show', $plan->id) }}" class="btn btn-light btn-sm">View Plan</a>
                                    <form method="post" action="{{ route('exam_seating.return') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                        <button type="submit" class="btn btn-warning btn-sm">Return for Correction</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
@endsection
