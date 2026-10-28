@extends('layouts.master')
@section('page_title', 'Feedback #' . $item->id)
@section('content')
    {{-- Admin feedback detail (Workstream CW): triage the report,
         merge it as a duplicate of a canonical one, or forward it
         (compiled) to the Super Admin. The report text is the
         reporter's own — the Admin never edits it. --}}
  <div class="l75-fb-detail">
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">#{{ $item->id }} — {{ $item->title }}</h6>
            <a href="{{ route('feedback.index') }}" class="btn btn-light btn-sm">Back to Inbox</a>
        </div>
        <div class="card-body">
            <div class="mb-2">
                @include('pages.support_team.feedback._chips', ['item' => $item])
                <span class="text-muted ml-2">{{ $item->typeLabel() }}</span>
            </div>

            <p class="fb-desc">{{ $item->description }}</p>

            @if($item->context)
                <p class="text-muted"><i class="icon-pushpin mr-1"></i> Where: {{ $item->context }}</p>
            @endif

            @if($item->attachments->count())
                <div class="mb-3">
                    <div class="font-weight-semibold mb-1">Attachments</div>
                    @include('pages.support_team.feedback._attachments', ['item' => $item])
                </div>
            @endif

            @include('pages.support_team.feedback._autolog', ['item' => $item])

            @if($item->duplicates->count())
                <div class="alert alert-info border-0 mt-3">
                    <strong>{{ $item->duplicates->count() }} duplicate report{{ $item->duplicates->count() === 1 ? '' : 's' }}</strong> merged into this one:
                    @foreach($item->duplicates as $dup)
                        <div>#{{ $dup->id }} — {{ $dup->title }} <span class="text-muted">(by {{ $dup->reporter_name }})</span></div>
                    @endforeach
                </div>
            @endif

            @if($item->isDuplicate())
                <div class="alert alert-warning border-0 mt-3">
                    This report is merged as a duplicate of
                    <a href="{{ route('feedback.show', $item->duplicate_of_id) }}"><strong>#{{ $item->duplicate_of_id }}</strong></a>.
                    The reporter now follows that report's status.
                </div>
            @endif

            @if($item->isForwarded())
                <div class="alert alert-success border-0 mt-3">
                    Forwarded to the Super Admin on {{ $item->forwarded_at->format('d M Y, h:i A') }}
                    {{ $item->forwarder ? 'by ' . $item->forwarder->name : '' }}.
                    Super Admin status: <strong>{{ $item->saStatusLabel() }}</strong>
                    @if($item->sa_reply)
                        <div class="mt-1"><strong>SA reply:</strong> {{ $item->sa_reply }}</div>
                    @endif
                    {{-- Wave 16: only the school Admin can reopen a
                         closed report — it goes back to Received for
                         the Super Admin (re-escalation). --}}
                    @if($item->isClosedForSa())
                        <form method="post" action="{{ route('feedback.reopen', $item->id) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-warning btn-sm">Reopen — send back to Super Admin</button>
                            <span class="text-muted ml-2">Closed by the Super Admin. Reopening puts it back to Received on their board.</span>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h6 class="card-title">Triage</h6></div>
                <div class="card-body">
                    <form method="post" action="{{ route('feedback.update', $item->id) }}">
                        @csrf @method('patch')
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control select">
                                @foreach($statuses as $value => $label)
                                    <option value="{{ $value }}" {{ $item->status == $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="admin_note">Admin note (shown to the reporter)</label>
                            <textarea name="admin_note" id="admin_note" rows="3" class="form-control" placeholder="Eg. Plumber visit scheduled for Thursday.">{{ old('admin_note', $item->admin_note) }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Save <i class="icon-checkmark ml-1"></i></button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            @if(!$item->isDuplicate() && !$item->isForwarded())
                <div class="card">
                    <div class="card-header"><h6 class="card-title">Merge as Duplicate</h6></div>
                    <div class="card-body">
                        <p class="text-muted">If the same issue is already reported, merge this one into that canonical report. The reporter then follows the canonical report's status.</p>
                        <form method="post" action="{{ route('feedback.duplicate', $item->id) }}">
                            @csrf
                            <div class="form-group">
                                <label for="duplicate_of_id">Canonical report</label>
                                <select name="duplicate_of_id" id="duplicate_of_id" class="form-control select" required>
                                    <option value="">— Select the original report —</option>
                                    @foreach($candidates as $cand)
                                        <option value="{{ $cand->id }}">#{{ $cand->id }} — {{ \Illuminate\Support\Str::limit($cand->title, 60) }} ({{ $cand->categoryLabel() }}, by {{ $cand->reporter_name }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-warning" data-confirm="Merge this report as a duplicate? The reporter will follow the canonical report instead." data-confirm-title="Merge Duplicate" data-confirm-action="Merge">Merge as Duplicate</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h6 class="card-title">Forward to Super Admin</h6></div>
                    <div class="card-body">
                        <p class="text-muted">Only platform problems go up — app glitches, UI/UX bugs and the like. School issues (facilities, fees, transport…) are sorted by the school alone and never leave this inbox.</p>
                        <form method="post" action="{{ route('feedback.forward') }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $item->id }}">
                            <div class="form-check mb-2">
                                <input type="checkbox" name="platform_issue" value="1" class="form-check-input" id="platform_issue" {{ $item->category === 'app_bug' ? 'checked' : '' }}>
                                <label class="form-check-label" for="platform_issue">This is a platform problem (not a school issue)</label>
                            </div>
                            <button type="submit" class="btn btn-primary" data-confirm="Forward this report to the Super Admin?" data-confirm-title="Forward to Super Admin" data-confirm-action="Forward">
                                <i class="icon-share2 mr-1"></i> Forward this report
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body text-muted">
                        @if($item->isDuplicate())
                            Duplicates are not forwarded — their canonical report carries the issue up.
                        @else
                            This report is already with the Super Admin; its handling is tracked above.
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
  </div>
@endsection
