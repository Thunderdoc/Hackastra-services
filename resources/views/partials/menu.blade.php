<div class="sidebar sidebar-dark sidebar-main sidebar-expand-md">

@if(Qs::userIsTeacher())
    <div class="t9-brand">
        <a href="{{ route('dashboard') }}" class="t9-brand-link">
            <img src="{{ asset('global_assets/images/logo_icon_light.png') }}" alt="" class="t9-brand-icon">
            <span class="t9-brand-copy"><strong>Hackstra</strong><small>School Management System</small></span>
        </a>
    </div>
    <div class="t9-academy">
        <i class="icon-library2"></i>
        <span>{{ strtoupper(Qs::getSystemName()) }}</span>
    </div>@else    <div class="lav-sidebar-brand">
        <a href="{{ route('dashboard') }}" class="lav-sidebar-brand-link">
            <img src="{{ Qs::getLogo(asset('global_assets/images/logo_icon_light.png')) }}" alt="" class="lav-sidebar-brand-icon">
            <span>
                <strong>{{ Qs::getSystemName() }}</strong>
                <small>School Management System</small>
            </span>
        </a>
    </div>@endif



    <!-- Sidebar content -->
    <div class="sidebar-content">

@if(!Qs::userIsTeacher())        <!-- User menu -->
        <div class="sidebar-user">
            <div class="card-body">
                <div class="media">
                    <div class="mr-3">
                        <a href="{{ route('my_account') }}"><span class="l7-avatar l7-avatar-md" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}@if(Qs::hasRealPhoto(Auth::user()->photo))<img src="{{ Auth::user()->photo }}" alt="" onerror="this.remove()">@endif</span></a>
                    </div>

                    <div class="media-body">
                        <div class="media-title font-weight-semibold">{{ Auth::user()->name }}</div>
                        <div class="font-size-xs opacity-50">
                            <i class="icon-user font-size-sm"></i> &nbsp;{{ ucwords(str_replace('_', ' ', Auth::user()->user_type)) }}
                        </div>
                    </div>

                    <div class="ml-3 align-self-center">
                        <a href="{{ route('my_account') }}" class="text-white"><i class="icon-cog3"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <!-- /user menu -->@endif


        <!-- Main navigation -->
        <div class="card card-sidebar-mobile">
            <ul class="nav nav-sidebar" data-nav-type="accordion">@if(Qs::userIsTeacher())
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ (Route::is('dashboard')) ? 'active' : '' }}">
                        <i class="icon-home4"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Attendance</div><i class="icon-menu" title="Attendance"></i></li>
                {{-- Workstream AR: the day-wise register is retired — the
                     one Attendance entry point is the period register. --}}
                <li class="nav-item">
                    <a href="{{ route('attendance.periods.index') }}" class="nav-link {{ (Route::is('attendance.periods.*')) ? 'active' : '' }}">
                        <i class="icon-watch"></i>
                        <span>Mark Attendance</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('attendance.report') }}" class="nav-link {{ (Route::is('attendance.report')) ? 'active' : '' }}">
                        <i class="icon-stats-bars2"></i>
                        <span>Attendance Report</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('attendance.defaulters') }}" class="nav-link {{ (Route::is('attendance.defaulters')) ? 'active' : '' }}">
                        <i class="icon-alarm"></i>
                        <span>Low Attendance (Below 75%)</span>
                    </a>
                </li>
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Communication</div><i class="icon-menu" title="Communication"></i></li>
                <li class="nav-item">
                    <a href="{{ route('circulars.index') }}" class="nav-link {{ (Route::is('circulars.*')) ? 'active' : '' }}">
                        <i class="icon-megaphone"></i>
                        <span>Circulars</span>
                    </a>
                </li>
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Academics</div><i class="icon-menu" title="Academics"></i></li>
                <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['tt.index', 'tt.mine', 'ttr.edit', 'ttr.show', 'ttr.manage']) ? 'nav-item-expanded nav-item-open' : '' }} ">
                    <a href="#" class="nav-link"><i class="icon-graduation2"></i> <span> Academics</span></a>
                    <ul class="nav nav-group-sub" data-submenu-title="Manage Academics">
                        <li class="nav-item"><a href="{{ route('tt.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['tt.index']) ? 'active' : '' }}">Timetables</a></li>
                        <li class="nav-item"><a href="{{ route('tt.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['tt.mine']) ? 'active' : '' }}">My Timetable</a></li>
                    </ul>
                </li>
                {{-- Workstream CS2 (LMS, owner-ordered module): new items
                     only — nothing existing moved, renamed or removed. --}}
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Learning</div><i class="icon-menu" title="Learning"></i></li>
                <li class="nav-item">
                    <a href="{{ route('assignments.index') }}" class="nav-link {{ (Route::is('assignments.*')) ? 'active' : '' }}">
                        <i class="icon-file-text2"></i>
                        <span>Assignments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('materials.index') }}" class="nav-link {{ (Route::is('materials.*')) ? 'active' : '' }}">
                        <i class="icon-book"></i>
                        <span>Study Materials</span>
                    </a>
                </li>
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Students</div><i class="icon-menu" title="Students"></i></li>
                <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['students.create', 'students.list', 'students.edit', 'students.show', 'students.promotion', 'students.promotion_manage', 'students.graduated']) ? 'nav-item-expanded nav-item-open' : '' }} ">
                    <a href="#" class="nav-link"><i class="icon-users"></i> <span> Students</span></a>
                    <ul class="nav nav-group-sub" data-submenu-title="Manage Students">
                        {{-- Owner ruling 2026-10-22: Student Information is
                             ONE link — it lands on the class/section
                             picker (students.list with no class) instead
                             of expanding into one item per class. A
                             teacher's picker lists only their assigned
                             classes (App\Support\TeacherScope), exactly
                             as this per-class submenu used to (Batch 1B). --}}
                        <li class="nav-item">
                            <a href="{{ route('students.list') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['students.list', 'students.edit', 'students.show']) ? 'active' : '' }}">Student Information</a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Examinations</div><i class="icon-menu" title="Examinations"></i></li>
                <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['marks.index', 'marks.manage']) ? 'nav-item-expanded nav-item-open' : '' }} ">
                    <a href="#" class="nav-link"><i class="icon-books"></i> <span> Exams</span></a>
                    <ul class="nav nav-group-sub" data-submenu-title="Manage Exams">
                        <li class="nav-item">
                            <a href="{{ route('marks.index') }}" class="nav-link {{ (Route::is('marks.index')) ? 'active' : '' }}">Marks</a>
                        </li>
                        {{-- Exam Date Sheet (owner-ordered module
                             2026-10-22): the teacher's scoped read view --}}
                        <li class="nav-item">
                            <a href="{{ route('exam_dates.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['exam_dates.index', 'exam_dates.show', 'exam_dates.print', 'exam_dates.pdf']) ? 'active' : '' }}">Exam Date Sheet</a>
                        </li>
                        {{-- Owner ruling 2026-10-22: Marksheet + Admit Cards
                             removed from the teacher menu — both are
                             student/office documents, and their routes
                             now refuse the teacher role server-side too. --}}
                    </ul>
                </li>
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Account</div><i class="icon-menu" title="Account"></i></li>
                <li class="nav-item">
                    <a href="{{ route('my_account') }}" class="nav-link {{ (Route::is('my_account')) ? 'active' : '' }}">
                        <i class="icon-user"></i>
                        <span>My Account</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('ask.index') }}" class="nav-link {{ (Route::is('ask.*')) ? 'active' : '' }}">
                        <i class="icon-bubbles4"></i>
                        <span>Ask Hackstra</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('my_payslips.index') }}" class="nav-link {{ (Route::is('my_payslips.*')) ? 'active' : '' }}">
                        <i class="icon-coin-dollar"></i>
                        <span>My Payslips</span>
                    </a>
                </li>
                {{-- Feedback (Workstream CW): report to the school Admin + own list --}}
                <li class="nav-item">
                    <a href="{{ route('feedback.create') }}" class="nav-link {{ (Route::is('feedback.create')) ? 'active' : '' }}">
                        <i class="icon-bubbles"></i>
                        <span>Give Feedback</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('feedback.mine') }}" class="nav-link {{ (Route::is('feedback.mine') || Route::is('feedback.mine.show')) ? 'active' : '' }}">
                        <i class="icon-stack"></i>
                        <span>My Feedback</span>
                    </a>
                </li>@else


                <!-- Main -->
                @if(Qs::userIsSuperAdmin())
                {{-- Platform-only model (owner ruling 2026-10-22,
                     Workstream DD — replaces the Workstream AB portal
                     switcher): the Super Admin owns the PLATFORM. There
                     is no school portal for this account any more: the
                     Platform Overview is its only dashboard, and the
                     school sections below are not rendered for it. --}}
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Portals</div><i class="icon-menu" title="Portals"></i></li>
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ (Route::is('dashboard') || Route::is('home')) ? 'active' : '' }}">
                        <i class="icon-office"></i>
                        <span>Platform Overview</span>
                    </a>
                </li>
                @else
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ (Route::is('dashboard')) ? 'active' : '' }}">
                        <i class="icon-home4"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                @endif

                {{-- Workstream DD: every section from here to the role
                     fragment include is SCHOOL domain. The platform-only
                     Super Admin never renders any of it; all other roles
                     are unaffected (the condition is simply true). --}}
                @if(!Qs::userIsSuperAdmin())
                {{-- Batch 1B: group headers unify the generic sidebar with the
                     Batch 9 teacher sidebar's design language. Headers sit
                     INSIDE the same permission gates as the items they label,
                     so no role sees a header for a section it cannot use. --}}
                @if(Qs::userIsTeamSAT())
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Attendance</div><i class="icon-menu" title="Attendance"></i></li>
                {{-- Workstream AR: the day-wise register is retired — the
                     one Attendance entry point is the period register. --}}
                {{-- Workstream DD: taking a register is a school WRITE
                     (PeriodAttendanceController store is the period's own
                     teacher only). Super Admin is view-only in the school,
                     so the entry renders for the write team (Admin +
                     Teacher) only; SA keeps the two read views below. --}}
                @if(Qs::userIsTeamSATWrite())
                <li class="nav-item">
                    <a href="{{ route('attendance.periods.index') }}" class="nav-link {{ (Route::is('attendance.periods.*')) ? 'active' : '' }}">
                        <i class="icon-watch"></i>
                        <span>Mark Attendance</span>
                    </a>
                </li>
                @endif
                <li class="nav-item">
                    <a href="{{ route('attendance.report') }}" class="nav-link {{ (Route::is('attendance.report')) ? 'active' : '' }}">
                        <i class="icon-stats-bars2"></i>
                        <span>Attendance Report</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('attendance.defaulters') }}" class="nav-link {{ (Route::is('attendance.defaulters')) ? 'active' : '' }}">
                        <i class="icon-alarm"></i>
                        <span>Low Attendance (Below 75%)</span>
                    </a>
                </li>
                @endif

                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Communication</div><i class="icon-menu" title="Communication"></i></li>
                @if(Qs::userIsTeamSA())
                <li class="nav-item">
                    <a href="{{ route('events.index') }}" class="nav-link {{ (Route::is('events.*')) ? 'active' : '' }}">
                        <i class="icon-calendar3"></i>
                        <span>School Events</span>
                    </a>
                </li>
                @endif

                <li class="nav-item">
                    <a href="{{ route('circulars.index') }}" class="nav-link {{ (Route::is('circulars.*')) ? 'active' : '' }}">
                        <i class="icon-megaphone"></i>
                        <span>Circulars</span>
                    </a>
                </li>

                {{--Academics--}}
                @if(Qs::userIsAcademic())
                    <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Academics</div><i class="icon-menu" title="Academics"></i></li>
                    <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['tt.index', 'ttr.edit', 'ttr.show', 'ttr.manage']) ? 'nav-item-expanded nav-item-open' : '' }} ">
                        <a href="#" class="nav-link"><i class="icon-graduation2"></i> <span> Academics</span></a>

                        <ul class="nav nav-group-sub" data-submenu-title="Manage Academics">

                        {{--Timetables--}}
                            <li class="nav-item"><a href="{{ route('tt.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['tt.index']) ? 'active' : '' }}">Timetables</a></li>
                        </ul>
                    </li>
                    @endif

                {{--Administrative--}}
                @if(Qs::userIsAdministrative())
                    <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Administration</div><i class="icon-menu" title="Administration"></i></li>
                    <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['payments.index', 'payments.create', 'payments.invoice', 'payments.receipts', 'payments.edit', 'payments.manage', 'payments.show', 'payments.defaulters', 'payments.collection', 'payroll.index', 'payroll.payslip',]) ? 'nav-item-expanded nav-item-open' : '' }} ">
                        <a href="#" class="nav-link"><i class="icon-office"></i> <span> Administrative</span></a>

                        <ul class="nav nav-group-sub" data-submenu-title="Administrative">

                            {{--Payments--}}
                            @if(Qs::userIsTeamAccount())
                            <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['payments.index', 'payments.create', 'payments.edit', 'payments.manage', 'payments.show', 'payments.invoice', 'payments.defaulters', 'payments.collection', 'payroll.index', 'payroll.payslip']) ? 'nav-item-expanded' : '' }}">

                                <a href="#" class="nav-link {{ in_array(Route::currentRouteName(), ['payments.index', 'payments.edit', 'payments.create', 'payments.manage', 'payments.show', 'payments.invoice', 'payments.defaulters', 'payments.collection', 'payroll.index', 'payroll.payslip']) ? 'active' : '' }}">Payments</a>

                                <ul class="nav nav-group-sub">
                                    @if(Qs::userIsTeamAccountWrite())
                                    <li class="nav-item"><a href="{{ route('payments.create') }}" class="nav-link {{ Route::is('payments.create') ? 'active' : '' }}">Create Payment</a></li>
                                    @endif
                                    <li class="nav-item"><a href="{{ route('payments.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['payments.index', 'payments.edit', 'payments.show']) ? 'active' : '' }}">Manage Payments</a></li>
                                    <li class="nav-item"><a href="{{ route('payments.manage') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['payments.manage', 'payments.invoice', 'payments.receipts']) ? 'active' : '' }}">Student Payments</a></li>
                                    <li class="nav-item"><a href="{{ route('payments.defaulters') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['payments.defaulters']) ? 'active' : '' }}">Fee Defaulters</a></li>
                                    <li class="nav-item"><a href="{{ route('payments.collection') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['payments.collection']) ? 'active' : '' }}">Collection Report</a></li>
                                    @if(Qs::userIsTeamSA())
                                    <li class="nav-item"><a href="{{ route('payroll.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['payroll.index', 'payroll.payslip']) ? 'active' : '' }}">Payroll</a></li>
                                    @endif

                                </ul>

                            </li>
                            @endif
                        </ul>
                    </li>
                @endif

                {{--Manage Students--}}
                @if(Qs::userIsTeamSAT())
                    <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Students</div><i class="icon-menu" title="Students"></i></li>
                    <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['students.create', 'students.list', 'students.edit', 'students.show', 'students.promotion', 'students.promotion_manage', 'students.graduated']) ? 'nav-item-expanded nav-item-open' : '' }} ">
                        <a href="#" class="nav-link"><i class="icon-users"></i> <span> Students</span></a>

                        <ul class="nav nav-group-sub" data-submenu-title="Manage Students">
                            {{--Admit Student--}}
                            @if(Qs::userIsTeamSAWrite())
                                <li class="nav-item">
                                    <a href="{{ route('students.create') }}"
                                       class="nav-link {{ (Route::is('students.create')) ? 'active' : '' }}">Admit Student</a>
                                </li>
                            @endif

                            {{--Student Information--}}
                            {{-- Owner ruling 2026-10-22: ONE link to the
                                 Student Information landing (class/section
                                 picker) — the per-class expansion is gone. --}}
                            <li class="nav-item">
                                <a href="{{ route('students.list') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['students.list', 'students.edit', 'students.show']) ? 'active' : '' }}">Student Information</a>
                            </li>

                            @if(Qs::userIsTeamSA())

                            {{--Student Promotion--}}
                            {{-- Workstream DD: promoting/resetting promotions is a
                                 school WRITE — PromotionController gates
                                 promote/reset/reset_all behind teamSAWrite
                                 (Admin only). The SA menu therefore drops the
                                 whole Promotion submenu; SA keeps the read
                                 views (Student Information, Students Graduated). --}}
                            @if(Qs::userIsTeamSAWrite())
                            <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['students.promotion', 'students.promotion_manage']) ? 'nav-item-expanded' : '' }}"><a href="#" class="nav-link {{ in_array(Route::currentRouteName(), ['students.promotion', 'students.promotion_manage' ]) ? 'active' : '' }}">Student Promotion</a>
                            <ul class="nav nav-group-sub">
                                <li class="nav-item"><a href="{{ route('students.promotion') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['students.promotion']) ? 'active' : '' }}">Promote Students</a></li>
                                <li class="nav-item"><a href="{{ route('students.promotion_manage') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['students.promotion_manage']) ? 'active' : '' }}">Manage Promotions</a></li>
                            </ul>

                            </li>
                            @endif

                            {{--Student Graduated--}}
                            <li class="nav-item"><a href="{{ route('students.graduated') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['students.graduated' ]) ? 'active' : '' }}">Students Graduated</a></li>
                                @endif

                        </ul>
                    </li>
                @endif

                @if(Qs::userIsTeamSA())
                    <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Management</div><i class="icon-menu" title="Management"></i></li>
                    {{--Manage Users--}}
                    <li class="nav-item">
                        <a href="{{ route('users.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['users.index', 'users.show', 'users.edit']) ? 'active' : '' }}"><i class="icon-users4"></i> <span> Users</span></a>
                    </li>

                    {{--Bulk Management (Phase A): staged CSV add/edit hub.
                        Owner-ordered single menu item; Admin-only, like
                        every bulk write (teamSAWrite).--}}
                    @if(Qs::userIsAdmin())
                    <li class="nav-item">
                        <a href="{{ route('bulk.index') }}" class="nav-link {{ \Illuminate\Support\Str::startsWith(Route::currentRouteName() ?? '', 'bulk.') ? 'active' : '' }}"><i class="icon-upload"></i> <span> Bulk Management</span></a>
                    </li>
                    @endif

                    {{--Manage Classes--}}
                    <li class="nav-item">
                        <a href="{{ route('classes.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['classes.index','classes.edit']) ? 'active' : '' }}"><i class="icon-windows2"></i> <span> Classes</span></a>
                    </li>

                    {{--Manage Dorms--}}
                    <li class="nav-item">
                        <a href="{{ route('dorms.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['dorms.index','dorms.edit']) ? 'active' : '' }}"><i class="icon-home9"></i> <span> Dormitories</span></a>
                    </li>

                    {{--Manage Sections--}}
                    <li class="nav-item">
                        <a href="{{ route('sections.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['sections.index','sections.edit',]) ? 'active' : '' }}"><i class="icon-fence"></i> <span>Sections</span></a>
                    </li>

                    {{--Manage Subjects--}}
                    <li class="nav-item">
                        <a href="{{ route('subjects.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['subjects.index','subjects.edit',]) ? 'active' : '' }}"><i class="icon-pin"></i> <span>Subjects</span></a>
                    </li>

                    {{--Teacher Eligibility (Scheduling Engine Slice A):
                         which subjects each teacher is qualified to
                         teach — the pool the allocation engine uses. --}}
                    @if(Qs::userIsTeamSA())
                    <li class="nav-item">
                        <a href="{{ route('planning.readiness') }}" class="nav-link {{ (Route::is('planning.readiness')) ? 'active' : '' }}"><i class="icon-clipboard3"></i> <span>School Readiness</span></a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('planning.staffing') }}" class="nav-link {{ (Route::is('planning.staffing')) ? 'active' : '' }}"><i class="icon-stats-bars"></i> <span>Staffing Dashboard</span></a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('planning.eligibility') }}" class="nav-link {{ (Route::is('planning.eligibility')) ? 'active' : '' }}"><i class="icon-user-check"></i> <span>Teacher Eligibility</span></a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('planning.allocation') }}" class="nav-link {{ (Route::is('planning.allocation')) ? 'active' : '' }}"><i class="icon-tree7"></i> <span>Teacher Allocation</span></a>
                    </li>
                    @endif

                    {{--Feedback (Workstream CW): Admin triages the school
                         inbox; the Super Admin sees forwarded items only--}}
                    @if(Qs::userIsAdmin())
                    <li class="nav-item">
                        <a href="{{ route('feedback.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['feedback.index', 'feedback.show']) ? 'active' : '' }}"><i class="icon-bubbles"></i> <span>Feedback Inbox</span></a>
                    </li>
                    @endif
                    @if(Qs::userIsSuperAdmin())
                    <li class="nav-item">
                        <a href="{{ route('feedback.sa_index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['feedback.sa_index', 'feedback.sa_show']) ? 'active' : '' }}"><i class="icon-bubbles"></i> <span>School Feedback</span></a>
                    </li>
                    @endif
                @endif

                {{--Exam--}}
                @if(Qs::userIsTeamSAT())
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Examinations</div><i class="icon-menu" title="Examinations"></i></li>
                <li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['exams.index', 'exams.edit', 'grades.index', 'grades.edit', 'marks.index', 'marks.manage', 'marks.bulk', 'marks.tabulation', 'marks.show', 'admit_cards.index', 'admit_cards.print',]) ? 'nav-item-expanded nav-item-open' : '' }} ">
                    <a href="#" class="nav-link"><i class="icon-books"></i> <span> Exams</span></a>

                    <ul class="nav nav-group-sub" data-submenu-title="Manage Exams">
                        {{-- Workstream BG: items in exam-workflow order —
                             set up the exam, enter marks, tabulate, print
                             marksheet + admit cards, then grade bands and
                             the marks-correction tool. Labels and order
                             only; every route and permission gate below
                             is exactly as before. --}}
                        @if(Qs::userIsTeamSA())

                        {{--Exam list--}}
                            <li class="nav-item">
                                <a href="{{ route('exams.index') }}"
                                   class="nav-link {{ (Route::is('exams.index')) ? 'active' : '' }}">Exam List</a>
                            </li>
                        @endif

                        @if(Qs::userIsAdmin())
                        {{--Exam Date Sheet (owner-ordered module 2026-10-22):
                            the Admin sets the per-subject dates here--}}
                            <li class="nav-item">
                                <a href="{{ route('exam_dates.manage') }}"
                                   class="nav-link {{ in_array(Route::currentRouteName(), ['exam_dates.manage', 'exam_dates.save']) ? 'active' : '' }}">Exam Date Sheet</a>
                            </li>
                        @endif

                        @if(Qs::userIsTeamSATWrite())
                            {{--Marks Manage--}}
                            <li class="nav-item">
                                <a href="{{ route('marks.index') }}"
                                   class="nav-link {{ in_array(Route::currentRouteName(), ['marks.index']) ? 'active' : '' }}">Marks</a>
                            </li>
                        @endif

                        @if(Qs::userIsTeamSA())
                            {{--Tabulation Sheet--}}
                            <li class="nav-item">
                                <a href="{{ route('marks.tabulation') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['marks.tabulation']) ? 'active' : '' }}">Tabulation Sheet</a>
                            </li>
                        @endif

                        @if(Qs::userIsTeamSAT())
                            {{--Marksheet--}}
                            @if(Qs::userIsTeamSATWrite())
                            <li class="nav-item">
                                <a href="{{ route('marks.bulk') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['marks.bulk', 'marks.show']) ? 'active' : '' }}">Marksheet</a>
                            </li>
                            @endif

                            {{--Admit Cards--}}
                            <li class="nav-item">
                                <a href="{{ route('admit_cards.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['admit_cards.index', 'admit_cards.print']) ? 'active' : '' }}">Admit Cards</a>
                            </li>
                        @endif

                        @if(Qs::userIsTeamSA())
                            {{--Grades list--}}
                            <li class="nav-item">
                                    <a href="{{ route('grades.index') }}"
                                       class="nav-link {{ in_array(Route::currentRouteName(), ['grades.index', 'grades.edit']) ? 'active' : '' }}">Grades</a>
                            </li>

                            {{--Marks Correction menu item removed with the page (owner decision 2026-10-23).--}}
                        @endif

                    </ul>
                </li>
                @endif


                {{--End Exam--}}
                @endif {{-- /school-domain sections (Workstream DD) --}}

                @include('pages.'.Qs::getUserType().'.menu')

                {{--Manage Account--}}
                <li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Account</div><i class="icon-menu" title="Account"></i></li>
                <li class="nav-item">
                    <a href="{{ route('my_account') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['my_account']) ? 'active' : '' }}"><i class="icon-user"></i> <span>My Account</span></a>
                </li>
                {{-- Audit R-17: Ask Hackstra is a school-domain route —
                     PlatformOnly bounces the Super Admin to /dashboard,
                     so the item is hidden for SA (it keeps School
                     Feedback + Platform Mail as its channels). --}}
                @if(!Qs::userIsSuperAdmin())
                <li class="nav-item">
                    <a href="{{ route('ask.index') }}" class="nav-link {{ (Route::is('ask.*')) ? 'active' : '' }}"><i class="icon-bubbles4"></i> <span>Ask Hackstra</span></a>
                </li>
                @endif
                @if(in_array(Auth::user()->user_type, Qs::getStaff(['super_admin'])))
                <li class="nav-item">
                    <a href="{{ route('my_payslips.index') }}" class="nav-link {{ (Route::is('my_payslips.*')) ? 'active' : '' }}"><i class="icon-coin-dollar"></i> <span>My Payslips</span></a>
                </li>
                @endif
                {{-- Feedback (Workstream CW): school roles report to the
                     school Admin and track their own reports. Super Admin
                     excluded (audit R-17): those routes are school-domain
                     and PlatformOnly bounces SA; SA uses School Feedback. --}}
                @if(!Qs::userIsSuperAdmin())
                <li class="nav-item">
                    <a href="{{ route('feedback.create') }}" class="nav-link {{ (Route::is('feedback.create')) ? 'active' : '' }}"><i class="icon-bubbles"></i> <span>Give Feedback</span></a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('feedback.mine') }}" class="nav-link {{ (Route::is('feedback.mine') || Route::is('feedback.mine.show')) ? 'active' : '' }}"><i class="icon-stack"></i> <span>My Feedback</span></a>
                </li>
                @endif
