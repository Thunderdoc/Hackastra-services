@extends('layouts.master')
@section('page_title', 'School Feedback #' . $item->id)
@section('content')
    {{-- Super Admin feedback detail (Workstream CW): the forwarded
         report is read-only — the SA cannot edit the reporter's text.
         The SA records the handling status and a reply, which the
         reporter reads in My Feedback. --}}
  <div class="l75-fb-detail l106">
    <div class="l106-panel l106-fb-card">
        <div class="l106-panel-head">
            <h6>#{{ $item->id }} — {{ $item->title }}</h6>
            <a href="{{ route('feedback.sa_index') }}" class="btn btn-light btn-sm">Back to School Feedback</a>
        </div>
        <div class="l106-panel-body">
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

            <div class="mt-3 text-muted">
                School-side status: <strong>{{ $item->statusLabel() }}</strong>
                · Forwarded {{ $item->forwarded_at->format('d M Y, h:i A') }}{{ $item->forwarder ? ' by ' . $item->forwarder->name : '' }}
                @if($item->duplicates->count())
                    · {{ $item->duplicates->count() }} duplicate report{{ $item->duplicates->count() === 1 ? '' : 's' }} merged into this one by the Admin
                @endif
            </div>

            @if($item->admin_note)
                <div class="alert alert-info border-0 mt-3">
                    <strong>Admin note:</strong>
                    <div style="white-space:pre-wrap">{{ $item->admin_note }}</div>
                </div>
            @endif
        </div>
    </div>

    <div class="l106-panel l106-fb-card">
        <div class="l106-panel-head"><h6>Super Admin Handling</h6></div>
        <div class="l106-panel-body">
            <form method="post" action="{{ route('feedback.sa_update', $item->id) }}">
                @csrf @method('patch')
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="sa_status">Handling status</label>
                            {{-- Wave 16: a closed report cannot be reopened
                                 by the Super Admin — the control is
                                 disabled, and the hidden field keeps
                                 reply-only saves validating (a disabled
                                 select submits nothing). Only the school
                                 Admin can reopen it. --}}
                            <select name="sa_status" id="sa_status" class="form-control select" {{ $item->isClosedForSa() ? 'disabled' : '' }}>
                                @foreach($sa_statuses as $value => $label)
                                    <option value="{{ $value }}" {{ $item->sa_status == $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @if($item->isClosedForSa())
                                <input type="hidden" name="sa_status" value="fixed">
                                <p class="text-muted mt-1 mb-0">Closed — you cannot reopen this report. Only the school Admin can reopen it from the school side.</p>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label for="sa_reply">Platform-side note (the reporter never sees this — they see only the school-side status)</label>
                            <textarea name="sa_reply" id="sa_reply" rows="3" class="form-control" placeholder="Eg. Fixed in this week's update — the cooler has been replaced.">{{ old('sa_reply', $item->sa_reply) }}</textarea>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Reply <i class="icon-reply ml-1"></i></button>
            </form>
        </div>
    </div>
  </div>
@endsection
