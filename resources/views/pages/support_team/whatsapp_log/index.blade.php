@extends('layouts.master')
@section('page_title', 'WhatsApp Log')
@section('page_icon')
    <span class="l118-page-icon l118-green-tile" aria-hidden="true"><i class="icon-bubbles4"></i></span>
@endsection
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; WhatsApp Log
@endsection
@section('content')
    {{-- F1 (Round 4, design §9): the WhatsApp outbox + sent-log.
         Phone numbers are masked — the full number never renders.
         Mocked rows are badged honestly: they were NOT sent. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Every WhatsApp message the school queued — sent, mocked, failed or skipped, with the reason. Messages send in small batches as events happen; {{ $queued_count }} waiting.
        @endslot
        @slot('stats')
            <div class="row dashboard-stats">
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-green">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-checkmark3"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Sent Today</span>
                            <strong>{{ number_format($stats['sent_today']) }}</strong>
                            <small>Accepted by WhatsApp today</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-blue">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-bubbles4"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Mocked</span>
                            <strong>{{ number_format($stats['mocked']) }}</strong>
                            <small>Logged in mock mode — not sent</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-red">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-warning2"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Failed</span>
                            <strong>{{ number_format($stats['failed']) }}</strong>
                            <small>Provider refused or unreachable</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-orange">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-blocked"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Skipped</span>
                            <strong>{{ number_format($stats['skipped']) }}</strong>
                            <small>No phone, opted out, disabled or cooldown</small>
                        </span>
                    </div>
                </div>
            </div>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('whatsapp_log.index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="wa_event">Event</label>
                    <select id="wa_event" name="event" class="form-control">
                        <option value="">All Events</option>
                        @foreach($events as $key => $label)
                            <option value="{{ $key }}" {{ $filters['event'] === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="wa_status">Status</label>
                    <select id="wa_status" name="status" class="form-control">
                        <option value="">All Statuses</option>
                        @foreach($statuses as $st)
                            <option value="{{ $st }}" {{ $filters['status'] === $st ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="wa_from">From</label>
                    <input type="date" id="wa_from" name="from" class="form-control" value="{{ $filters['from'] }}">
                </div>
                <div class="pat-filter-field">
                    <label for="wa_to">To</label>
                    <input type="date" id="wa_to" name="to" class="form-control" value="{{ $filters['to'] }}">
                </div>
                <div class="pat-filter-field">
                    <label for="wa_q">Parent / Child</label>
                    <input type="text" id="wa_q" name="q" class="form-control" value="{{ $filters['q'] }}" placeholder="Search name">
                </div>
                <div class="pat-filter-action">
                    <button class="btn btn-primary" type="submit">Filter</button>
                </div>
            </form>
        @endslot

        <table class="table hk-std hk-std-whatsapp">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Parent</th>
                    <th>Child</th>
                    <th>Event</th>
                    <th>Message</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Provider / Error</th>
                    <th>Attempts</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($messages as $m)
                <tr>
                    <td>{{ $m->created_at ? $m->created_at->format('d M Y, h:i A') : '—' }}</td>
                    <td>{{ optional($m->parent)->name ?: '—' }}</td>
                    <td>{{ optional(optional($m->studentRecord)->user)->name ?: '—' }}</td>
                    <td>{{ $events[$m->event] ?? $m->event }}</td>
                    <td>{{ \Illuminate\Support\Str::limit((string) $m->body_preview, 90) }}</td>
                    <td>{{ $m->maskedPhone() }}</td>
                    <td><span class="badge {{ \App\Http\Controllers\SupportTeam\WhatsAppLogController::statusBadge($m) }}">{{ \App\Http\Controllers\SupportTeam\WhatsAppLogController::statusLabel($m) }}</span></td>
                    <td>
                        @if($m->error_code)
                            <span class="text-danger">{{ $m->error_code }}@if($m->error_message) — {{ \Illuminate\Support\Str::limit((string) $m->error_message, 60) }}@endif</span>
                        @elseif($m->provider_message_id)
                            {{ $m->provider_message_id }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ (int) $m->attempts }}</td>
                    <td>
                        @if($is_admin && $m->status === 'failed' && (int) $m->attempts < 3)
                            <form method="post" action="{{ route('whatsapp_log.retry', Qs::hash($m->id)) }}" data-confirm="Retry this WhatsApp message?" data-confirm-title="Retry Message" data-confirm-action="Retry">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary"><i class="icon-redo2 mr-1"></i> Retry</button>
                            </form>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10">@include('partials.patterns.empty_state', ['icon' => 'icon-bubbles', 'message' => 'No WhatsApp messages yet. When WhatsApp is enabled in Settings, absence, fee, result, circular and homework messages will appear here — mocked ones included.'])</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">
            {{ $messages->links() }}
        </div>
    @endcomponent
@endsection
