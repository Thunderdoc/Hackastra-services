@extends('layouts.master')
@section('page_title', 'Result Publication')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; <a href="{{ route('exams.index') }}">Exams</a> &nbsp;/&nbsp; Publication
@endsection
@section('page_subtitle', 'Publish results only when every teacher has submitted their marks.')
@section('content')

    {{-- Result publication workflow (owner order 2026-10-29): the
         Admin prepares and requests; the Principal publishes. This
         page is the one place both sides work from. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title"><i class="icon-eye mr-2"></i>{{ $exam->name }} — Term {{ $exam->term }}, {{ $exam->year }}</h5>
        </div>
        <div class="card-body">
            @if($exam->results_published)
                <div class="alert alert-success border-0">
                    <strong>Results published.</strong> Students and parents can see these results now.
                    @if(\App\Helpers\Qs::userIsPrincipal())
                        <form method="post" action="{{ route('exams.unpublish_results', $exam->id) }}" class="mt-2" data-confirm="Unpublish results for {{ $exam->name }}? Students and parents will no longer be able to see these results." data-confirm-title="Unpublish Results" data-confirm-action="Unpublish" data-confirm-danger>
                            @csrf
                            <button type="submit" class="btn btn-light btn-sm"><i class="icon-eye-blocked mr-1"></i> Unpublish Results</button>
                        </form>
                    @endif
                </div>
            @elseif($total_units === 0)
                <div class="alert alert-warning border-0">
                    <strong>No marks entered yet.</strong> No teacher has entered marks for this exam, so there is nothing to publish.
                </div>
            @else
                <p><strong>{{ $total_units - count($pending_units) }} of {{ $total_units }}</strong> subjects have their marks submitted.</p>

                @if(count($pending_units) > 0)
                    <h6 class="mt-3">Still waiting on these teachers</h6>
                    <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr><th>Class</th><th>Subject</th><th>Teacher</th><th></th></tr>
                        </thead>
                        <tbody>
                        @foreach($pending_units as $u)
                            <tr>
                                <td>{{ $u['class_name'] }}@if($u['section_name']) — {{ $u['section_name'] }}@endif</td>
                                <td>{{ $u['subject_name'] }}</td>
                                <td>{{ $u['teacher_name'] }}</td>
                                <td class="text-right">
                                    @if($u['teacher_id'])
                                        <form method="post" action="{{ route('exams.notify_pending', $exam->id) }}">
                                            @csrf
                                            <input type="hidden" name="section_id" value="{{ $u['section_id'] }}">
                                            <input type="hidden" name="subject_id" value="{{ $u['subject_id'] }}">
                                            <button type="submit" class="btn btn-warning btn-sm"><i class="icon-bell2 mr-1"></i> Remind</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                    <form method="post" action="{{ route('exams.notify_pending', $exam->id) }}">
                        @csrf
                        <input type="hidden" name="all" value="1">
                        <button type="submit" class="btn btn-warning"><i class="icon-bell2 mr-1"></i> Remind all pending teachers (submit within 2 days)</button>
                    </form>
                @else
                    <div class="alert alert-success border-0">
                        <strong>All marks are submitted.</strong> Every subject is in — the results are ready to publish.
                    </div>
                @endif

                <hr>
                @if($exam->publish_requested_at)
                    <p><strong>Publication requested</strong> by {{ optional($requester)->name ?? 'the school Admin' }} on {{ \Illuminate\Support\Carbon::parse($exam->publish_requested_at)->format('d M Y, g:i A') }} — waiting for the Principal to publish.</p>
                    @if(\App\Helpers\Qs::userIsPrincipal())
                        <form method="post" action="{{ route('exams.publish_results', $exam->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-success"><i class="icon-eye mr-1"></i> Publish Results</button>
                        </form>
                    @endif
                @elseif(count($pending_units) === 0)
                    @if(\App\Helpers\Qs::userIsAdmin())
                        <form method="post" action="{{ route('exams.request_publication', $exam->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary"><i class="icon-paperplane mr-1"></i> Send publication request to the Principal</button>
                        </form>
                        <p class="text-muted mt-2">The results go live only when the Principal publishes them.</p>
                    @elseif(\App\Helpers\Qs::userIsPrincipal())
                        <form method="post" action="{{ route('exams.publish_results', $exam->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-success"><i class="icon-eye mr-1"></i> Publish Results</button>
                        </form>
                    @endif
                @else
                    <p class="text-muted">The publication request unlocks when every subject's marks are submitted.</p>
                @endif
            @endif
        </div>
    </div>

@endsection
