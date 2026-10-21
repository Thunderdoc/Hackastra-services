<div id="page-header" class="page-header page-header-light">
    <div class="page-header-content header-elements-md-inline">
        <div class="page-title d-flex">
            <h4><span class="font-weight-semibold">@yield('page_title')</span></h4>
            <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
        </div>

        <div class="header-elements">
            @if(Qs::userIsSuperAdmin())
                <a href="{{ route('settings') }}" class="session-chip" title="Open settings to change the current session"><i class="icon-calendar5"></i><span>Current Session: <strong>{{ Qs::getSetting('current_session') }}</strong></span></a>
            @else
                <span class="session-chip"><i class="icon-calendar5"></i><span>Current Session: <strong>{{ Qs::getSetting('current_session') }}</strong></span></span>
            @endif
        </div>
    </div>
</div>
