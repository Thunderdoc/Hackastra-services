@extends('layouts.master')
@section('page_title', 'Circular Details')
@section('page_breadcrumb')
    Communication &nbsp;/&nbsp; <a href="{{ route('circulars.index') }}">Circulars</a> &nbsp;/&nbsp; View Circular
@endsection
@section('content')
    {{-- Loop 75 (owner addendum 2026-10-22): circular detail —
         hero card + message card on the left, Quick Information
         and Audience cards on the right. Every value comes from
         the record; the message renders escaped with its
         paragraph breaks, exactly as the feed shows it. --}}
    @php
        $status = $circular->statusLabel();
        $statusPill = ['Live' => ['Active', 'l75-pill-active'], 'Scheduled' => ['Scheduled', 'l75-pill-scheduled'], 'Expired' => ['Expired', 'l75-pill-muted'], 'Draft' => ['Draft', 'l75-pill-muted']][$status] ?? [$status, 'l75-pill-muted'];
        $canWrite = Qs::userIsTeamSAWrite();
        $audienceSentence = $circular->audience === 'all'
            ? 'This circular is visible to all students, parents, teachers and staff.'
            : 'This circular is visible to ' . strtolower($audiences[$circular->audience] ?? $circular->audience) . ' only.';
    @endphp

    <div class="l75-cd-toolbar">
        <a href="{{ route('circulars.index') }}" class="btn btn-light"><i class="icon-arrow-left13 mr-1"></i> Back to Circulars</a>
        <span class="spacer"></span>
        <a href="{{ route('circulars.print', $circular->id) }}" target="_blank" rel="noopener" class="btn btn-light"><i class="icon-printer mr-1"></i> Print</a>
        <a href="{{ route('circulars.pdf', $circular->id) }}" class="btn btn-light"><i class="icon-download mr-1"></i> Download PDF</a>
        @if($canWrite)
            <a href="{{ route('circulars.edit', $circular->id) }}" class="btn btn-primary"><i class="icon-pencil7 mr-1"></i> Edit</a>
            <form method="post" action="{{ route('circulars.destroy', $circular->id) }}" style="display:inline">
                @csrf @method('delete')
                <button type="submit" class="btn btn-light text-danger" data-confirm="Delete this circular?" data-confirm-title="Delete Circular" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button>
            </form>
        @endif
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="l75-cd-hero">
                        <span class="l75-circ-tile"><i class="icon-megaphone"></i></span>
                        <div style="flex:1;min-width:0">
                            <h4 class="l75-cd-title">{{ $circular->title }}</h4>
                            <div class="l75-cd-pills">
                                <span class="circ-pri-chip circ-pri-chip-{{ $circular->priority }}">@if($circular->priority === 'urgent')<i class="icon-alarm"></i> @endif{{ $priorities[$circular->priority] ?? $circular->priority }}</span>
                                @if($circular->is_published)
                                    <span class="l75-pill l75-pill-scheduled">Published</span>
                                    <span class="l75-pill {{ $statusPill[1] }}">{{ $statusPill[0] }}</span>
                                @else
                                    <span class="l75-pill l75-pill-muted">Draft</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="l75-cd-meta">
                        <div class="l75-cd-meta-item"><span class="lbl">Audience</span><span class="val"><i class="icon-users"></i>{{ $audiences[$circular->audience] ?? $circular->audience }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Published by</span><span class="val"><i class="icon-user"></i>{{ $circular->publisher->name ?? 'School Office' }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Published on</span><span class="val"><i class="icon-calendar3"></i>{{ $circular->created_at->format('d M Y') }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Available from</span><span class="val"><i class="icon-calendar3"></i>{{ $circular->starts_at ? $circular->starts_at->format('d M Y') : 'Immediately' }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Valid until</span><span class="val"><i class="icon-history"></i>{{ $circular->expires_at ? $circular->expires_at->format('d M Y') : 'No end date' }}</span></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title">Message</h6></div>
                <div class="card-body">
                    <div class="l75-cd-body">{!! nl2br(e($circular->body)) !!}</div>
                </div>
            </div>

            @if($circular->attachments->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="card-title">Attachments</h6></div>
                    <div class="card-body">
                        @foreach($circular->attachments as $attachment)
                            <div class="d-flex align-items-center {{ !$loop->last ? 'mb-2' : '' }}">
                                <i class="icon-attachment mr-2"></i>
                                <a href="{{ $attachment->downloadUrl() }}">{{ $attachment->original_name }}</a>
                                <span class="text-muted ml-2">{{ $attachment->isImage() ? 'Image' : 'PDF' }}</span>
                                <a href="{{ $attachment->downloadUrl() }}" class="btn btn-light btn-sm ml-auto"><i class="icon-download mr-1"></i> Download</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title">Quick Information</h6></div>
                <div class="card-body">
                    <div class="l75-qi-row"><span class="k">Reference No.</span><span class="v">{{ $circular->reference() }}</span></div>
                    <div class="l75-qi-row"><span class="k">Circular ID</span><span class="v">#{{ $circular->id }}</span></div>
                    <div class="l75-qi-row"><span class="k">Priority</span><span class="v">{{ $priorities[$circular->priority] ?? $circular->priority }}</span></div>
                    <div class="l75-qi-row"><span class="k">Status</span><span class="v">{{ $circular->is_published ? 'Published · ' . $statusPill[0] : 'Draft' }}</span></div>
                    <div class="l75-qi-row"><span class="k">Start Date</span><span class="v">{{ $circular->starts_at ? $circular->starts_at->format('d M Y') : '—' }}</span></div>
                    <div class="l75-qi-row"><span class="k">End Date</span><span class="v">{{ $circular->expires_at ? $circular->expires_at->format('d M Y') : 'No end date' }}</span></div>
                    <div class="l75-qi-row"><span class="k">Created At</span><span class="v">{{ $circular->created_at->format('d M Y, h:i A') }}</span></div>
                    <div class="l75-qi-row"><span class="k">Created By</span><span class="v">{{ $circular->publisher->name ?? 'School Office' }}</span></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title">Audience</h6></div>
                <div class="card-body">
                    <p class="mb-2"><span class="circ-aud"><i class="icon-users"></i>For {{ $audiences[$circular->audience] ?? $circular->audience }}</span></p>
                    <p class="text-muted mb-0">{{ $audienceSentence }}</p>
                </div>
            </div>

            @if(!$canWrite && $circular->is_published)
                <div class="l75-cd-note">
                    <i class="icon-info22 mr-1"></i> This is a published circular — for changes, contact the school administrator.
                </div>
            @endif
        </div>
    </div>
@endsection