@endif

                </ul>
            </div>
        </div>
{{-- Session display (owner directive 2026-10-21 23:30 IST): the session
     is shown ONCE, in the topbar chip. The sidebar session card that used
     to sit here (teacher "Current Session" / generic "Academic Session",
     incl. its collapsed-rail calendar tile) is removed for every role —
     the Loop 35 shell's nav scroll region now simply extends to the
     bottom of the sidebar under the pinned brand. --}}

</div>

{{-- Loop 91 — sidebar scroll restore (early stub). The scroll fix's
     save/re-assert logic lives in hackstra-ux.js (Loop 91 section);
     this stub runs the moment the sidebar markup above has been
     parsed — before the content area is painted — and restores the
     position saved by the previous page, so the rail never flashes
     at the top and then jumps. It reads only; it never writes, so a
     stored position can never be clobbered with 0 here. --}}
<script>
(function(){try{var v=sessionStorage.getItem('hackstra-sidebar-scroll');if(v!==null){var n=parseInt(v,10);if(n>0){var el=document.querySelector('.sidebar .card-sidebar-mobile')||document.querySelector('.sidebar .sidebar-content');if(el){el.scrollTop=n;}}}}catch(e){}})();
</script>

{{-- Mobile drawer backdrop: tap outside the open drawer to close it (owner request 2026-10-23). --}}
<div class="hk-sidebar-backdrop" aria-hidden="true"></div>
