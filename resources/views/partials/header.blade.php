@if(Qs::userIsTeacher() && in_array(request()->path(), ['/', 'home', 'dashboard'], true))
<div class="t9-subheader">
    <div class="t9-subheader-left">
        <span class="t9-crumb">Dashboard</span>
    </div>
    <div class="t9-subheader-right">
        <a href="{{ route('attendance.periods.index') }}" class="btn t9-take-btn">
            <i class="icon-calendar52"></i> Take Attendance
        </a>
    </div>
</div>
@elseif(View::hasSection('hide_page_header'))
    {{-- Loop 31 (Fix 5): this page renders its own designed header
         (breadcrumb + title + subtitle), so the generic layout title
         bar is suppressed to avoid printing the title twice. The page
         opts in with @section('hide_page_header', true). --}}
@else<div id="page-header" class="page-header page-header-light">
    {{-- Loop 46 (Workstream BS): THE common header — the one place a
         page's title is rendered. A page supplies only its
         @section('page_title'); it may optionally add a
         @section('page_breadcrumb') (rendered once, above the title)
         and/or @section('page_actions') (rendered at the right of the
         header). In-page card headings must never repeat the title. --}}
    <div class="page-header-content header-elements-md-inline">
        <div class="page-title d-flex">
            {{-- Loop 118: optional icon tile beside the title — a page
                 opts in with @section('page_icon') holding the tile
                 markup (the reference designs carry one on some
                 pages). Pages without the section render exactly as
                 before. --}}
            @if(View::hasSection('page_icon'))
                @yield('page_icon')
            @endif
            <div>
                @if(View::hasSection('page_breadcrumb'))
                    <div class="page-header-crumb">@yield('page_breadcrumb')</div>
                @endif
                <h4><span class="font-weight-semibold">@yield('page_title')</span></h4>
                @if(View::hasSection('page_subtitle'))
                    <div class="page-header-sub">@yield('page_subtitle')</div>
                @endif
            </div>
        </div>
        @if(View::hasSection('page_actions'))
            <div class="header-elements d-none d-md-flex align-items-center">@yield('page_actions')</div>
        @endif
        {{-- Loop 33: the Current Session chip moved to the common topbar
             (partials/top_menu) so it renders exactly once per page. --}}
    </div>
</div>
@endif
