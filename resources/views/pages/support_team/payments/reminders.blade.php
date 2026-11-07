@extends('layouts.master')
@section('page_title', 'Fee Reminders')
@section('page_icon')
    <span class="l118-page-icon l118-blue-tile" aria-hidden="true"><i class="icon-bell2"></i></span>
@endsection
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('payments.manage') }}">Payments</a> &nbsp;/&nbsp; Fee Reminders
@endsection
@section('content')
    {{-- F5 (design §6.3): the reminders panel + log. Master state,
         due counts and trigger health on top; the log below is a
         proper hk-std table, server-paginated, filterable and
         CSV-exportable. Admin + Accountant only (controller gate). --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Automatic fee reminders for session {{ $year }} — who was reminded, when, and for how much.
        @endslot
        @slot('action')
            <a href="{{ route('payments.reminders.export', request()->query()) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Export CSV</a>
            <form method="post" action="{{ route('payments.reminders.run') }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm"><i class="icon-bell2 mr-1"></i> Run due reminders now</button>
            </form>
        @endslot

        <div class="row dashboard-stats">
            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-stat-card dashboard-stat-blue">
                    <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-bell2"></i></span></span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">Automatic Reminders</span>
                        <strong>{{ $enabled ? 'Enabled' : 'Disabled' }}</strong>
                        <small>Offsets: {{ implode(', ', $offsets) }} &middot; Bell: {{ $channel_bell == '1' ? 'On' : 'Off' }} &middot; WhatsApp: {{ $channel_whatsapp == '1' ? 'On' : 'Off' }}
                            @if($is_admin)<a href="{{ route('settings') }}#set-fee-reminders">Edit in Settings</a>@endif
                        </small>
                    </span>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-stat-card dashboard-stat-orange">
                    <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar3"></i></span></span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">Due Today</span>
                        <strong>{{ number_format($due_today) }}</strong>
                        <small>student(s) would be reminded today</small>
                    </span>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-stat-card dashboard-stat-green">
                    <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar52"></i></span></span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">Next 7 Days</span>
                        <strong>{{ number_format($due_next_7) }}</strong>
                        <small>student reminder(s) due in the next 7 days</small>
                    </span>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-stat-card dashboard-stat-pink">
                    <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-warning2"></i></span></span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">No Due Date</span>
                        <strong>{{ number_format($age_population) }}</strong>
                        <small>student(s) with dues but no instalment due date — reminded by fee age</small>
                    </span>
                </div>
            </div>
        </div>

        <p class="text-muted mt-2">
            @if($last_run_label)
                Automatic reminders last ran {{ $last_run_label }}. They run when this page or the dashboard is opened, or from an external daily ping if configured.
            @else
                Automatic reminders have not run yet. They run when this page or the dashboard is opened, or from an external daily ping if configured.
            @endif
            @if($channel_whatsapp == '1')
                WhatsApp reminders require WhatsApp setup — until it is connected they are logged as skipped.
            @endif
        </p>

        <form method="GET" action="{{ route('payments.reminders') }}" class="pat-filter-form print-hide">
            <div class="pat-filter-field">
                <label for="fr_from">From</label>
                <input type="date" id="fr_from" name="from" class="form-control" value="{{ $filters['from_str'] }}">
            </div>
            <div class="pat-filter-field">
                <label for="fr_to">To</label>
                <input type="date" id="fr_to" name="to" class="form-control" value="{{ $filters['to_str'] }}">
            </div>
            <div class="pat-filter-field">
                <label for="fr_channel">Channel</label>
                <select id="fr_channel" name="channel" class="form-control">
                    <option value="">All Channels</option>
                    <option value="bell" {{ $filters['channel'] === 'bell' ? 'selected' : '' }}>Bell</option>
                    <option value="whatsapp" {{ $filters['channel'] === 'whatsapp' ? 'selected' : '' }}>WhatsApp</option>
                </select>
            </div>
            <div class="pat-filter-field">
                <label for="fr_trigger">Trigger</label>
                <select id="fr_trigger" name="trigger" class="form-control">
                    <option value="">All Triggers</option>
                    <option value="scheduled" {{ $filters['trigger'] === 'scheduled' ? 'selected' : '' }}>Scheduled</option>
                    <option value="manual" {{ $filters['trigger'] === 'manual' ? 'selected' : '' }}>Manual</option>
                </select>
            </div>
            <div class="pat-filter-field">
                <label for="fr_class">Class</label>
                <select id="fr_class" name="class_id" class="form-control">
                    <option value="">All Classes</option>
                    @foreach($classes as $class)
                        <option value="{{ $class->id }}" {{ $filters['class_id'] === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-filter-field">
                <label>&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="{{ route('payments.reminders') }}" class="btn btn-light btn-sm">Reset</a>
                </div>
            </div>
        </form>

        @if($logs->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-bell2',
                'message' => 'No fee reminders have been logged yet. Sent, skipped and manual reminders will appear here.',
            ])
        @else
            <div class="table-responsive">
                <table class="table hk-std hk-std-fee-reminders">
                    <colgroup><col style="width:12%"><col style="width:16%"><col style="width:11%"><col style="width:11%"><col style="width:10%"><col style="width:11%"><col style="width:9%"><col style="width:9%"><col style="width:11%"></colgroup>
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Student</th>
                        <th>Class–Section</th>
                        <th>Parent</th>
                        <th>Channel</th>
                        <th>Rule</th>
                        <th class="text-right">Amount due</th>
                        <th>Sent by</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td><strong>{{ $log->created_at ? $log->created_at->format('d M Y') : '—' }}</strong>@if($log->created_at)<br><small class="text-muted">{{ $log->created_at->format('h:i A') }}</small>@endif</td>
                            <td>
                                <strong>{{ optional(optional($log->student_record)->user)->name ?: optional($log->student)->name ?: '—' }}</strong>
                                @if(optional($log->student_record)->adm_no)<br><small class="text-muted">{{ $log->student_record->adm_no }}</small>@endif
                            </td>
                            <td>{{ optional(optional($log->student_record)->my_class)->name ?: '—' }} – {{ optional(optional($log->student_record)->section)->name ?: '—' }}</td>
                            <td>{{ optional($log->parent_user)->name ?: '—' }}</td>
                            <td>{{ $log->channel === 'whatsapp' ? 'WhatsApp' : 'Bell' }}</td>
                            <td>{{ \App\Http\Controllers\SupportTeam\FeeReminderController::ruleLabel($log) }}</td>
                            <td class="text-right">{{ \App\Helpers\Money::inr($log->amount_due) }}</td>
                            <td>{{ $log->sent_by ? (optional($log->sender)->name ?: '—') : 'System' }}</td>
                            <td>
                                @if($log->status === 'sent')<span class="badge badge-success">Sent</span>
                                @elseif($log->status === 'skipped_paid')<span class="badge badge-secondary">Skipped – paid</span>
                                @elseif($log->status === 'skipped_no_channel')<span class="badge badge-warning">Skipped – no channel</span>
                                @else<span class="badge badge-danger">{{ \App\Http\Controllers\SupportTeam\FeeReminderController::statusLabel($log) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <span class="text-muted">Showing {{ $logs->firstItem() }} to {{ $logs->lastItem() }} of {{ number_format($logs->total()) }} entries</span>
                <span>{{ $logs->links() }}</span>
            </div>
        @endif
    @endcomponent
@endsection
