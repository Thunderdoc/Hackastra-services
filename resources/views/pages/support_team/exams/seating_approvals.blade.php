@extends('layouts.master')
@section('page_title', 'Exam Seating Approvals')
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
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
@endsection
