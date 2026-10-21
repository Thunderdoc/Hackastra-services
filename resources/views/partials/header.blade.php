@if(Qs::userIsTeacher() && in_array(request()->path(), ['/', 'home', 'dashboard'], true))
<div class="t9-subheader">
    <div class="t9-subheader-left">
        <a href="{{ route('dashboard') }}" class="t9-back" aria-label="Back to dashboard"><i class="icon-arrow-left8"></i></a>
        <span class="t9-crumb">Dashboard</span>
    </div>
    <div class="t9-subheader-right">
        <div class="t9-datebox">
            <i class="icon-calendar3"></i>
            <span>
                <strong>{{ $today->format('l, d F Y') }}</strong>
                <small>Current Session: {{ $current_session ?: '—' }}</small>
            </span>
        </div>
        <a href="{{ route('attendance.periods.index') }}" class="btn t9-take-btn">
            <i class="icon-calendar52"></i> Take Attendance
        </a>
    </div>
</div>
@else<div id="page-header" class="page-header page-header-light">
    <div class="page-header-content header-elements-md-inline">
        <div class="page-title d-flex">
            <h4><span class="font-weight-semibold">@yield('page_title')</span></h4>
            <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
        </div>

        <div class="header-elements">
            @if(Qs::userIsTeamSA())
                <a href="{{ route('settings') }}" class="session-chip" title="Open settings to change the current session"><i class="icon-calendar5"></i><span>Current Session: <strong>{{ Qs::getSetting('current_session') }}</strong></span></a>
            @else
                <span class="session-chip"><i class="icon-calendar5"></i><span>Current Session: <strong>{{ Qs::getSetting('current_session') }}</strong></span></span>
            @endif
        </div>
    </div>
</div>
@endif