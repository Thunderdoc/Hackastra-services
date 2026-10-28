@extends('layouts.master')
@section('page_title', 'My Feedback #' . $item->id)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Communication &nbsp;/&nbsp; <a href="{{ route('feedback.mine') }}">My Feedback</a> &nbsp;/&nbsp; Report #{{ $item->id }}
@endsection
@section('content')
    {{-- Reporter detail (Workstream CW + Loop 87 uplift): read-only —
         the report text, its attachments, the captured context, the
         live status chain, and the administrator responses kept
         clearly separate from the report itself. --}}
  <div class="l75-fb-detail l87-detail">
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">{{ $item->title }}</h6>
            <a href="{{ route('feedback.mine') }}" class="btn btn-light btn-sm">Back to My Feedback</a>
        </div>
        <div class="card-body">
            <div class="mb-2">
                @include('pages.support_team.feedback._chips', ['item' => $item, 'hide_sa' => true])
                <span class="text-muted ml-2">{{ $item->typeLabel() }}</span>
            </div>

            <div class="l87-meta text-muted mb-3">
                <span><i class="icon-calendar mr-1"></i> Submitted on {{ $item->created_at->format('d M Y, h:i A') }}</span>
                <span><i class="icon-history mr-1"></i> Last updated {{ $item->updated_at->format('d M Y, h:i A') }}</span>
            </div>

            @if($item->isDuplicate())
                <div class="alert alert-info border-0">
                    This report was merged with a similar report{{ $item->duplicateOf ? ' (#' . $item->duplicateOf->id . ')' : '' }} by the school Admin to avoid duplicate work.
                    Its current status: <strong>{{ $item->effectiveStatusLabel() }}</strong>.
                </div>
            @endif

            <div class="font-weight-semibold mb-1">Your Report</div>
            <p class="fb-desc">{{ $item->description }}</p>

            @if($item->context)
                <p class="text-muted"><i class="icon-pushpin mr-1"></i> Where: {{ $item->context }}</p>
            @endif

            @if($item->attachments->count())
                <div class="mb-3">
                    <div class="font-weight-semibold mb-1">Your attachments</div>
                    @include('pages.support_team.feedback._attachments', ['item' => $item])
                </div>
            @endif

            @include('pages.support_team.feedback._autolog', ['item' => $item])

            <hr>

            <div class="row">
                <div class="col-md-6">
                    <div class="font-weight-semibold mb-1">Progress</div>
                    <div>School Admin status: <strong>{{ $item->statusLabel() }}</strong></div>
                    <div class="text-muted">Handled by the school.</div>
                </div>
                <div class="col-md-6">
                    <div class="font-weight-semibold mb-1">Administrator Response</div>
                    @if($item->admin_note)
                        <div class="l87-response">
                            <div class="l87-response-by"><i class="icon-user-check mr-1"></i> School Admin</div>
                            <div style="white-space:pre-wrap">{{ $item->admin_note }}</div>
                        </div>
                    @else
                        <p class="text-muted mb-0">Your report has been received. No administrator response is available yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
  </div>
@endsection
