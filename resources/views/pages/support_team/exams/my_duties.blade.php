@extends('layouts.master')
@section('page_title', 'My Invigilation Duties')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">My Invigilation Duties</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
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
                    <tr><td colspan="5" class="text-center text-muted">No invigilation duties assigned yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
