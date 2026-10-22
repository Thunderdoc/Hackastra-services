@extends('layouts.master')
@section('page_title', 'Platform Overview')
@section('hide_page_header', true)
@section('content')
    {{-- Workstream R: Platform Super Admin home — platform oversight
         (users & roles, audit & security, configuration), deliberately
         separate from the school's daily-operations dashboard.
         Workstream DD (owner ruling 2026-10-22): the Super Admin is
         platform-only — there is no path from this page into the
         school at all; the old "Open School Dashboard" action and the
         school-page tiles/links were removed, and the aggregates that
         remain are unlinked. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Hackstra platform overview for {{ $school_name ?: 'your school' }} — user accounts, security activity and configuration at a glance.</p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                </span>
            </div>
        </div>
    </div>

    {{-- Platform headline numbers — every value comes from a live query --}}
    <div class="row dashboard-stats">
        @foreach([
            ['label' => 'Total Users', 'value' => number_format($total_users), 'sub' => 'Accounts across all roles · ' . number_format($signed_in_this_week) . ' signed in this week' . (($suspended_users || $terminated_users) ? ' · ' . number_format($suspended_users) . ' suspended · ' . number_format($terminated_users) . ' terminated' : ''), 'icon' => 'icon-users4', 'tone' => 'blue', 'link' => route('users.index')],
            ['label' => 'Audit Entries Today', 'value' => number_format($audit_today), 'sub' => 'Recorded actions today', 'icon' => 'icon-shield-check', 'tone' => 'green', 'link' => route('audit_logs.index')],
            ['label' => 'Audit Entries This Week', 'value' => number_format($audit_week), 'sub' => 'Since ' . $audit_week_from->format('l, d M'), 'icon' => 'icon-stats-bars', 'tone' => 'orange', 'link' => route('audit_logs.index')],
            {{-- Workstream DD: the Configuration aggregate stays (it is
                 a platform-level completeness signal) but is UNLINKED —
                 the settings page itself is the school's. --}}
            ['label' => 'Configuration', 'value' => $settings_done . ' of ' . $settings_total, 'sub' => $settings_missing->isNotEmpty() ? 'Missing: ' . $settings_missing->implode(', ') : 'All key settings completed', 'icon' => 'icon-cog5', 'tone' => 'purple', 'link' => null],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                @if($stat['link'])<a href="{{ $stat['link'] }}" class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">@else<div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">@endif
                    <span class="dashboard-stat-top">
                        <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                    </span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                        <strong>{{ $stat['value'] }}</strong>
                        <small>{{ $stat['sub'] }}</small>
                    </span>
                @if($stat['link'])</a>@else</div>@endif
            </div>
        @endforeach
    </div>

    <div class="row platform-overview">
        <div class="col-xl-8">
            {{-- Users by role — grouped counts from the users table --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-users2 text-blue mr-1"></i> Users by Role</h5><p class="dashboard-muted mb-0">Who can sign in, and as what</p></div>
                    <a href="{{ route('users.index') }}" class="dashboard-view-all">Manage Users <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Role</th><th>Accounts</th><th>Last sign-in</th></tr></thead>
                        <tbody>
                        @foreach($role_counts as $role)
                            <tr>
                                <td><strong>{{ $role['label'] }}</strong></td>
                                <td>{{ number_format($role['total']) }}</td>
                                <td>{{ $role['last_sign_in'] ? $role['last_sign_in']->format('d M Y, h:i A') : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Recent audit activity — the same records the audit viewer lists --}}
            <section class="dashboard-panel platform-audit-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-history text-blue mr-1"></i> Recent Audit Activity</h5><p class="dashboard-muted mb-0">Who did what, and when — latest first</p></div>
                    <a href="{{ route('audit_logs.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @if($recent_audit->isNotEmpty())
                <div class="table-responsive l17-list-scroll">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Subject</th></tr></thead>
                        <tbody>
                        @foreach($recent_audit as $log)
                            <tr>
                                <td>{{ $log->created_at->format('d M Y, h:i A') }}</td>
                                <td><strong>{{ optional($log->user)->name ?: '—' }}</strong></td>
                                <td><code>{{ $log->action }}</code></td>
                                <td>{{ $log->subject_type ? class_basename($log->subject_type) . ($log->subject_id ? ' #' . $log->subject_id : '') : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                {{-- Empty feed gets the shared empty-state pattern
                     (Workstream CA) instead of a lone table row. --}}
                <div class="platform-audit-empty">
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-history',
                        'message' => 'No audit entries yet. Sign-ins, payments, mark updates and deletions will appear here.',
                    ])
                </div>
                @endif
            </section>

            {{-- School snapshot — secondary, clearly labelled: this
                 deployment serves a single school, so these are that
                 school's headline sizes, not tenant metrics. Moved from
                 the rail into the main column as a stat band
                 (Workstream CA) so the main column carries the
                 content-rich material. --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-windows2 text-blue mr-1"></i> School Snapshot</h5><p class="dashboard-muted mb-0">{{ $school_name ?: 'This school' }} at a glance</p></div>
                </div>
                <div class="platform-snap-body">
                    <div class="platform-snap-grid">
                        <div class="platform-snap-tile">
                            <span class="platform-snap-icon snap-blue"><i class="icon-graduation2"></i></span>
                            <span class="platform-snap-copy"><small>Active students</small><strong>{{ number_format($snapshot_student_count) }}</strong></span>
                        </div>
                        <div class="platform-snap-tile">
                            <span class="platform-snap-icon snap-green"><i class="icon-users4"></i></span>
                            <span class="platform-snap-copy"><small>Teachers</small><strong>{{ number_format($snapshot_teacher_count) }}</strong></span>
                        </div>
                        {{-- Numbers-not-records (owner ruling 2026-10-22):
                             the lifetime collection TOTAL is a platform
                             statistic — a plain number, never a link into
                             the school's payment records. --}}
                        <div class="platform-snap-tile">
                            <span class="platform-snap-icon snap-blue"><i class="icon-coins"></i></span>
                            <span class="platform-snap-copy"><small>Total fee collection</small><strong>{{ \App\Helpers\Money::inr($snapshot_collection_total) }}</strong></span>
                        </div>
                    </div>
                    <div class="platform-snap-foot">
                        <p class="dashboard-muted mb-0">Day-to-day school operations are managed by the school Admin. This panel is a read-only size signal for the platform.</p>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Platform quick actions --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-rocket text-blue mr-1"></i> Platform Actions</h5><p class="dashboard-muted mb-0">Platform-level administration.</p></div>
                </div>
                <div class="dashboard-action-grid l17-action-grid">
                    {{-- Zero-tolerance dedup: no "Manage Users" tile here — the
                         Users panel footer below is that path; no "Audit Logs"
                         tile either — the Activity panel footer is that path. --}}
                    <a href="{{ route('users.create') }}"><i class="icon-user-plus"></i><span>Create User</span></a>
                    {{-- Workstream DD: the School Settings / Grades Setup /
                         Sections tiles are gone — those pages are school
                         domain, and this account is platform-only. --}}
                </div>
            </section>

            {{-- Configuration checklist — key settings, set or missing --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-cog5 text-blue mr-1"></i> Configuration Checklist</h5><p class="dashboard-muted mb-0">{{ $settings_done }} of {{ $settings_total }} key settings completed{{ $settings_missing->isNotEmpty() ? ' · Missing: ' . $settings_missing->implode(', ') : '' }}</p></div>
                    {{-- Workstream DD: no Settings link — the settings page
                         belongs to the school Admin. The checklist itself
                         is a read-only platform aggregate and stays. --}}
                </div>
                @foreach($settings_checklist as $item)
                    <div class="dashboard-event">
                        <span class="event-copy"><strong>{{ $item['label'] }}</strong></span>
                        <span class="dashboard-status {{ $item['set'] ? 'status-success' : 'status-warning' }}">{{ $item['set'] ? 'Set' : 'Missing' }}</span>
                    </div>
                @endforeach
            </section>

            {{-- Recent sign-ins — successful logins (failed attempts are
                 recorded too; they live in the Security panel below) --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-enter text-blue mr-1"></i> Recent Sign-ins</h5><p class="dashboard-muted mb-0">Latest successful sign-ins.</p></div>
                </div>
                <div class="table-responsive l17-list-scroll">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>User</th><th>When</th><th>IP</th></tr></thead>
                        <tbody>
                        @forelse($recent_logins as $login)
                            <tr>
                                <td><strong>{{ optional($login->user)->name ?: '—' }}</strong></td>
                                <td>{{ $login->created_at->format('d M, h:i A') }}</td>
                                <td>{{ $login->ip_address ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="dashboard-empty">No sign-ins recorded yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Security — failed sign-in attempts (auth.failed audit
                 rows written by LogFailedLogin; the attempted identity is
                 carried in the row's description, never the password) --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-shield-check text-blue mr-1"></i> Security</h5><p class="dashboard-muted mb-0">Failed sign-in attempts</p></div>
                    <a href="{{ route('audit_logs.index') }}" class="dashboard-view-all">Audit Logs <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="dashboard-event">
                    <span class="event-copy"><strong>Failed attempts today</strong></span>
                    <span class="dashboard-status {{ $failed_today > 0 ? 'status-warning' : 'status-success' }}">{{ number_format($failed_today) }}</span>
                </div>
                @forelse($recent_failed as $attempt)
                    <div class="dashboard-event">
                        <span class="event-copy"><strong>{{ $attempt->description ?: '—' }}</strong><br><small class="dashboard-muted">{{ $attempt->created_at->format('d M Y, h:i A') }}</small></span>
                        <span class="dashboard-muted">{{ $attempt->ip_address ?: '—' }}</span>
                    </div>
                @empty
                    <p class="dashboard-empty mb-0">No failed sign-in attempts recorded.</p>
                @endforelse
            </section>

        </div>
    </div>
@endsection
