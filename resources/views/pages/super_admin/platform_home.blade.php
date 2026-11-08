@extends('layouts.master')
@section('page_title', 'Platform Overview')
@section('hide_page_header', true)
@section('content')
    @include('partials.role_banner', ['hkRole' => 'superadmin', 'hkLabel' => 'Super Admin'])
    {{-- Workstream R: Platform Super Admin home — platform oversight
         (users & roles, audit & security), deliberately
         separate from the school's daily-operations dashboard.
         Workstream DD (owner ruling 2026-10-22): the Super Admin is
         platform-only — there is no path from this page into the
         school at all; the old "Open School Dashboard" action and the
         school-page tiles/links were removed, and the aggregates that
         remain are unlinked.
         Loop 106 (owner-flagged live 2026-10-23): the platform surfaces
         get the same premium dark-first design system as the school
         Loops — the .l106 scope styles every component below (hero,
         stat cards, panels, snapshot tiles, checklist) in both
         themes — and the user figures are platform accounts only. --}}
    <div class="l106 l106-platform">
    <div class="dashboard-hero mb-4">
        <div>
            <p class="mb-0 dashboard-muted">Hackstra platform overview for {{ $school_name ?: 'your school' }} — user accounts and security activity at a glance.</p>
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
    <div class="row dashboard-stats hk-reveal">
        @foreach([
            ['label' => 'Platform Accounts', 'value' => number_format($total_users), 'sub' => 'Super Admins + School Admins · ' . number_format($signed_in_this_week) . ' signed in this week' . (($suspended_users || $terminated_users) ? ' · ' . number_format($suspended_users) . ' suspended · ' . number_format($terminated_users) . ' terminated' : ''), 'icon' => 'icon-users4', 'tone' => 'blue', 'link' => route('users.index')],
            ['label' => 'Audit Entries Today', 'value' => number_format($audit_today), 'sub' => 'Recorded actions today', 'icon' => 'icon-shield-check', 'tone' => 'green', 'link' => route('audit_logs.index')],
            ['label' => 'Audit Entries This Week', 'value' => number_format($audit_week), 'sub' => 'Since ' . $audit_week_from->format('l, d M'), 'icon' => 'icon-stats-bars', 'tone' => 'orange', 'link' => route('audit_logs.index')],
            ['label' => 'Settings Unlock Waiting', 'value' => number_format($settings_unlock_waiting ?? 0), 'sub' => 'School settings unlock requests the Principal has approved — your final decision', 'icon' => 'icon-lock2', 'tone' => 'orange', 'link' => route('settings_unlock.index')],
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

    <div class="row platform-overview hk-reveal hk-reveal-1">
        <div class="col-xl-8">
            {{-- Platform accounts by role — platform accounts only
                 (Loop 106); the school's people are counted in the
                 School Snapshot panel below. --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-users2 text-blue mr-1"></i> Platform Accounts by Role</h5><p class="dashboard-muted mb-0">Who runs the platform and the school office</p></div>
                    <a href="{{ route('users.index') }}" class="dashboard-view-all">Manage Users <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table hk-std hk-std-560 mb-0">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:34%"><col style="width:20%"><col style="width:46%"></colgroup>
                        <thead><tr><th>Role</th><th>Accounts</th><th>Last sign-in</th></tr></thead>
                        <tbody>
                        @foreach($role_counts as $role)
                            <tr>
                                <td><strong>{{ $role['label'] }}</strong></td>
                                <td>{{ number_format($role['total']) }}</td>
                                <td class="hk-nw">{{ $role['last_sign_in'] ? $role['last_sign_in']->format('d M Y, h:i A') : '—' }}</td>
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
                    <table class="table hk-std hk-std-640 mb-0">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:24%"><col style="width:26%"><col style="width:26%"><col style="width:24%"></colgroup>
                        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Subject</th></tr></thead>
                        <tbody>
                        @foreach($recent_audit as $log)
                            <tr>
                                <td class="hk-nw">{{ $log->created_at->format('d M Y, h:i A') }}</td>
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
                    {{-- Round 5 Slice 4 (P14): the current licence
                         RECORD as a badge, only when a record
                         exists — no record, no badge, never a
                         placeholder plan. (P16): the referral log
                         link lives here too — platform area only,
                         no sidebar addition. --}}
                    @if(!empty($school_licence))
                        <div class="platform-snap-foot">
                            <p class="mb-0"><strong>Licence:</strong> {{ $school_licence->plan_name }}
                                <span class="dashboard-status {{ $school_licence->status === 'active' ? 'status-success' : ($school_licence->status === 'past_due' ? 'status-warning' : '') }}">{{ \App\Models\SchoolLicence::statusLabel($school_licence->status) }}</span>
                                @if($school_licence->renews_on)<span class="dashboard-muted">· Renews {{ $school_licence->renews_on->format('d M Y') }}</span>@endif
                            </p>
                        </div>
                    @endif
                    <div class="platform-snap-foot">
                        <p class="dashboard-muted mb-0">Day-to-day school operations are managed by the school Admin. This panel is a read-only size signal for the platform.</p>
                        <p class="mb-0 mt-2">
                            @if(Route::has('platform_licence.edit'))<a href="{{ route('platform_licence.edit') }}" class="dashboard-view-all">{{ !empty($school_licence) ? 'Manage Licence' : 'Record Licence' }} <i class="icon-arrow-right7"></i></a>@endif
                            @if(Route::has('platform_referrals.index'))<a href="{{ route('platform_referrals.index') }}" class="dashboard-view-all ml-3">Referrals <i class="icon-arrow-right7"></i></a>@endif
                        </p>
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

            {{-- Recent sign-ins — successful logins (failed attempts are
                 recorded too; they live in the Security panel below) --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-enter text-blue mr-1"></i> Recent Sign-ins</h5><p class="dashboard-muted mb-0">Latest successful sign-ins.</p></div>
                </div>
                <div class="table-responsive l17-list-scroll">
                    <table class="table hk-std hk-std-560 mb-0">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:40%"><col style="width:32%"><col style="width:28%"></colgroup>
                        <thead><tr><th>User</th><th>When</th><th>IP</th></tr></thead>
                        <tbody>
                        @forelse($recent_logins as $login)
                            <tr>
                                <td><strong>{{ optional($login->user)->name ?: '—' }}</strong></td>
                                <td class="hk-nw">{{ $login->created_at->format('d M, h:i A') }}</td>
                                <td>{{ $login->ip_address ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">@include('partials.patterns.empty_state', ['icon' => 'icon-history', 'message' => 'No sign-ins recorded yet.', 'compact' => true])</td></tr>
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
                    @include('partials.patterns.empty_state', ['icon' => 'icon-shield-check', 'message' => 'No failed sign-in attempts recorded.', 'compact' => true])
                @endforelse
            </section>

        </div>
    </div>
    </div>{{-- /.l106 --}}
@endsection
