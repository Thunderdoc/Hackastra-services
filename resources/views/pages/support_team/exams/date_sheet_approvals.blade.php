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
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-calendar2',
                    'message' => 'No date sheets are awaiting approval right now.',
                ])
            @else
                <div class="table-responsive">
                    <table class="table hk-std hk-std-880">
                        <colgroup><col style="width:4%"><col style="width:20%"><col style="width:12%"><col style="width:8%"><col style="width:16%"><col style="width:16%"><col style="width:24%"></colgroup>
                        <thead><tr><th>#</th><th>Exam</th><th>Class</th><th>Papers</th><th>Submitted by</th><th>Submitted</th><th>Actions</th></tr></thead>
                        <tbody>
                        @foreach($states as $state)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ optional($state->exam)->name }}</td>
                                <td>{{ optional($state->my_class)->name }}</td>
                                <td class="hk-nw">{{ $state->row_count }}</td>
                                <td>{{ $state->submitted_by_name ?: '—' }}</td>
                                <td class="hk-nw">{{ $state->submitted_at ? $state->submitted_at->format('d M Y, g:i A') : '—' }}</td>
                                <td class="hk-nw">
                                    <a href="{{ route('exam_dates.show', ['exam_id' => $state->exam_id, 'class_id' => $state->my_class_id]) }}" class="btn btn-light btn-sm">
                                        <i class="icon-eye mr-1"></i> Preview
                                    </a>
                                    <form method="post" action="{{ route('exam_dates.approve') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="exam_id" value="{{ $state->exam_id }}">
                                        <input type="hidden" name="class_id" value="{{ $state->my_class_id }}">
                                        <button type="submit" class="btn btn-success btn-sm"><i class="icon-checkmark3 mr-1"></i> Approve &amp; Publish</button>
                                    </form>
                                    <form method="post" action="{{ route('exam_dates.return') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="exam_id" value="{{ $state->exam_id }}">
                                        <input type="hidden" name="class_id" value="{{ $state->my_class_id }}">
                                        <button type="submit" class="btn btn-warning btn-sm"><i class="icon-undo2 mr-1"></i> Return for Correction</button>
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

    @if(!empty($published_states) && $published_states->isNotEmpty())
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Published Date Sheets</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <div class="alert alert-info border-0">
                <span>Published sheets are locked — the Admin cannot change or regenerate them. If one needs a
                correction, return it here: it goes back to draft, the Admin fixes it, submits it again, and
                you publish the corrected sheet.</span>
            </div>

            <div class="table-responsive">
                <table class="table hk-std hk-std-880">
                    <colgroup><col style="width:4%"><col style="width:20%"><col style="width:12%"><col style="width:8%"><col style="width:16%"><col style="width:16%"><col style="width:24%"></colgroup>
                    <thead><tr><th>#</th><th>Exam</th><th>Class</th><th>Papers</th><th>Published</th><th></th><th>Actions</th></tr></thead>
                    <tbody>
                    @foreach($published_states as $state)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ optional($state->exam)->name }}</td>
                            <td>{{ optional($state->my_class)->name }}</td>
                            <td class="hk-nw">{{ $state->row_count }}</td>
                            <td class="hk-nw">{{ $state->approved_at ? $state->approved_at->format('d M Y, g:i A') : '—' }}</td>
                            <td></td>
                            <td class="hk-nw">
                                <a href="{{ route('exam_dates.show', ['exam_id' => $state->exam_id, 'class_id' => $state->my_class_id]) }}" class="btn btn-light btn-sm">
                                    <i class="icon-eye mr-1"></i> Preview
                                </a>
                                <form method="post" action="{{ route('exam_dates.return') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="exam_id" value="{{ $state->exam_id }}">
                                    <input type="hidden" name="class_id" value="{{ $state->my_class_id }}">
                                    <button type="submit" class="btn btn-warning btn-sm"><i class="icon-undo2 mr-1"></i> Return for Correction</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

@endsection
