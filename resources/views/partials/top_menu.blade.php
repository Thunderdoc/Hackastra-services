<div class="navbar navbar-expand-md lav-topbar">
    <div class="lav-topbar-mobile">
        <button type="button" class="navbar-toggler sidebar-mobile-main-toggle" aria-label="Open navigation">
            <i class="icon-paragraph-justify3"></i>
        </button>
    </div>
    <a href="{{ route('dashboard') }}" class="lav-topbar-toggle sidebar-control sidebar-main-toggle" aria-label="Toggle navigation">
        <i class="icon-paragraph-justify3"></i>
    </a>
    <div class="lav-search" role="search">
        <i class="icon-search4"></i>
        <input type="search" placeholder="Search students, teachers, classes, payments..." aria-label="Search">
        <kbd>Ctrl K</kbd>
    </div>
    <div class="ml-auto d-flex align-items-center">
        <button type="button" class="lav-topbar-icon" aria-label="Notifications"><i class="icon-bell2"></i><span>3</span></button>
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
