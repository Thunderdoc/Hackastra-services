@extends('layouts.master')
@section('page_title', 'Date Sheet Approvals')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Date Sheet Approvals</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <div class="alert alert-info border-0">
                <span>Date sheets the Admin has submitted. Approving publishes the sheet — students, parents and
                teachers see it from that moment. Review the sheet first with Preview, then approve.</span>
            </div>

            @if($states->isEmpty())
                <p class="text-muted mb-0">No date sheets are awaiting approval right now.</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>#</th><th>Exam</th><th>Class</th><th>Papers</th><th>Submitted by</th><th>Submitted</th><th>Actions</th></tr></thead>
                        <tbody>
                        @foreach($states as $state)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ optional($state->exam)->name }}</td>
                                <td>{{ optional($state->my_class)->name }}</td>
                                <td>{{ $state->row_count }}</td>
                                <td>{{ $state->submitted_by_name ?: '—' }}</td>
                                <td>{{ $state->submitted_at ? $state->submitted_at->format('d M Y, g:i A') : '—' }}</td>
                                <td>
                                    <a href="{{ route('exam_dates.show', ['exam_id' => $state->exam_id, 'class_id' => $state->my_class_id]) }}" class="btn btn-light btn-sm">
                                        <i class="icon-eye mr-1"></i> Preview
                                    </a>
                                    <form method="post" action="{{ route('exam_dates.approve') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="exam_id" value="{{ $state->exam_id }}">
                                        <input type="hidden" name="class_id" value="{{ $state->my_class_id }}">
                                        <button type="submit" class="btn btn-success btn-sm"><i class="icon-checkmark3 mr-1"></i> Approve &amp; Publish</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

@endsection
