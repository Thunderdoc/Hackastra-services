@extends('layouts.master')
@section('page_title', 'My Feedback #' . $item->id)
@section('content')
    {{-- Reporter detail (Workstream CW): read-only — the report text,
         its attachments, the captured context, and the live status
         chain (Admin status → SA forwarding → SA reply). --}}
    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">{{ $item->title }}</h6>
            <a href="{{ route('feedback.mine') }}" class="btn btn-light btn-sm">Back to My Feedback</a>
        </div>
        <div class="card-body">
            <div class="mb-2">
                @include('pages.support_team.feedback._chips', ['item' => $item])
                <span class="text-muted ml-2">{{ $item->typeLabel() }}</span>
            </div>

            @if($item->isDuplicate())
                <div class="alert alert-info border-0">
                    This report was merged with a similar report{{ $item->duplicateOf ? ' (#' . $item->duplicateOf->id . ')' : '' }} by the school Admin to avoid duplicate work.
                    Its current status: <strong>{{ $item->effectiveStatusLabel() }}</strong>.
                </div>
            @endif

            <p style="white-space:pre-wrap">{{ $item->description }}</p>

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
                    <div class="font-weight-semibold mb-1">School Admin</div>
                    <div>Status: <strong>{{ $item->statusLabel() }}</strong></div>
                    @if($item->admin_note)
                        <div class="text-muted" style="white-space:pre-wrap">{{ $item->admin_note }}</div>
                    @endif
                    @if($item->isForwarded())
                        <div class="text-muted">Forwarded to the Super Admin on {{ $item->forwarded_at->format('d M Y, h:i A') }}.</div>
                    @endif
                </div>
                <div class="col-md-6">
                    <div class="font-weight-semibold mb-1">Super Admin</div>
                    @if($item->isForwarded())
                        <div>Status: <strong>{{ $item->saStatusLabel() }}</strong></div>
                        @if($item->sa_reply)
                            <div style="white-space:pre-wrap">{{ $item->sa_reply }}</div>
                        @else
                            <div class="text-muted">No reply yet.</div>
                        @endif
                    @else
                        <div class="text-muted">Not forwarded — the school Admin forwards compiled reports to the Super Admin.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
