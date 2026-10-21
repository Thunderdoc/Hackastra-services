@if(Qs::userIsTeacher() && in_array(request()->path(), ['/', 'home', 'dashboard'], true))
<div class="t9-subheader">
    <div class="t9-subheader-left">
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
@elseif(View::hasSection('hide_page_header'))
    {{-- Loop 31 (Fix 5): this page renders its own designed header
         (breadcrumb + title + subtitle), so the generic layout title
         bar is suppressed to avoid printing the title twice. The page
         opts in with @section('hide_page_header', true). --}}
@else<div id="page-header" class="page-header page-header-light">
    <div class="page-header-content header-elements-md-inline">
        <div class="page-title d-flex">
            <h4><span class="font-weight-semibold">@yield('page_title')</span></h4>
        </div>
        {{-- Loop 33: the Current Session chip moved to the common topbar
             (partials/top_menu) so it renders exactly once per page. --}}
    </div>
</div>
@endif