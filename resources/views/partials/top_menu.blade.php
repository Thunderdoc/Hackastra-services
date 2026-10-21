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
        <input type="search" name="q" id="lav-global-search" value="{{ request('q') }}" placeholder="Search students, teachers, classes, payments, exams..." aria-label="Search students, teachers, classes, payments, exams">
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
    <div class="ml-auto d-flex align-items-center">
        {{-- No notifications backend exists yet (audit §9): no fake count badge. --}}
        <button type="button" class="lav-topbar-icon" aria-label="Notifications" title="Notifications are not configured yet"><i class="icon-bell2"></i></button>
        <button type="button" class="lav-topbar-icon lav-theme-toggle" aria-label="Toggle theme"><i class="icon-sun3"></i></button>
        <div class="nav-item dropdown dropdown-user lav-profile">
            <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
                <img src="{{ Auth::user()->photo }}" class="rounded-circle" alt="">
                <span><strong>{{ Auth::user()->name }}</strong><small>{{ ucwords(str_replace('_', ' ', Auth::user()->user_type)) }}</small></span>
            </a>
            <div class="dropdown-menu dropdown-menu-right">
                <a href="{{ Qs::userIsStudent() ? route('students.show', Qs::hash(Qs::findStudentRecord(Auth::user()->id)->id)) : route('users.show', Qs::hash(Auth::user()->id)) }}" class="dropdown-item"><i class="icon-user-plus"></i> My profile</a>
                <a href="{{ route('my_account') }}" class="dropdown-item"><i class="icon-cog5"></i> Account settings</a>
                <div class="dropdown-divider"></div>
                <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="dropdown-item"><i class="icon-switch2"></i> Logout</a>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none">@csrf</form>
            </div>
        </div>
    </div>
</div>
