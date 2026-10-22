@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('hide_page_header', true)
@section('content')
    {{-- Loop 96 — Parent dashboard premium pass (2026-10-22). Rebuilt
         to the Loop 79 / Loop 92 design standard: greeting header, five
         KPI cards, per-child cards, a combined Fees Due panel with the
         real Pay Fees path, factual low-attendance notes and the
         parent-visible circulars strip. Every figure comes from
         HomeController::parentHomeData — the SAME computations the old
         home used (PaymentRepo::getAllMyPR fee assembly, byte-identical
         to MyParent\MyController@fees, and attendanceSummary, the
         (present+late)/marked rule the Monthly Attendance Report and
         the Low Attendance page share). No routes, permissions or menu
         items change; the session stays in the topbar only. --}}
    <div class="p96-welcome">
        <div class="p96-welcome-main">
            <h2>{{ $greeting }}, {{ Auth::user()->name }}</h2>
            <p>
                @if($children->isNotEmpty())
                    {{ $children->count() }} {{ $children->count() === 1 ? 'child' : 'children' }} linked to your account — attendance, fees and school news at a glance.
                @else
                    Welcome to your parent workspace.
                @endif
            </p>
        </div>
        <div class="p96-welcome-side">
            <div class="p96-welcome-date">
                <i class="icon-calendar3"></i>
                <span><strong>{{ $today->format('l, d F Y') }}</strong></span>
            </div>
            <a href="{{ route('my_fees') }}" class="p96-take-btn">
                <i class="icon-coins"></i> View Fees
            </a>
        </div>
    </div>

    {{-- KPI cards — real figures for this parent's children only --}}
    <div class="p96-kpi-grid">
        <a href="{{ route('my_children') }}" class="p96-kpi p96-kpi-blue">
            <span class="p96-kpi-icon"><i class="icon-users4"></i></span>
            <span class="p96-kpi-copy">
                <span class="p96-kpi-label">My Children</span>
                <strong>{{ number_format($children->count()) }}</strong>
                <small>Linked to your account</small>
            </span>
            <span class="p96-kpi-chev"><i class="icon-arrow-right7"></i></span>
        </a>
        <a href="{{ route('my_fees') }}" class="p96-kpi p96-kpi-orange">
            <span class="p96-kpi-icon"><i class="icon-coins"></i></span>
            <span class="p96-kpi-copy">
                <span class="p96-kpi-label">Fees Due</span>
                <strong>{{ \App\Helpers\Money::inr($fees_due) }}</strong>
                <small>{{ $fees_due > 0 ? $fees_pending_records.' '.($fees_pending_records === 1 ? 'payment' : 'payments').' outstanding' : 'Nothing outstanding' }}</small>
            </span>
            <span class="p96-kpi-chev"><i class="icon-arrow-right7"></i></span>
        </a>
        <a href="{{ route('my_fees') }}" class="p96-kpi p96-kpi-green">
            <span class="p96-kpi-icon"><i class="icon-wallet"></i></span>
            <span class="p96-kpi-copy">
                <span class="p96-kpi-label">Fees Paid</span>
                <strong>{{ \App\Helpers\Money::inr($fees_paid) }}</strong>
                <small>Paid this session</small>
            </span>
            <span class="p96-kpi-chev"><i class="icon-arrow-right7"></i></span>
        </a>
        <a href="{{ route('attendance.my') }}" class="p96-kpi p96-kpi-lavender">
            <span class="p96-kpi-icon"><i class="icon-calendar52"></i></span>
            <span class="p96-kpi-copy">
                <span class="p96-kpi-label">Children&rsquo;s Attendance</span>
                @if($attendance_today['recorded'])
                    <strong>{{ $attendance_today['present'] }} of {{ $attendance_today['children'] }}</strong>
                    <small>Present today</small>
                @elseif($attendance_today['session_percent'] !== null)
                    <strong>{{ $attendance_today['session_percent'] }}%</strong>
                    <small>Attendance this session</small>
                @else
                    <strong>—</strong>
                    <small>No attendance recorded yet</small>
                @endif
            </span>
            <span class="p96-kpi-chev"><i class="icon-arrow-right7"></i></span>
        </a>
        <a href="{{ route('assignments.index') }}" class="p96-kpi p96-kpi-pink">
            <span class="p96-kpi-icon"><i class="icon-clipboard3"></i></span>
            <span class="p96-kpi-copy">
                <span class="p96-kpi-label">Pending Assignments</span>
                <strong>{{ number_format($lms_pending_assignments ?? 0) }}</strong>
                <small>{{ ($lms_pending_assignments ?? 0) > 0 ? 'Not yet submitted' : 'All caught up' }}</small>
            </span>
            <span class="p96-kpi-chev"><i class="icon-arrow-right7"></i></span>
        </a>
    </div>

    {{-- Fees Due — combined panel, shown only while any child owes.
         Each row's Pay Fees button lands on that child's fees page,
         where the existing online-payment checkout (Workstream CU)
         takes the payment. When everything is paid, a slim all-clear
         strip says so instead. --}}
    @if($children->isNotEmpty() && $fees_due > 0)
        <section class="p96-dues mb-4">
            <div class="p96-panel-head">
                <div class="p96-panel-title">
                    <span class="p96-panel-icon p96-tone-amber"><i class="icon-coins"></i></span>
                    <div>
                        <h5>Fees Due</h5>
                        <p class="p96-panel-sub">Outstanding fees for this session — pay online or at the school office.</p>
                    </div>
                </div>
                <div class="p96-dues-total">
                    <small>Total outstanding</small>
                    <strong>{{ \App\Helpers\Money::inr($fees_due) }}</strong>
                </div>
            </div>
            @foreach($children as $child)
                @if($child['fees']['due'] > 0)
                    @php($dRecord = $child['record'])
                    @php($dWords = preg_split('/\s+/', trim(optional($dRecord->user)->name ?? '')))
                    @php($dIni = strtoupper(substr($dWords[0] ?? '', 0, 1).(isset($dWords[1]) ? substr($dWords[1], 0, 1) : '')))
                    <div class="p96-dues-row">
                        <span class="p96-avatar p96-avatar-sm" aria-hidden="true">{{ $dIni }}@if(Qs::hasRealPhoto(optional($dRecord->user)->photo))<img src="{{ $dRecord->user->photo }}" alt="" onerror="this.remove()">@endif</span>
                        <span class="p96-dues-copy">
                            <strong>{{ optional($dRecord->user)->name }}</strong>
                            <small>{{ optional($dRecord->my_class)->name ?: 'Class —' }} &middot; {{ $child['fees']['pending_records'] }} {{ $child['fees']['pending_records'] === 1 ? 'payment' : 'payments' }} outstanding</small>
                        </span>
                        <span class="p96-dues-amount">{{ \App\Helpers\Money::inr($child['fees']['due']) }}</span>
                        <a href="{{ route('my_fees.show', $dRecord->user_id) }}" class="p96-pay-btn"><i class="icon-coins"></i> Pay Fees</a>
                    </div>
                @endif
            @endforeach
        </section>
    @elseif($children->isNotEmpty() && $fees_total > 0)
        <div class="p96-clear mb-4">
            <span class="p96-clear-icon"><i class="icon-checkmark3"></i></span>
            <span><strong>Fees clear</strong> — nothing outstanding for this session.</span>
            <a href="{{ route('my_fees') }}" class="p96-view-all">View fee history <i class="icon-arrow-right7"></i></a>
        </div>
    @endif

    {{-- Child cards --}}
    @if($children->isNotEmpty())
        <div class="p96-children">
            @foreach($children as $child)
                @php($record = $child['record'])
                @php($words = preg_split('/\s+/', trim(optional($record->user)->name ?? '')))
                @php($ini = strtoupper(substr($words[0] ?? '', 0, 1).(isset($words[1]) ? substr($words[1], 0, 1) : '')))
                @php($fees_paid_pct = $child['fees']['total'] > 0 ? (int) round(($child['fees']['paid'] / $child['fees']['total']) * 100) : 0)
                <section class="p96-panel p96-child">
                    <div class="p96-child-head">
                        <span class="p96-avatar" aria-hidden="true">{{ $ini }}@if(Qs::hasRealPhoto(optional($record->user)->photo))<img src="{{ $record->user->photo }}" alt="" onerror="this.remove()">@endif</span>
                        <div class="p96-child-id">
                            <h5>{{ optional($record->user)->name }}</h5>
                            <p>
                                {{ optional($record->my_class)->name ?: 'Class —' }} &middot; {{ optional($record->section)->name ?: 'Section —' }}
                                @if($record->adm_no) &middot; Adm No: {{ $record->adm_no }} @endif
                            </p>
                        </div>
                        @if($child['fees']['pending_records'] > 0)
                            <span class="p96-pill p96-pill-amber">Due {{ \App\Helpers\Money::inr($child['fees']['due']) }}</span>
                        @elseif($child['fees']['has_records'])
                            <span class="p96-pill p96-pill-green">Paid in full</span>
                        @else
                            <span class="p96-pill p96-pill-grey">No fee records yet</span>
                        @endif
                    </div>

                    <div class="p96-child-stats">
                        <div class="p96-child-stat">
                            <small>Attendance this session</small>
                            @if($child['attendance']['percent'] !== null)
                                <strong>{{ $child['attendance']['percent'] }}%</strong>
                                <span>{{ $child['attendance']['present'] + $child['attendance']['late'] }} of {{ $child['attendance']['total'] }} marked days present</span>
                            @else
                                <strong>—</strong>
                                <span>No registers marked yet</span>
                            @endif
                        </div>
                        <div class="p96-child-stat">
                            <small>Fees this session</small>
                            @if($child['fees']['has_records'])
                                <strong>{{ \App\Helpers\Money::inr($child['fees']['paid']) }}</strong>
                                <span>paid of {{ \App\Helpers\Money::inr($child['fees']['total']) }}@if($child['fees']['due'] > 0) &middot; {{ \App\Helpers\Money::inr($child['fees']['due']) }} due @endif</span>
                            @else
                                <strong>—</strong>
                                <span>No fee records yet</span>
                            @endif
                        </div>
                    </div>

                    @if($child['fees']['has_records'])
                        <div class="p96-progress-wrap">
                            <div class="p96-progress"><span style="width: {{ $fees_paid_pct }}%"></span></div>
                            <small>{{ $fees_paid_pct }}% of this session&rsquo;s fees paid</small>
                        </div>
                    @endif

                    {{-- Low attendance — the Low Attendance page's rule:
                         (present + late) / marked below 75%. Wording stays
                         factual; the day-by-day record is one click away. --}}
                    @if($child['attendance']['percent'] !== null && $child['attendance']['percent'] < 75)
                        <div class="p96-note">
                            <i class="icon-warning2"></i>
                            <span>Attendance is <strong>{{ $child['attendance']['percent'] }}%</strong> this session — below the 75% mark.
                                <a href="{{ route('attendance.my', ['student_id' => $record->id]) }}">See the day-by-day record</a></span>
                        </div>
                    @endif

                    <div class="p96-child-links">
                        <a href="{{ route('tt.mine', ['child' => $record->id]) }}"><i class="icon-table2"></i><span>Timetable</span></a>
                        <a href="{{ route('my_fees.show', $record->user_id) }}"><i class="icon-coins"></i><span>Fees</span></a>
                        <a href="{{ route('attendance.my', ['student_id' => $record->id]) }}"><i class="icon-calendar52"></i><span>Attendance</span></a>
                        <a href="{{ route('marks.year_selector', Qs::hash($record->user_id)) }}"><i class="icon-stats-bars"></i><span>Marksheet</span></a>
                    </div>
                </section>
            @endforeach
        </div>
    @else
        <section class="p96-panel mb-4">
            <div class="p96-empty">
                <i class="icon-users4"></i>
                <h5>No children are linked to your account yet</h5>
                <p>Once the school links your child&rsquo;s record to your account, their attendance, marks and fee status will appear here. If your child is already enrolled, please contact the school office and mention your registered email address.</p>
            </div>
        </section>
    @endif

    <div class="p96-bottom">
        {{-- Recent circulars — Circular::published() + forAudience('parent')
             is exactly Circular::isVisibleTo('parent'): published, inside
             the display window, addressed to everyone or to parents. --}}
        <section class="p96-panel">
            <div class="p96-panel-head">
                <div class="p96-panel-title">
                    <span class="p96-panel-icon p96-tone-blue"><i class="icon-megaphone"></i></span>
                    <div>
                        <h5>Recent Circulars</h5>
                        <p class="p96-panel-sub">Notices for parents from the school office</p>
                    </div>
                </div>
                <a href="{{ route('circulars.index') }}" class="p96-view-all">View All <i class="icon-arrow-right7"></i></a>
            </div>
            @if($circulars_week_count > 0)
                <p class="p96-head-note">{{ $circulars_week_count }} new {{ $circulars_week_count === 1 ? 'circular' : 'circulars' }} in the last 7 days</p>
            @endif
            @forelse($circulars as $circular)
                <a href="{{ route('circulars.show', $circular->id) }}" class="p96-row">
                    <span class="p96-row-icon p96-tone-blue"><i class="icon-megaphone"></i></span>
                    <span class="p96-row-copy">
                        <strong>{{ $circular->title }}</strong>
                        <small>{{ \App\Models\Circular::audiences()[$circular->audience] ?? 'Everyone' }} &middot; {{ ($circular->starts_at ?: $circular->created_at)->format('d M Y') }}</small>
                    </span>
                    <span class="p96-pill p96-pill-pri-{{ $circular->priority }}">{{ \App\Models\Circular::priorities()[$circular->priority] ?? 'Normal' }}</span>
                </a>
            @empty
                <div class="p96-empty">
                    <i class="icon-megaphone"></i>
                    <p>No circulars for parents right now. New notices from the school office will appear here.</p>
                </div>
            @endforelse
        </section>

        {{-- Upcoming events & exams (school calendar + exam timetables) --}}
        <section class="p96-panel">
            <div class="p96-panel-head">
                <div class="p96-panel-title">
                    <span class="p96-panel-icon p96-tone-green"><i class="icon-calendar3"></i></span>
                    <div>
                        <h5>Upcoming Events &amp; Exams</h5>
                        <p class="p96-panel-sub">School events and examinations</p>
                    </div>
                </div>
            </div>
            @forelse($upcoming_events as $event)
                <div class="p96-row">
                    <span class="p96-event-date"><small>{{ $event['month'] }}</small><strong>{{ $event['day'] }}</strong></span>
                    <span class="p96-row-copy">
                        <strong>{{ $event['title'] }}</strong>
                        <small>{{ $event['meta'] }}</small>
                    </span>
                    <span class="p96-pill p96-pill-event-{{ $event['type'] }}">{{ $event['type_label'] }}</span>
                </div>
            @empty
                <div class="p96-empty">
                    <i class="icon-calendar3"></i>
                    <p>No upcoming events. School events and examination dates will appear here.</p>
                </div>
            @endforelse
        </section>
    </div>
@endsection
