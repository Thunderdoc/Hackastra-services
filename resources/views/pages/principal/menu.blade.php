{{-- Principal navigation (owner order 2026-10-28): the principal's
     own order — academic leadership, monitoring and designated
     approvals — not the operator's admin menu. School Settings is
     deliberately ABSENT (limited role: settings stay admin-only;
     a dead link would only bounce to login). Every item points
     at a real route; there is no Safety module in the app, so
     Safety & Incidents points at the discipline records, the
     school's actual incident records. --}}
<li class="nav-item">
    <a href="{{ route('dashboard') }}" class="nav-link {{ Route::is('dashboard') ? 'active' : '' }}"><i class="icon-home4"></i> <span>Dashboard</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('classes.index') }}" class="nav-link {{ Route::is('classes.*') ? 'active' : '' }}"><i class="icon-windows2"></i> <span>Academics &amp; Curriculum</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('tt.index') }}" class="nav-link {{ Route::is('tt.*') ? 'active' : '' }}"><i class="icon-calendar3"></i> <span>Timetable &amp; Staffing</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('students.list', optional(App\Models\MyClass::first())->id) }}" class="nav-link {{ Route::is('students.*') ? 'active' : '' }}"><i class="icon-users4"></i> <span>Students</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('users.index') }}" class="nav-link {{ Route::is('users.*') ? 'active' : '' }}"><i class="icon-user-tie"></i> <span>Teachers &amp; Staff</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('attendance.periods.index') }}" class="nav-link {{ Route::is('attendance.*') ? 'active' : '' }}"><i class="icon-calendar52"></i> <span>Attendance</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('exams.index') }}" class="nav-link {{ Route::is('exams.*') ? 'active' : '' }}"><i class="icon-book"></i> <span>Exams &amp; Results</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('leave.index') }}" class="nav-link {{ Route::is('leave.*') ? 'active' : '' }}"><i class="icon-clipboard"></i> <span>Approvals &amp; Requests</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('exam_dates.approvals') }}" class="nav-link {{ Route::is('exam_dates.approvals') ? 'active' : '' }}"><i class="icon-calendar3"></i> <span>Date Sheet Approvals</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('payments.manage') }}" class="nav-link {{ Route::is('payments.*') ? 'active' : '' }}"><i class="icon-wallet"></i> <span>Fees &amp; Finance</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('parent_messages.index') }}" class="nav-link {{ Route::is('parent_messages.*') ? 'active' : '' }}"><i class="icon-bubbles4"></i> <span>Communication &amp; Parent Relations</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('transport.index') }}" class="nav-link {{ Route::is('transport.*') ? 'active' : '' }}"><i class="icon-truck"></i> <span>Transport, Library &amp; Facilities</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('discipline_records.index') }}" class="nav-link {{ Route::is('discipline_records.*') ? 'active' : '' }}"><i class="icon-shield-check"></i> <span>Safety &amp; Incidents</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('reports.builder') }}" class="nav-link {{ Route::is('reports.*') ? 'active' : '' }}"><i class="icon-stats-bars"></i> <span>Reports &amp; Analytics</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('audit_logs.index') }}" class="nav-link {{ Route::is('audit_logs.*') ? 'active' : '' }}"><i class="icon-eye"></i> <span>Audit Logs</span></a>
</li>
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Account</div><i class="icon-menu" title="Account"></i></li>
<li class="nav-item">
    <a href="{{ route('my_account') }}" class="nav-link {{ Route::is('my_account') ? 'active' : '' }}"><i class="icon-user"></i> <span>My Account</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('leave.my') }}" class="nav-link {{ Route::is('leave.my') ? 'active' : '' }}"><i class="icon-calendar52"></i> <span>My Leave</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('ask.index') }}" class="nav-link {{ Route::is('ask.*') ? 'active' : '' }}"><i class="icon-bubbles4"></i> <span>Ask Hackstra</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('feedback.create') }}" class="nav-link {{ Route::is('feedback.create') ? 'active' : '' }}"><i class="icon-bubbles"></i> <span>Give Feedback</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('feedback.mine') }}" class="nav-link {{ Route::is('feedback.mine') || Route::is('feedback.mine.show') ? 'active' : '' }}"><i class="icon-stack"></i> <span>My Feedback</span></a>
</li>
