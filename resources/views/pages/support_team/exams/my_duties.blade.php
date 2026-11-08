@extends('layouts.master')
@section('page_title', 'My Invigilation Duties')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Examinations &nbsp;/&nbsp; <a href="{{ route('exams.index') }}">Manage Exams</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">My Invigilation Duties</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="table-responsive">
            <table class="table table-hover hk-std hk-std-720">
            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
            <colgroup><col style="width:18%"><col style="width:12%"><col style="width:26%"><col style="width:20%"><col style="width:24%"></colgroup>
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Exam</th>
                    <th>Class</th>
                    <th>Subject</th>
                </tr>
                </thead>
                <tbody>
                @forelse($duties as $duty)
                    <tr>
                        <td>{{ $duty->exam_date->exam_date ?? '—' }}</td>
                        <td>{{ substr((string) ($duty->exam_date->start_time ?? ''), 0, 5) ?: '—' }}</td>
                        <td>{{ optional($duty->exam_date->exam)->name ?? '—' }}</td>
                        <td>{{ optional($duty->exam_date->my_class)->name ?? '—' }}</td>
                        <td>{{ optional($duty->exam_date->subject)->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.patterns.empty_state', ['icon' => 'icon-calendar2', 'message' => 'No invigilation duties assigned yet.'])</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
