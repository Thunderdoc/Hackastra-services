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

    {{-- Loop 114: page subtitle per the reference, above the actions. --}}
    <p class="l114-page-sub">Read the complete circular and related information.</p>

    <div class="l75-cd-toolbar">
        {{-- Loop 114: actions grouped at the right in the reference
             order — Print / Download PDF / Back to Circulars, with
             Back as the blue primary. Edit/Delete (writers only)
             sit between Download PDF and Back. --}}
        <span class="spacer"></span>
        <a href="{{ route('circulars.print', $circular->id) }}" target="_blank" rel="noopener" class="btn btn-light"><i class="icon-printer mr-1"></i> Print</a>
        <a href="{{ route('circulars.pdf', $circular->id) }}" class="btn btn-light"><i class="icon-download mr-1"></i> Download PDF</a>
        @if($canWrite)
            <a href="{{ route('circulars.edit', $circular->id) }}" class="btn btn-light"><i class="icon-pencil7 mr-1"></i> Edit</a>
            <form method="post" action="{{ route('circulars.destroy', $circular->id) }}" style="display:inline">
                @csrf @method('delete')
                <button type="submit" class="btn btn-light text-danger" data-confirm="Delete this circular?" data-confirm-title="Delete Circular" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button>
            </form>
        @endif
        <a href="{{ route('circulars.index') }}" class="btn btn-primary"><i class="icon-arrow-left13 mr-1"></i> Back to Circulars</a>
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
                                {{-- Loop 114: exactly two hero pills per the
                                     reference — the priority chip and
                                     "✓ Published" (the separate ACTIVE pill
                                     is gone; lifecycle detail lives in
                                     Quick Information). --}}
                                @if($circular->is_published)
                                    <span class="l75-pill l75-pill-active l114-case"><i class="icon-checkmark2"></i> Published</span>
                                @else
                                    <span class="l75-pill l75-pill-muted l114-case">Draft</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="l75-cd-meta l114-meta5">
                        <div class="l75-cd-meta-item"><span class="lbl">Audience</span><span class="val"><i class="icon-users"></i>{{ $circular->audience === 'all' ? 'For Everyone' : ($audiences[$circular->audience] ?? $circular->audience) }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Published by</span><span class="val"><i class="icon-user"></i>{{ $circular->publisher->name ?? 'School Office' }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Published on</span><span class="val"><i class="icon-calendar3"></i>{{ $circular->created_at->format('d M Y') }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Available from</span><span class="val"><i class="icon-calendar3"></i>{{ $circular->starts_at ? $circular->starts_at->format('d M Y') : 'Immediately' }}</span></div>
                        <div class="l75-cd-meta-item"><span class="lbl">Valid until</span><span class="val"><i class="icon-history"></i>{{ $circular->expires_at ? $circular->expires_at->format('d M Y') : 'No end date' }}</span></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title"><i class="icon-file-text2 l114-cd-msg-ic"></i>Circular Message</h6></div>
                <div class="card-body">
                    {{-- Loop 114: the message sits in the reference's
                         tinted quote box inside the card. --}}
                    <div class="l114-quote">
                        <div class="l75-cd-body">{!! nl2br(e($circular->body)) !!}</div>
                    </div>
                </div>
            </div>

            @if($circular->attachments->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="card-title"><i class="icon-attachment l114-cd-msg-ic"></i>Attachments ({{ $circular->attachments->count() }})</h6></div>
                    <div class="card-body">
                        @foreach($circular->attachments as $attachment)
                            @php
                                $attBytes = \Illuminate\Support\Facades\Storage::disk('local')->exists($attachment->path) ? \Illuminate\Support\Facades\Storage::disk('local')->size($attachment->path) : null;
                                $attSize = $attBytes === null ? null : ($attBytes >= 1048576 ? number_format($attBytes / 1048576, 1) . ' MB' : max(1, (int) round($attBytes / 1024)) . ' KB');
                            @endphp
                            <div class="d-flex align-items-center {{ !$loop->last ? 'mb-2' : '' }}">
                                <span class="l114-att-ic"><i class="{{ $attachment->isImage() ? 'icon-file-picture2' : 'icon-file-pdf' }}"></i></span>
                                <span class="l114-att-txt">
                                    <a href="{{ $attachment->downloadUrl() }}" class="l114-att-name">{{ $attachment->original_name }}</a>
                                    <span class="l114-att-sub">{{ $attSize ? $attSize . ' • ' : '' }}{{ $attachment->isImage() ? 'Image' : 'PDF' }}</span>
                                </span>
                                <a href="{{ $attachment->downloadUrl() }}" class="btn btn-light btn-sm ml-auto"><i class="icon-download mr-1"></i> Download</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Loop 114: the published banner lives under the message
                 in the main column (it was a plain line at the very
                 bottom of the right rail), with the reference's
                 two-sentence text. --}}
            @if(!$canWrite && $circular->is_published)
                <div class="l75-cd-note l114-cd-banner">
                    <i class="icon-info22"></i>
                    <span><strong>This is a published circular and cannot be edited.</strong><br>For any changes or corrections, please contact the school administrator.</span>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title">Quick Information</h6></div>
                <div class="card-body">
                    <div class="l75-qi-row"><span class="k">Reference No.</span><span class="v">{{ $circular->reference() }}</span></div>
                    <div class="l75-qi-row"><span class="k">Circular ID</span><span class="v">#{{ $circular->id }}</span></div>
                    <div class="l75-qi-row"><span class="k">Priority</span><span class="v"><span class="circ-pri-chip circ-pri-chip-{{ $circular->priority }}">@if($circular->priority === 'urgent')<i class="icon-alarm"></i> @endif{{ $priorities[$circular->priority] ?? $circular->priority }}</span></span></div>
                    <div class="l75-qi-row"><span class="k">Status</span><span class="v">@if($circular->is_published)<span class="l75-pill l75-pill-active l114-case"><i class="icon-checkmark2"></i> Published</span>@else<span class="l75-pill l75-pill-muted l114-case">Draft</span>@endif</span></div>
                    <div class="l75-qi-row"><span class="k">Start Date</span><span class="v">{{ $circular->starts_at ? $circular->starts_at->format('d M Y') : '—' }}</span></div>
                    <div class="l75-qi-row"><span class="k">End Date</span><span class="v">{{ $circular->expires_at ? $circular->expires_at->format('d M Y') : 'No end date' }}</span></div>
                    <div class="l75-qi-row"><span class="k">Created At</span><span class="v">{{ $circular->created_at->format('d M Y, h:i A') }}</span></div>
                    <div class="l75-qi-row"><span class="k">Created By</span><span class="v">{{ $circular->publisher->name ?? 'School Office' }}</span></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title">Audience</h6></div>
                <div class="card-body">
                    <p class="text-muted mb-2">This circular is visible to:</p>
                    <p class="mb-2"><span class="circ-aud"><i class="icon-users"></i>For {{ $audiences[$circular->audience] ?? $circular->audience }}</span></p>
                    <p class="text-muted mb-0">{{ $audienceSentence }}</p>
                </div>
            </div>

            @if($circular->is_published)
                <div class="card">
                    <div class="card-header"><h6 class="card-title">Official Signed PDF</h6></div>
                    <div class="card-body">
                        @if($circular->official_pdf_generated_at)
                            <p class="mb-2"><i class="icon-file-pdf mr-1"></i> The signed official copy of this circular — with the principal's signature and school seal — was generated automatically when it was published.</p>
                            <p class="text-muted mb-3">Generated on {{ $circular->official_pdf_generated_at->format('d M Y, h:i A') }}</p>
                            <a href="{{ route('circulars.official', $circular->id) }}" target="_blank" rel="noopener" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                            <a href="{{ route('circulars.official', [$circular->id, 'download' => 1]) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download</a>
                        @else
                            <p class="text-muted mb-0"><i class="icon-file-pdf mr-1"></i> The signed official PDF is being prepared — it appears here automatically, no action is needed.</p>
                        @endif
                    </div>
                </div>
            @endif

        </div>
    </div>
@endsection
