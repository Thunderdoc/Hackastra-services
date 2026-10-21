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
        <input type="search" name="q" id="lav-global-search" value="{{ request('q') }}" placeholder="Search students, teachers, classes, payments..." aria-label="Search students, teachers, classes, payments, exams">
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
        @if(Qs::userIsTeamSA())
            <a href="{{ route('settings') }}" class="session-chip lav-session-chip" title="Open settings to change the current session"><i class="icon-calendar5"></i><span class="lav-session-label">Current Session:&nbsp;</span><strong>{{ Qs::getSetting('current_session') }}</strong></a>
        @else
            <span class="session-chip lav-session-chip"><i class="icon-calendar5"></i><span class="lav-session-label">Current Session:&nbsp;</span><strong>{{ Qs::getSetting('current_session') }}</strong></span>
        @endif
        @if(Qs::userIsTeamSAT() || Qs::userIsTeamAccount())
            @php
                $lavRecentPayments = \App\Models\PaymentRecord::with('payment')->latest()->take(4)->get();
                $lavRecentStudents = \App\Models\StudentRecord::with('user')->latest()->take(4)->get();
            @endphp
            <div class="nav-item dropdown">
                <button type="button" class="lav-topbar-icon" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Recent activity" title="Recent activity"><i class="icon-bell2"></i></button>
                <div class="dropdown-menu dropdown-menu-right lav-notif-menu">
                    <div class="dropdown-header">Recent activity</div>
                    @forelse($lavRecentPayments as $lavPay)
                        <a class="dropdown-item lav-notif-item" href="{{ route('payments.receipts', Qs::hash($lavPay->id)) }}">
                            <i class="icon-coins"></i>
                            <span>{{ optional($lavPay->payment)->title ?: 'Fee payment' }} &mdash; {{ \App\Helpers\Money::inr($lavPay->amt_paid) }}<small>{{ $lavPay->created_at->diffForHumans() }}</small></span>
                        </a>
                    @empty
                    @endforelse
                    @forelse($lavRecentStudents as $lavStudent)
                        <a class="dropdown-item lav-notif-item" href="{{ route('students.show', Qs::hash($lavStudent->id)) }}">
                            <i class="icon-user-plus"></i>
                            <span>{{ optional($lavStudent->user)->name ?: 'New student' }} admitted<small>{{ $lavStudent->created_at->diffForHumans() }}</small></span>
                        </a>
                    @empty
                    @endforelse
                    @if($lavRecentPayments->isEmpty() && $lavRecentStudents->isEmpty())
                        <span class="dropdown-item text-muted">No recent activity yet.</span>
                    @endif
                </div>
            </div>
        @else
            @php
                $lavCirculars = \App\Models\Circular::where('is_published', true)->latest()->take(4)->get();
            @endphp
            <div class="nav-item dropdown">
                <button type="button" class="lav-topbar-icon" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Circulars" title="Circulars"><i class="icon-bell2"></i></button>
                <div class="dropdown-menu dropdown-menu-right lav-notif-menu">
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
                    <a class="dropdown-item" href="{{ route('circulars.index') }}">View all circulars</a>
                </div>
            </div>
        @endif
        <div class="nav-item dropdown dropdown-user lav-profile">
            <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
                <span class="l7-avatar l7-avatar-sm" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}<img src="{{ Auth::user()->photo }}" alt="" onerror="this.remove()"></span>
                <span class="lav-user-meta"><strong>{{ Auth::user()->name }}</strong><small>{{ ucwords(str_replace('_', ' ', Auth::user()->user_type)) }}</small></span>
            </a>
            <div class="dropdown-menu dropdown-menu-right">
                <a href="{{ route('my_account') }}" class="dropdown-item"><i class="icon-cog5"></i> My Account</a>
                <div class="dropdown-divider"></div>
                <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="dropdown-item"><i class="icon-switch2"></i> Logout</a>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none">@csrf</form>
            </div>
        </div>
    </div>
</div>
