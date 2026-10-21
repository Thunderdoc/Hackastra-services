@extends('layouts.master')
@section('page_title', 'Results Not Published')
@section('content')

    {{-- Workstream BH — honest empty state for the Result Publish gate:
         a student/parent lands here when the exam's results have not
         been published by the school yet. No marks data is passed to
         this view at all. --}}
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="mb-3">
                <i class="icon-eye-blocked" style="font-size:44px;color:#7a8797"></i>
            </div>
            <h4 class="font-weight-bold mb-2">Results Not Published Yet</h4>
            @if(!empty($exam))
                <p class="text-muted mb-1">
                    Results for <strong>{{ $exam->name }}</strong> ({{ $year }})
                    @if(!empty($sr) && !empty($sr->user))
                        for <strong>{{ $sr->user->name }}</strong>
                    @endif
                    have not been published yet.
                </p>
            @else
                <p class="text-muted mb-1">
                    No results have been published for the <strong>{{ $year }}</strong> session yet.
                </p>
            @endif
            <p class="text-muted">They will appear here as soon as the school publishes them. Please check back later, or contact the school office if you were expecting them.</p>
            <a href="{{ route('dashboard') }}" class="btn btn-primary mt-2">Back to Dashboard</a>
        </div>
    </div>

@endsection
