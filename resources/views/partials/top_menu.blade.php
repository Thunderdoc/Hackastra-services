{{--
    Loop 33 — ONE common topbar for every page (Workstream AS).
    Included exactly once by layouts/master. Structure:
      [mobile toggle] [sidebar toggle] [global search — Ctrl+K focuses it]
      ... right: [Current Session chip x1] [bell] [user chip]
    There is deliberately NO page title here — titles live in the page
    header (partials/header). The session chip lives here and ONLY here
    (it was removed from partials/header so it can never render twice).
    The old theme-toggle (sun) button was removed: no JS handler or dark
    theme exists anywhere in the app, so it was a dead control.
    Loop 49/50 (Workstream BX) — the bar's design finish, and the theme
    toggle RETURNS, this time wired to a real dark theme (qs.css Loop 50):
    per-account, stored as the UserPreference 'theme' key, rendered
    server-side as html.hk-dark so there is never a flash.
--}}
<div class="navbar navbar-expand-md lav-topbar">
    <div class="lav-topbar-mobile">
        <button type="button" class="navbar-toggler sidebar-mobile-main-toggle" aria-label="Open navigation">
            <i class="icon-paragraph-justify3"></i>
        </button>
    </div>
    <a href="{{ route('dashboard') }}" class="lav-topbar-toggle sidebar-control sidebar-main-toggle" aria-label="Toggle navigation">
        <i class="icon-paragraph-justify3"></i>
    </a>
    <form class="lav-search" role="search" method="GET" action="{{ route('dashboard.search') }}">
        <i class="icon-search4"></i>
        <input type="search" name="q" id="lav-global-search" value="{{ request('q') }}" placeholder="{{ Qs::userIsSuperAdmin() ? 'Search platform users...' : ((Auth::user() && Auth::user()->user_type === 'librarian') ? 'Search students, books, teachers, classes…' : 'Search students, teachers, classes, payments...') }}" aria-label="Search">
        <kbd>Ctrl + K</kbd>
    </form>
    <script>
        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                var input = document.getElementById('lav-global-search');
                if (input) { input.focus(); input.select(); }
            }
        });
    </script>
    <div class="ml-auto d-flex align-items-center lav-topbar-right">
        {{-- Loop 56 — live clock (Workstream CC, owner: "timer should
             be in the header for all"). The server renders the initial
             time so it is right on first paint; the script then ticks
             it every second from the VIEWER's own clock. Quiet text in
             the bar's typography — not another pill. The date sits
             beside the time on wide screens only (Loop 56 CSS). --}}
        <span class="lav-clock" id="lav-clock"><span class="lav-clock-time" id="lav-clock-time">{{ now()->format('h:i:s A') }}</span><span class="lav-clock-date" id="lav-clock-date">{{ now()->format('D, d M') }}</span></span>
        <script>
            (function () {
                var timeEl = document.getElementById('lav-clock-time');
                var dateEl = document.getElementById('lav-clock-date');
                if (!timeEl) { return; }
                var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                function pad(n) { return (n < 10 ? '0' : '') + n; }
                function tick() {
                    var d = new Date();
                    var h = d.getHours();
                    var ap = h >= 12 ? 'PM' : 'AM';
                    h = h % 12; if (h === 0) { h = 12; }
                    timeEl.textContent = pad(h) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + ap;
                    if (dateEl) { dateEl.textContent = days[d.getDay()] + ', ' + pad(d.getDate()) + ' ' + months[d.getMonth()]; }
                }
                tick();
                setInterval(tick, 1000);
            })();
        </script>
        @if(Qs::userIsTeamSA() && !Qs::userIsSuperAdmin())
            <a href="{{ route('settings') }}" class="session-chip lav-session-chip" title="Open settings to change the current session"><i class="icon-calendar3"></i><span class="lav-session-label">Current Session:&nbsp;</span><strong>{{ Qs::getSetting('current_session') }}</strong></a>
        @else
            {{-- The Super Admin lands here too (Workstream DD): the
                 session is shown as information, but the settings page
                 is the school's — no link for the platform account. --}}
            <span class="session-chip lav-session-chip"><i class="icon-calendar3"></i><span class="lav-session-label">Current Session:&nbsp;</span><strong>{{ Qs::getSetting('current_session') }}</strong></span>
        @endif
        {{-- Loop 50 — dark-mode toggle: EVERY role, exactly once, beside
             the bell. This icon set has no moon glyph; the pair is the
             set's own contrast (to-dark) and sun3 (to-light) glyphs, and
             CSS shows exactly one per theme. The click flips html.hk-dark
             instantly, mirrors the choice to localStorage (so the login
             page honours it after logout) and persists it to the account
             preference store via AJAX — no reload. --}}
        <button type="button" class="lav-topbar-icon lav-theme-toggle" id="lav-theme-toggle" aria-label="Dark mode" title="Dark mode" aria-pressed="false"><i class="icon-contrast lav-theme-to-dark"></i><i class="icon-sun3 lav-theme-to-light"></i></button>
        <script>
            (function () {
                var btn = document.getElementById('lav-theme-toggle');
                if (!btn) { return; }
                var root = document.documentElement;
                function syncMirror() {
                    var dark = root.classList.contains('hk-dark');
                    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
                    try { localStorage.setItem('hk-theme', dark ? 'dark' : 'light'); } catch (e) {}
                }
                syncMirror();
                btn.addEventListener('click', function () {
                    var dark = !root.classList.contains('hk-dark');
                    root.classList.toggle('hk-dark', dark);
                    syncMirror();
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    fetch('{{ route('my_account.theme') }}', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '' },
                        body: 'theme=' + (dark ? 'dark' : 'light')
                    }).then(function (r) { if (!r.ok) { throw new Error('theme save failed'); } }).catch(function () {
                        // Batch fix 2026-10-25: the save did not stick
                        // (expired session on a long-open tab, network
                        // drop) — roll the screen back instead of
                        // leaving a theme the next page load flips
                        // back from. That flip-flop is exactly what
                        // reads as "changing by itself".
                        root.classList.toggle('hk-dark', !dark);
                        syncMirror();
                    });
                });
            })();
        </script>
        @php
            // Loop 56 (Workstream CC): bell events, badge and home
            // toasts. School events are school-wide records — the same
            // SchoolEvent source the dashboards and School Calendar
            // use (HomeController::upcomingEvents / buildCalendar),
            // with NO per-role scoping on the records themselves.
            // Only teamSA may open the events manager, so other roles
            // link through to their dashboard calendar instead.
            $lavEventsUrl = Qs::userIsTeamSA() ? route('events.index') : route('dashboard');
            $lavEventsLinkLabel = Qs::userIsTeamSA() ? 'View all events' : 'Open calendar';
            $lavUpcomingEvents = collect();
            $lavTodayEvents = collect();
            $lavEventsToday = 0;
            // Platform-only model (owner ruling 2026-10-22, Workstream
            // DD): school events are school data — the Super Admin's
            // bell, badge and home toasts never surface them.
            if (!Qs::userIsSuperAdmin() && \Illuminate\Support\Facades\Schema::hasTable('school_events')) {
                $lavUpcomingEvents = \App\Models\SchoolEvent::whereDate('starts_at', '>=', now()->toDateString())
                    ->orderBy('starts_at')->take(5)->get();
                $lavTodayEvents = $lavUpcomingEvents->filter(function ($lavEv) {
                    return $lavEv->starts_at && $lavEv->starts_at->isToday();
                })->take(3)->values();
                $lavEventsToday = \App\Models\SchoolEvent::whereDate('starts_at', now()->toDateString())->count();
            }
            // BADGE RULE (server-side, correct on first render): the
            // role's actionable notifications — for a teacher, the
            // TeacherReminders actionable count; every other role's
            // panel is a feed with no unread/actionable store, so 0 —
            // PLUS the number of school events happening today.
            $lavBellBadge = $lavEventsToday;
            $lavToastReminder = null;
            if (Qs::userIsTeacher()) {
                $lavBellBadge += \App\Support\TeacherReminders::actionableCount(Auth::user());
                $lavToastReminder = collect(\App\Support\TeacherReminders::forTeacher(Auth::user()))
                    ->where('actionable', true)->first();
            }
            // Workstream CZ (LMS): per-user notifications (assignment
            // graded / internal marks pushed) — the store the
            // student + parent bell lists. Writers only ever create
            // rows for students and their parents, so for every other
            // role these stay empty and the badge is unchanged.
            $lavNotifications = collect();
            $lavUnreadNotifs = 0;
            if (\Illuminate\Support\Facades\Schema::hasTable('user_notifications')) {
                $lavUnreadNotifs = \App\Support\Notify::unreadCount(Auth::id());
                $lavNotifications = \App\Support\Notify::latestFor(Auth::id(), 5);
                $lavBellBadge += $lavUnreadNotifs;
            }
            // Workstream CW (feedback flow): same computed-count style —
            // the school Admin's badge rises with NEW feedback waiting
            // for triage, the Super Admin's with forwarded reports not
            // yet picked up, and every reporter sees their own reports
            // that changed since they last opened My Feedback.
            if (\Illuminate\Support\Facades\Schema::hasTable('feedback_items')) {
                if (Qs::userIsAdmin()) {
                    $lavBellBadge += \App\Models\FeedbackItem::where('status', 'new')->count();
                }
                if (Qs::userIsSuperAdmin()) {
                    $lavBellBadge += \App\Models\FeedbackItem::forwarded()->where('sa_status', 'received')->count();
                }
                $lavBellBadge += \App\Models\FeedbackItem::unseenUpdatesCountFor(Auth::id());
            }
            $lavIsHome = request()->routeIs('home') || request()->routeIs('dashboard')
                || request()->routeIs('dashboard.school') || request()->path() === 'home';
        @endphp
        @if(Qs::userIsSuperAdmin())
            {{-- Platform-only model (Workstream DD): the bell is a
                 school-activity feed (recent payments, new students,
                 events, circulars). The Super Admin gets no bell —
                 platform activity lives on the Platform Overview. --}}
        @elseif(Qs::userIsTeamSAT() || Qs::userIsTeamAccount())
            @php
                $lavRecentPayments = \App\Models\PaymentRecord::with('payment')->latest()->take(4)->get();
                $lavRecentStudents = \App\Models\StudentRecord::with('user')->latest()->take(4)->get();
            @endphp
            <div class="nav-item dropdown">
                <button type="button" class="lav-topbar-icon" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Recent activity" title="Recent activity"><i class="icon-bell2"></i>@if($lavBellBadge > 0)<span class="lav-bell-badge">{{ $lavBellBadge }}</span>@endif</button>
                <div class="dropdown-menu dropdown-menu-right lav-notif-menu">
                    {{-- LOOP31-FIX10-REMINDERS-LIST (start): the teacher's
                         derived reminders, rendered distinctly with a
                         Reminder tag, above the recent-activity feed.
                         (Re-applied onto the Loop 33 topbar at integration.) --}}
                    @if(Qs::userIsTeacher())
                        @php
                            $lavReminders = \App\Support\TeacherReminders::forTeacher(Auth::user());
                        @endphp
                        @if(count($lavReminders))
                            <div class="dropdown-header">Reminders</div>
                            @foreach(array_slice($lavReminders, 0, 5) as $lavReminder)
                                <a class="dropdown-item lav-notif-item" href="{{ $lavReminder['link'] }}">
                                    <i class="{{ $lavReminder['icon'] }}"></i>
                                    <span>{{ $lavReminder['title'] }}<small>{{ $lavReminder['meta'] }} &middot; {{ $lavReminder['date_label'] }}</small></span>
                                    <span class="lav-reminder-tag">{{ $lavReminder['tag'] }}</span>
                                </a>
                            @endforeach
                            <div class="dropdown-divider"></div>
                        @endif
                    @endif
                    {{-- LOOP31-FIX10-REMINDERS-LIST (end) --}}
                    {{-- Loop 56: Upcoming Events — real SchoolEvent
                         records, soonest first, same source as the
                         dashboards/School Calendar. --}}
                    <div class="dropdown-header">Upcoming Events</div>
                    @forelse($lavUpcomingEvents as $lavEvent)
                        <a class="dropdown-item lav-notif-item" href="{{ $lavEventsUrl }}">
                            <i class="icon-calendar3"></i>
                            <span>{{ $lavEvent->title }}<small>{{ $lavEvent->starts_at->format('D, d M Y') }}@if($lavEvent->all_day) &middot; All Day @endif</small></span>
                            @if($lavEvent->starts_at->isToday())<span class="lav-event-tag">Today</span>@else<span class="lav-event-tag">{{ \App\Models\SchoolEvent::types()[$lavEvent->type] ?? 'Event' }}</span>@endif
                        </a>
                    @empty
                        <span class="dropdown-item text-muted">No upcoming events.</span>
                    @endforelse
                    <a class="dropdown-item" href="{{ $lavEventsUrl }}">{{ $lavEventsLinkLabel }}</a>
                    <div class="dropdown-divider"></div>
                    <div class="dropdown-header">Recent activity</div>
                    @forelse($lavRecentPayments as $lavPay)
                        <a class="dropdown-item lav-notif-item" href="{{ route('payments.receipts', Qs::hash($lavPay->id)) }}">
                            <i class="icon-coins"></i>
                            <span>{{ optional($lavPay->payment)->title ?: 'Fee payment' }} &mdash; {{ \App\Helpers\Money::inr($lavPay->amt_paid) }}<small>{{ $lavPay->created_at->diffForHumans() }}</small></span>
                        </a>
                    @empty
                    @endforelse
                    {{-- Roles audit 2026-10-28: students.show denies
                         accountants, so the admitted-student rows are
                         SAT-only — otherwise an accountant's bell
                         carried links to a denial page. Receipt rows
                         above stay for every branch viewer. --}}
                    @if(Qs::userIsTeamSAT())
                    @forelse($lavRecentStudents as $lavStudent)
                        <a class="dropdown-item lav-notif-item" href="{{ route('students.show', Qs::hash($lavStudent->id)) }}">
                            <i class="icon-user-plus"></i>
                            <span>{{ optional($lavStudent->user)->name ?: 'New student' }} admitted<small>{{ $lavStudent->created_at->diffForHumans() }}</small></span>
                        </a>
                    @empty
                    @endforelse
                    @endif
                    @if($lavRecentPayments->isEmpty() && (!Qs::userIsTeamSAT() || $lavRecentStudents->isEmpty()))
                        <span class="dropdown-item text-muted">No recent activity yet.</span>
                    @endif
                </div>
            </div>
        @else
            @php
                $lavCirculars = \App\Models\Circular::published()->forAudience(Auth::user()->user_type)->latest()->take(4)->get();
            @endphp
            <div class="nav-item dropdown" id="lavNotifBell">
                <button type="button" class="lav-topbar-icon" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Circulars" title="Circulars" data-lav-events-today="{{ $lavEventsToday }}"><i class="icon-bell2"></i>@if($lavBellBadge > 0)<span class="lav-bell-badge">{{ $lavBellBadge }}</span>@endif</button>
                <div class="dropdown-menu dropdown-menu-right lav-notif-menu">
                    {{-- Workstream CZ (LMS): the caller's own
                         notifications (grades + internal pushes for
                         students/parents). Opening this dropdown marks
                         them read (script below the menu). --}}
                    @if($lavNotifications->isNotEmpty())
                        <div class="dropdown-header">Notifications</div>
                        @foreach($lavNotifications as $lavNotif)
                            <a class="dropdown-item lav-notif-item" href="{{ $lavNotif->link ?: '#' }}">
                                <i class="icon-file-text2"></i>
                                <span>@if($lavNotif->isUnread())<strong>{{ $lavNotif->title }}</strong>@else{{ $lavNotif->title }}@endif<small>{{ $lavNotif->created_at->diffForHumans() }}@if($lavNotif->body) &middot; {{ \Illuminate\Support\Str::limit($lavNotif->body, 60) }}@endif</small></span>
                                @if($lavNotif->isUnread())<span class="lav-event-tag">New</span>@endif
                            </a>
                        @endforeach
                        <div class="dropdown-divider"></div>
                    @endif
                    <div class="dropdown-header">Latest circulars</div>
                    @forelse($lavCirculars as $lavCircular)
                        <a class="dropdown-item lav-notif-item" href="{{ route('circulars.show', $lavCircular->id) }}">
                            <i class="icon-megaphone"></i>
                            <span>{{ $lavCircular->title }}<small>{{ $lavCircular->created_at->diffForHumans() }}</small></span>
                        </a>
                    @empty
                        <span class="dropdown-item text-muted">No circulars yet.</span>
                    @endforelse
                    <div class="dropdown-divider"></div>
                    {{-- Loop 56: Upcoming Events — same real records
                         and link rule as the staff bell. --}}
                    <div class="dropdown-header">Upcoming Events</div>
                    @forelse($lavUpcomingEvents as $lavEvent)
                        <a class="dropdown-item lav-notif-item" href="{{ $lavEventsUrl }}">
                            <i class="icon-calendar3"></i>
                            <span>{{ $lavEvent->title }}<small>{{ $lavEvent->starts_at->format('D, d M Y') }}@if($lavEvent->all_day) &middot; All Day @endif</small></span>
                            @if($lavEvent->starts_at->isToday())<span class="lav-event-tag">Today</span>@else<span class="lav-event-tag">{{ \App\Models\SchoolEvent::types()[$lavEvent->type] ?? 'Event' }}</span>@endif
                        </a>
                    @empty
                        <span class="dropdown-item text-muted">No upcoming events.</span>
                    @endforelse
                    <a class="dropdown-item" href="{{ $lavEventsUrl }}">{{ $lavEventsLinkLabel }}</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="{{ route('circulars.index') }}">View all circulars</a>
                </div>
            </div>
            @if($lavUnreadNotifs > 0)
            <script>
                // Workstream CZ: opening the bell marks the caller's
                // notifications read; the badge then falls back to
                // today's-events only (its other component).
                (function () {
                    var wrap = document.getElementById('lavNotifBell');
                    if (!wrap || !window.jQuery) { return; }
                    window.jQuery(wrap).on('show.bs.dropdown', function () {
                        var meta = document.querySelector('meta[name="csrf-token"]');
                        fetch('{{ route('notifications.read') }}', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '' }
                        }).then(function () {
                            var btn = wrap.querySelector('[data-lav-events-today]');
                            var badge = wrap.querySelector('.lav-bell-badge');
                            if (!badge) { return; }
                            var left = btn ? parseInt(btn.getAttribute('data-lav-events-today') || '0', 10) : 0;
                            if (left > 0) { badge.textContent = left; } else { badge.remove(); }
                        }).catch(function () {});
                    });
                })();
            </script>
            @endif
        @endif
        <div class="nav-item dropdown dropdown-user lav-profile">
            <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
                <span class="l7-avatar l7-avatar-sm" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}@if(Qs::hasRealPhoto(Auth::user()->photo))<img src="{{ Auth::user()->photo }}" alt="" onerror="this.remove()">@endif</span>
                <span class="lav-user-meta"><strong>{{ Auth::user()->name }}</strong><small>{{ ucwords(str_replace('_', ' ', Auth::user()->user_type)) }}</small></span>
            </a>
            <div class="dropdown-menu dropdown-menu-right lav-user-menu">
                {{-- Loop 40: profile header block — same identity data
                     as the chip (name + role), presentation only. --}}
                <div class="lav-user-menu-head">
                    <span class="l7-avatar l7-avatar-md" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}@if(Qs::hasRealPhoto(Auth::user()->photo))<img src="{{ Auth::user()->photo }}" alt="" onerror="this.remove()">@endif</span>
                    <span class="lav-user-menu-meta"><strong>{{ Auth::user()->name }}</strong><small>{{ ucwords(str_replace('_', ' ', Auth::user()->user_type)) }}</small></span>
                </div>
                <div class="dropdown-divider"></div>
                <a href="{{ route('my_account') }}" class="dropdown-item"><i class="icon-cog5"></i> My Account</a>
                <div class="dropdown-divider"></div>
                <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="dropdown-item lav-logout"><i class="icon-switch2"></i> Logout</a>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none">@csrf</form>
            </div>
        </div>
    </div>
