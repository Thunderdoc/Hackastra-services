@extends('layouts.master')
@section('page_title', 'Principal Dashboard')
@section('content')
    @include('partials.role_banner', ['hkRole' => 'principal', 'hkLabel' => 'Principal'])
<style>
.pr-wrap{color:#10263b}
.pr-hero{background:#10263b;color:#fff;border-radius:12px;padding:22px 24px;margin-bottom:18px}
.pr-hero h2{color:#fff;margin:0 0 4px;font-size:26px}
.pr-hero p{color:#dbe7fb;margin:0}
.pr-hero .pr-date{color:#dbe7fb;font-size:14px}
.pr-eyebrow{letter-spacing:.14em;text-transform:uppercase;font-size:11px;color:#9fc3ec;font-weight:700}
.pr-att{border-radius:12px;padding:4px 0;margin-bottom:18px}
.pr-att-item{background:#fff;border:1px solid #d7e0ec;border-left:5px solid #d64545;border-radius:10px;padding:14px 18px;margin-bottom:10px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
.pr-att-item.amber{border-left-color:#e8a317}
.pr-att-item strong{font-size:15px}
.pr-att-item p{margin:2px 0;color:#44546a;font-size:13px}
.pr-att-item .pr-meta{color:#718096;font-size:12px}
.pr-att-item a{white-space:nowrap;font-weight:700}
.pr-ok{background:#eafaf0;border:1px solid #bfe8cb;border-radius:10px;padding:14px 18px;margin-bottom:18px}
.pr-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
@media(max-width:991px){.pr-cols{grid-template-columns:1fr}}
.pr-card{background:#fff;border:1px solid #d7e0ec;border-radius:12px;padding:20px;margin-bottom:0}
.pr-card h5{margin:0 0 4px;font-size:17px;color:#10263b}
.pr-card p,.pr-card td{color:#44546a}
.pr-card td strong,.pr-card p strong{color:#10263b}
.pr-card .pr-sub{color:#718096;font-size:13px;margin:0 0 14px}
.pr-ops{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
@media(max-width:540px){.pr-ops{grid-template-columns:repeat(2,1fr)}}
.pr-op{background:#f7fafc;border-radius:10px;padding:12px 14px}
.pr-op strong{font-size:22px;display:block}
.pr-op span{color:#44546a;font-size:12px}
.pr-table{width:100%;border-collapse:collapse;font-size:14px}
.pr-table th{text-align:left;color:#718096;font-size:11px;letter-spacing:.08em;text-transform:uppercase;padding:6px 8px;border-bottom:1px solid #d7e0ec}
.pr-table td{padding:9px 8px;border-bottom:1px solid #eef2f7}
.pr-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:9px;font-size:13px}
.pr-bar-row .pr-bar-label{width:110px;flex:none;color:#44546a}
.pr-bar-track{flex:1;background:#eef2f7;border-radius:6px;height:12px;overflow:hidden}
.pr-bar-fill{background:#10263b;height:12px;border-radius:6px}
.pr-bar-val{width:44px;text-align:right;font-weight:700}
.pr-foot{color:#718096;font-size:12px;margin-top:10px}
/* Visual-coherence round (2026-11-06): the .pr-* surfaces above
   were hardcoded light, so in dark mode the attention strip and
   the cards rendered WHITE on the dark page (owner: "aadha ujla
   aadha black"). Every surface now follows the app's dark card
   tokens (#26272a / #36383c, the dashboard-stat-card surface);
   light theme keeps the look above, untouched. */
html.hk-dark .pr-wrap{color:#e7e8e9}
html.hk-dark .pr-att-item{background:#26272a;border-color:#36383c;border-left-color:#d64545}
html.hk-dark .pr-att-item.amber{border-left-color:#e8a317}
html.hk-dark .pr-att-item p{color:#c9cbce}
html.hk-dark .pr-att-item .pr-meta{color:#a5a8ad}
html.hk-dark .pr-ok{background:#123528;border-color:#1e5c40;color:#e7e8e9}
html.hk-dark .pr-card{background:#26272a;border-color:#36383c}
html.hk-dark .pr-card h5{color:#f4f4f5}
html.hk-dark .pr-card p,html.hk-dark .pr-card td{color:#c9cbce}
html.hk-dark .pr-card td strong,html.hk-dark .pr-card p strong{color:#f4f4f5}
html.hk-dark .pr-card .pr-sub{color:#a5a8ad}
html.hk-dark .pr-op{background:#2f3135}
html.hk-dark .pr-op span{color:#a5a8ad}
html.hk-dark .pr-table th{color:#a5a8ad;border-bottom-color:#36383c}
html.hk-dark .pr-table td{border-bottom-color:#36383c}
html.hk-dark .pr-bar-row .pr-bar-label{color:#c9cbce}
html.hk-dark .pr-bar-track{background:#3a3c40}
html.hk-dark .pr-bar-fill{background:#6ea8ff}
html.hk-dark .pr-foot{color:#a5a8ad}
</style>

<div class="pr-wrap">
    <div class="pr-hero hk-reveal">
        <div class="pr-eyebrow">School Command Center</div>
        <p>Whole-school situation at a glance — academics, attendance, staffing and decisions.</p>
        <div class="pr-date">{{ $today->format('l, d F Y') }} &middot; Session {{ $current_session }}</div>
    </div>

    @if(count($pr_attention) > 0)
        <div class="pr-att">
            @foreach($pr_attention as $item)
                <div class="pr-att-item {{ $item['tone'] === 'amber' ? 'amber' : '' }}">
                    <div>
                        <strong>{{ $item['title'] }}</strong>
                        <p>{{ $item['detail'] }}</p>
                        <div class="pr-meta">{{ $item['meta'] }}</div>
                    </div>
                    <a href="{{ $item['link'] }}">{{ $item['link_label'] }} &rarr;</a>
                </div>
            @endforeach
        </div>
    @else
        <div class="pr-ok"><strong>All clear today.</strong> No timetable clashes, uncovered substitutions, overdue approvals or incidents needing attention.</div>
    @endif

    {{-- Beauty Wave 2 (2026-11-06): the four executive KPIs move
         off this page's private pr-kpi island onto the shared
         dashboard-stat-card pattern every other role home uses —
         same labels, same values, same subtexts, same links; only
         the card chrome is now the site's one stat system (the
         pr-hero / pr-att / pr-card sections below stay as they
         are, they carry this page's own command-center layout). --}}
    <div class="row dashboard-stats hk-reveal hk-reveal-1">
        @foreach([
            ['label' => 'Students', 'value' => number_format($pr_students), 'sub' => 'Enrolled · ' . ($pr_attendance_today['recorded'] ? $pr_attendance_today['percent'] . '% present today' : 'No attendance recorded today'), 'icon' => 'icon-users', 'tone' => 'blue', 'href' => route('students.list', optional(App\Models\MyClass::first())->id)],
            ['label' => 'Staff', 'value' => number_format($pr_teachers), 'sub' => 'Teachers · ' . $pr_ops['substitutions_today'] . ' substitution' . ($pr_ops['substitutions_today'] === 1 ? '' : 's') . ' today', 'icon' => 'icon-users4', 'tone' => 'purple', 'href' => route('users.index')],
            ['label' => 'Academics', 'value' => $pr_exams_total, 'sub' => 'Exams this session · ' . $pr_exams_published . ' results published', 'icon' => 'icon-books', 'tone' => 'orange', 'href' => route('exams.index')],
            ['label' => 'Attention Needed', 'value' => count($pr_attention), 'sub' => $pr_pending_leaves_count . ' pending approval' . ($pr_pending_leaves_count === 1 ? '' : 's') . ' in the inbox', 'icon' => 'icon-alarm', 'tone' => 'red', 'href' => route('leave.index')],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                <a href="{{ $stat['href'] }}" class="l100-stat-link" aria-label="{{ $stat['label'] }}: {{ $stat['value'] }} — view list">
                    <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                        <span class="dashboard-stat-top">
                            <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                            <span class="l100-stat-arrow"><i class="icon-arrow-right7"></i></span>
                        </span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                            <strong>{{ $stat['value'] }}</strong>
                            <small>{{ $stat['sub'] }}</small>
                        </span>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="pr-cols">
        <section class="pr-card">
            <h5>Today&rsquo;s School Operations</h5>
            <p class="pr-sub">Whether the school is running smoothly or needs intervention.</p>
            <div class="pr-ops">
                <div class="pr-op"><strong>{{ $pr_ops['periods'] }}</strong><span>Periods today</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['registers_submitted'] }}</strong><span>Registers submitted</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['registers_pending'] }}</strong><span>Registers pending</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['exams_today'] }}</strong><span>Exams today</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['events_today'] }}</strong><span>School events today</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['substitutions_uncovered'] }}</strong><span>Uncovered periods</span></div>
            </div>
            @if(count($pr_ops['events_list']) > 0)
                <div class="pr-foot">
                    Today:
                    @foreach($pr_ops['events_list'] as $ev)
                        {{ $ev->title }}@if(!$loop->last), @endif
                    @endforeach
                </div>
            @endif
            <div class="pr-foot"><a href="{{ route('attendance.periods.index') }}">Period attendance</a> &middot; <a href="{{ route('attendance.report') }}">Monthly report</a> &middot; <a href="{{ route('tt.index') }}">Timetables</a></div>
        </section>

        <section class="pr-card">
            <h5>Approval Center</h5>
            <p class="pr-sub">Pending leave decisions and result publication requests. Emergency leaves auto-approve per policy and never wait here.</p>
            @if(count($pr_pending_leaves) > 0)
                <table class="pr-table">
                    <thead><tr><th>Applicant</th><th>Type</th><th>Dates</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_pending_leaves as $leave)
                        <tr>
                            <td><strong>{{ optional($leave->user)->name }}</strong></td>
                            <td>{{ ucfirst($leave->leave_type ?? 'normal') }}</td>
                            <td>{{ $leave->start_date }} &ndash; {{ $leave->end_date }}</td>
                            <td><a href="{{ route('leave.index') }}">Review</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if(count($pr_pub_requests) > 0)
                <table class="pr-table" style="margin-top:14px;">
                    <thead><tr><th>Result publication requested</th><th>Requested</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_pub_requests as $pubExam)
                        <tr>
                            <td><strong>{{ $pubExam->name }}</strong></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($pubExam->publish_requested_at)->format('d M Y') }}</td>
                            <td><a href="{{ route('exams.publication', $pubExam->id) }}">Review &amp; publish</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if(count($pr_tt_requests) > 0)
                <table class="pr-table" style="margin-top:14px;">
                    <thead><tr><th>Timetable approval requested</th><th>Class</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_tt_requests as $ttReq)
                        <tr>
                            <td><strong>{{ $ttReq->name }}</strong></td>
                            <td>{{ optional($ttReq->my_class)->name ?? 'Deleted class' }}</td>
                            <td><a href="{{ route('tt.approvals') }}">Review &amp; approve</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if(isset($pr_unlock_requests) && count($pr_unlock_requests) > 0)
                <table class="pr-table" style="margin-top:14px;">
                    <thead><tr><th>Settings unlock requested</th><th>Changes</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_unlock_requests as $unlockReq)
                        <tr>
                            <td><strong>{{ optional($unlockReq->requester)->name ?? 'Admin' }}</strong></td>
                            <td>{{ \Illuminate\Support\Str::limit($unlockReq->changes, 90) }}</td>
                            <td><a href="{{ route('settings_unlock.index') }}">Review &amp; approve</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if(count($pr_pending_leaves) === 0 && count($pr_pub_requests) === 0 && count($pr_tt_requests) === 0 && (isset($pr_unlock_requests) ? count($pr_unlock_requests) === 0 : true))
                <p>No pending approvals.</p>
            @endif
            <div class="pr-foot"><a href="{{ route('leave.index') }}">Open approval queue</a></div>
        </section>
    </div>

    <div class="pr-cols">
        <section class="pr-card">
            <h5>Academic &amp; Performance Analytics</h5>
            <p class="pr-sub">Class attendance, last 30 days. Summary first — drill down to records from here.</p>
            @if(count($pr_class_attendance) > 0)
                @foreach($pr_class_attendance as $row)
                    <div class="pr-bar-row">
                        <span class="pr-bar-label">{{ $row['class'] }}</span>
                        <span class="pr-bar-track"><span class="pr-bar-fill" style="width:{{ $row['percent'] }}%"></span></span>
                        <span class="pr-bar-val">{{ $row['percent'] }}%</span>
                    </div>
                @endforeach
            @else
                <p>No attendance records in the last 30 days.</p>
            @endif
            <div class="pr-foot">
                @if($pr_term_exam)
                    {{ $pr_term_exam->name }} class averages:
                    @foreach($pr_class_avgs as $row)
                        {{ $row['class'] }} {{ $row['avg'] }}@if(!$loop->last), @endif
                    @endforeach
                @endif
            </div>
            <div class="pr-foot">
                @if(count($pr_subject_avgs) > 0)
                    Top subjects by Term 1 average:
                    @foreach($pr_subject_avgs as $row)
                        {{ $row['subject'] }} {{ $row['avg'] }}@if(!$loop->last), @endif
                    @endforeach
                    &middot; <a href="{{ route('marks.tabulation') }}">Tabulation</a>
                @endif
            </div>
            <div class="pr-foot">
                Students needing academic support (Term 1 average below 50): <strong>{{ $pr_support_count }}</strong>
                &middot; Lesson plans filed: <strong>{{ $pr_lesson_plans }}</strong> (<a href="{{ route('lesson_plans.index') }}">Lesson plans</a>)
            </div>
        </section>

        <section class="pr-card">
            <h5>Finance &amp; Administration Overview</h5>
            <p class="pr-sub">Authorized totals only — detailed transactions stay on the finance pages.</p>
            <div class="pr-ops">
                <div class="pr-op"><strong>&#8377;{{ number_format($pr_fees_collected) }}</strong><span>Fees collected ({{ $today->format('Y') }})</span></div>
                <div class="pr-op"><strong>&#8377;{{ number_format($pr_fees_pending) }}</strong><span>Fees outstanding</span></div>
                <div class="pr-op"><strong>&#8377;{{ number_format($pr_expenses) }}</strong><span>Expenses ({{ $pr_expenses_count }} records)</span></div>
            </div>
            <div class="pr-foot"><a href="{{ route('payments.manage') }}">Fee records</a> &middot; <a href="{{ route('reports.builder') }}">Reports &amp; Analytics</a></div>
            <h5 style="margin-top:20px">Teacher Workload</h5>
            <p class="pr-sub">Most registers submitted, last 30 days.</p>
            @if(count($pr_workload) > 0)
                @foreach($pr_workload as $row)
                    <div class="pr-bar-row">
                        <span class="pr-bar-label">{{ $row['teacher'] }}</span>
                        <span class="pr-bar-track"><span class="pr-bar-fill" style="width:{{ min(100, $row['registers'] * 10) }}%"></span></span>
                        <span class="pr-bar-val">{{ $row['registers'] }}</span>
                    </div>
                @endforeach
            @else
                <p>No registers submitted in the last 30 days.</p>
            @endif
        </section>
    </div>
</div>
@endsection
