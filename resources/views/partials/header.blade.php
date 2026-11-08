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
         opts in with the hide_page_header section. --}}
@else
{{-- Header Design B — Title Strip (owner pick, 2026-11-08): the
     topbar stays a plain utility bar; the page's identity lives in
     ONE slim site-card strip under it — pastel icon circle, page
     title, subtitle, the page's own actions, and a BREADCRUMB CHAIN
     whose every ancestor level is a link (one tap jumps straight to
     that level). The single Back control goes exactly ONE level up:
     to the last link of the page's own chain (its parent page), or
     to the dashboard when the page has no parent link. Pages that
     render their OWN Back keep it and suppress this one through the
     own_page_back opt-out, so a page never carries two Back controls.
     A page declares its chain through the page_breadcrumb section:
     Dashboard link, optional section label, parent-page link, then
     the current page name as plain text. The icon circle is chosen
     from the route-name map below; a page may override it with the
     page_icon section (legacy tile markup, rendered as-is). --}}
@php
    $hkStripHome = in_array(request()->path(), ['/', 'home', 'dashboard'], true);
    $hkStripOwnBack = View::hasSection('own_page_back');
    $hkCrumbRaw = View::hasSection('page_breadcrumb') ? $__env->yieldContent('page_breadcrumb') : '';
    $hkCrumbRaw = is_string($hkCrumbRaw) ? trim($hkCrumbRaw) : '';
    $hkCrumbLinks = [];
    if ($hkCrumbRaw !== '' && preg_match_all('/<a\s[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $hkCrumbRaw, $hkCrumbM, PREG_SET_ORDER)) {
        foreach ($hkCrumbM as $hkCrumbA) {
            $hkCrumbLinks[] = ['href' => html_entity_decode($hkCrumbA[1]), 'label' => trim(strip_tags($hkCrumbA[2]))];
        }
    }
    $hkBackHref = route('dashboard');
    $hkBackLabel = 'Dashboard';
    foreach (array_reverse($hkCrumbLinks) as $hkCrumbL) {
        if ($hkCrumbL['href'] !== url()->current()) {
            $hkBackHref = $hkCrumbL['href'];
            $hkBackLabel = $hkCrumbL['label'] !== '' ? $hkCrumbL['label'] : 'Previous page';
            break;
        }
    }
    $hkCrumbHtml = $hkCrumbRaw === '' ? '' : preg_replace('/\s*&nbsp;\s*\/\s*&nbsp;\s*/', ' <span class="hk-crumb-sep" aria-hidden="true">›</span> ', $hkCrumbRaw);
    $hkStripSeg = explode('.', (string) Route::currentRouteName())[0];
    $hkStripIcons = [
        'dashboard' => ['icon-home4', 'blue'], 'home' => ['icon-home4', 'blue'],
        'students' => ['icon-users4', 'violet'], 'my_children' => ['icon-people', 'violet'],
        'classes' => ['icon-office', 'teal'], 'sections' => ['icon-tree7', 'teal'],
        'subjects' => ['icon-book', 'amber'], 'exams' => ['icon-file-text2', 'rose'],
        'exam_dates' => ['icon-calendar52', 'rose'], 'marks' => ['icon-clipboard3', 'green'],
        'tt' => ['icon-calendar3', 'blue'], 'ttr' => ['icon-calendar3', 'blue'],
        'users' => ['icon-user-tie', 'violet'], 'payments' => ['icon-coin-dollar', 'amber'],
        'my_fees' => ['icon-wallet', 'amber'], 'payroll' => ['icon-cash2', 'amber'],
        'my_payslips' => ['icon-cash2', 'amber'], 'leave' => ['icon-calendar2', 'rose'],
        'substitutions' => ['icon-user-check', 'rose'], 'books' => ['icon-books', 'teal'],
        'book_requests' => ['icon-book2', 'teal'], 'library_fines' => ['icon-library2', 'teal'],
        'library_reservations' => ['icon-library2', 'teal'], 'transport' => ['icon-bus', 'amber'],
        'dorms' => ['icon-fence', 'violet'], 'hostel' => ['icon-fence', 'violet'],
        'inventory' => ['icon-box', 'slate'], 'expenses' => ['icon-coins', 'amber'],
        'events' => ['icon-calendar5', 'green'], 'circulars' => ['icon-megaphone', 'blue'],
        'certificates' => ['icon-certificate', 'amber'], 'alumni' => ['icon-graduation2', 'violet'],
        'admissions' => ['icon-user-plus', 'green'], 'analytics' => ['icon-pie-chart', 'violet'],
        'backup' => ['icon-database', 'slate'], 'bulk' => ['icon-upload', 'slate'],
        'discipline_records' => ['icon-flag3', 'rose'], 'documents' => ['icon-folder-open', 'slate'],
        'electives' => ['icon-checkmark-circle2', 'teal'], 'feedback' => ['icon-bubbles4', 'blue'],
        'grades' => ['icon-stairs-up', 'green'], 'health_records' => ['icon-heart6', 'rose'],
        'parent_messages' => ['icon-bubbles2', 'blue'], 'planning' => ['icon-clipboard', 'violet'],
        'ptm' => ['icon-calendar', 'green'], 'role_permissions' => ['icon-key', 'slate'],
        'rooms' => ['icon-grid6', 'teal'], 'settings_unlock' => ['icon-unlocked2', 'slate'],
        'settings' => ['icon-cog3', 'slate'], 'courses' => ['icon-graduation2', 'blue'],
        'assignments' => ['icon-pencil7', 'blue'], 'attendance' => ['icon-watch', 'green'],
        'staff_attendance' => ['icon-watch', 'green'], 'quizzes' => ['icon-question6', 'violet'],
        'materials' => ['icon-bookmark', 'teal'], 'question_bank' => ['icon-stack', 'violet'],
        'question_papers' => ['icon-file-pdf', 'rose'], 'my_account' => ['icon-user', 'blue'],
        'my_progress' => ['icon-stats-growth', 'green'], 'platform_mail' => ['icon-mail5', 'blue'],
        'audit_logs' => ['icon-history', 'slate'], 'licences' => ['icon-vcard', 'amber'],
        'licence' => ['icon-vcard', 'amber'], 'referrals' => ['icon-share2', 'green'],
        'ask' => ['icon-lifebuoy', 'blue'], 'search' => ['icon-search4', 'slate'],
        'class_overview' => ['icon-eye', 'teal'], 'homework_tracker' => ['icon-checkmark3', 'green'],
        'notifications' => ['icon-bell2', 'amber'],
    ];
    $hkStripIcon = $hkStripIcons[$hkStripSeg] ?? ['icon-stack', 'slate'];
@endphp
<div class="hk-strip-wrap">
    <div class="hk-strip">
        @if(!$hkStripOwnBack && !$hkStripHome)
        <a href="{{ $hkBackHref }}" class="btn btn-light btn-sm hk-global-back hk-strip-back" aria-label="Back to {{ $hkBackLabel }}"><i class="icon-arrow-left7"></i><span class="d-none d-sm-inline"> Back</span></a>
        @endif
        @if(View::hasSection('page_icon'))
            @yield('page_icon')
        @else
            <span class="hk-strip-ic hk-tint-{{ $hkStripIcon[1] }}" aria-hidden="true"><i class="{{ $hkStripIcon[0] }}"></i></span>
        @endif
        <div class="hk-strip-main">
            @if($hkCrumbHtml !== '')
                <nav class="hk-strip-crumbs" aria-label="Breadcrumb">{!! $hkCrumbHtml !!}</nav>
            @endif
            <h4 class="hk-strip-title"><span class="font-weight-semibold">@yield('page_title')</span></h4>
            @if(View::hasSection('page_subtitle'))
                <div class="hk-strip-sub">@yield('page_subtitle')</div>
            @endif
        </div>
        @if(View::hasSection('page_actions'))
            <div class="header-elements d-none d-md-flex align-items-center hk-strip-actions">@yield('page_actions')</div>
        @endif
    </div>
</div>
@endif