</div>
@if($lavIsHome && ($lavTodayEvents->count() || $lavToastReminder))
    {{-- Loop 56 — home popup toast(s) (owner: "for upcoming event or
         any notification show popup here"): an event happening TODAY,
         or the teacher's first actionable reminder. Each toast shows
         at most once per browser session per item (sessionStorage),
         is dismissible, and never blocks the page. Styled by Loop 56
         CSS, including under html.hk-dark. --}}
    <div class="lav-toast-stack" id="lav-toast-stack">
        @foreach($lavTodayEvents as $lavTodayEvent)
            <div class="lav-toast" data-toast-key="event-{{ $lavTodayEvent->id }}" role="status" hidden>
                <i class="icon-calendar3"></i>
                <span class="lav-toast-body"><strong>{{ $lavTodayEvent->title }}</strong><small>Happening today &middot; {{ $lavTodayEvent->starts_at->format('D, d M Y') }}</small></span>
                <a class="lav-toast-link" href="{{ $lavEventsUrl }}">View</a>
                <button type="button" class="lav-toast-close" aria-label="Dismiss notification">&times;</button>
            </div>
        @endforeach
        @if($lavToastReminder)
            <div class="lav-toast" data-toast-key="reminder-{{ md5($lavToastReminder['title'] . $lavToastReminder['date_label']) }}" role="status" hidden>
                <i class="{{ $lavToastReminder['icon'] }}"></i>
                <span class="lav-toast-body"><strong>{{ $lavToastReminder['title'] }}</strong><small>{{ $lavToastReminder['meta'] }} &middot; {{ $lavToastReminder['date_label'] }}</small></span>
                <a class="lav-toast-link" href="{{ $lavToastReminder['link'] }}">Open</a>
                <button type="button" class="lav-toast-close" aria-label="Dismiss notification">&times;</button>
            </div>
        @endif
    </div>
    <script>
        (function () {
            var stack = document.getElementById('lav-toast-stack');
            if (!stack) { return; }
            Array.prototype.forEach.call(stack.querySelectorAll('.lav-toast'), function (toast) {
                var key = 'hk-toast-' + toast.getAttribute('data-toast-key');
                var seen = false;
                try { seen = sessionStorage.getItem(key) === '1'; } catch (e) {}
                if (seen) { toast.remove(); return; }
                toast.hidden = false;
                try { sessionStorage.setItem(key, '1'); } catch (e) {}
                toast.querySelector('.lav-toast-close').addEventListener('click', function () { toast.remove(); });
            });
            if (!stack.children.length) { stack.remove(); }
        })();
    </script>
@endif
