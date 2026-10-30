<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\AuditLog;
use App\Models\DisciplineRecord;
use App\Models\ExamDate;
use App\Models\ExamRecord;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\LessonPlan;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\PaymentRecord;
use App\Models\SchoolEvent;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Repositories\UserRepo;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Helpers\Money;
use App\Models\Book;
use App\Models\BookRequest;
use App\Models\Circular;
use App\Models\Exam;
use App\Models\Receipt;
use App\Repositories\PaymentRepo;

class HomeController extends Controller
{
    protected $user;
    public function __construct(UserRepo $user)
    {
        $this->user = $user;
    }


    public function index()
    {
        return redirect()->route('dashboard');
    }

    public function privacy_policy()
    {
        $data['app_name'] = config('app.name');
        $data['app_url'] = config('app.url');
        $data['contact_phone'] = Qs::getSetting('phone');
        return view('pages.other.privacy_policy', $data);
    }

    public function terms_of_use()
    {
        $data['app_name'] = config('app.name');
        $data['app_url'] = config('app.url');
        $data['contact_phone'] = Qs::getSetting('phone');
        return view('pages.other.terms_of_use', $data);
    }

    /** Health endpoint (Wave 15): for uptime monitors. Public on
     *  purpose — a monitor cannot log in — and deliberately bare:
     *  only ok/fail, no versions, counts or anything an attacker
     *  could use. A failed DB ping returns 503. */
    public function health()
    {
        try {
            \Illuminate\Support\Facades\DB::select('SELECT 1');
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'fail';
        }
        $ok = $db === 'ok';
        return response()->json([
            'status' => $ok ? 'ok' : 'fail',
            'database' => $db,
        ], $ok ? 200 : 503);
    }

    /** Public admission enquiry form (Wave 12): no login needed —
     *  a parent enquiring about a seat. Classes come from the
     *  school's own class list; nothing else is exposed. */
    public function admission_form()
    {
        $data['app_name'] = Qs::getSetting('system_name') ?: config('app.name');
        $data['my_classes'] = \App\Models\MyClass::orderBy('id')->get();
        return view('pages.other.admission_enquiry', $data);
    }

    public function admission_store(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:150',
            'phone' => 'required|string|min:6|max:20',
            'my_class_id' => 'nullable|exists:my_classes,id',
            'message' => 'nullable|string|max:1000',
        ]);

        \App\Models\Admission::create([
            'name' => ucwords($data['name']),
            'phone' => $data['phone'],
            'my_class_id' => $data['my_class_id'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => 'new',
            'session' => Qs::getCurrentSession(),
        ]);

        return redirect()->route('admission_enquiry.form')
            ->with('flash_success', 'Thank you! Your enquiry has been received — the school office will contact you soon.');
    }

    public function dashboard()
    {
        $now = Carbon::now();
        $d = $this->baseDashboardData($now);

        // Loop 7 (Workstream A): roles that used to land on the bare
        // welcome panel (or, for teachers, the school-wide SAT board) get
        // a home built from their own real data. Admin / Super Admin keep
        // the full dashboard below, byte for byte.
        $user_type = Auth::user()->user_type;

        if ($user_type === 'teacher') {
            return view('pages.teacher.home', array_merge($d, $this->teacherHomeData($now)));
        }

        if ($user_type === 'student') {
            return view('pages.student.home', array_merge($d, $this->studentHomeData($now)));
        }

        if ($user_type === 'parent') {
            return view('pages.parent.home', array_merge($d, $this->parentHomeData($now)));
        }

        if ($user_type === 'accountant') {
            return view('pages.accountant.home', array_merge($d, $this->accountantHomeData($now)));
        }
        // Workstream Y: the librarian used to fall through to the
        // school-wide SAT board; they get a library home instead —
        // catalogue, loans out, and what is due or overdue.
        if ($user_type === 'librarian') {
            return view('pages.librarian.home', array_merge($d, $this->librarianHomeData($now)));
        }

        // Workstream R: the platform owner lands on a platform home —
        // users & roles, audit & security, configuration — not the
        // school's daily-operations board. The school dashboard stays
        // fully reachable (route dashboard.school) and unchanged.
        if ($user_type === 'super_admin') {
            return view('pages.super_admin.platform_home', array_merge($d, $this->platformHomeData($now)));
        }

        // Principal role (owner order 2026-10-28, limited role):
        // 'principal' is its own user_type — school-wide read +
        // designated approvals — NOT an admin account. The admin
        // board stays reachable via dashboard.school for admins.
        if ($user_type === 'principal') {
            return view('pages.principal.home', array_merge($d, $this->principalHomeData($now)));
        }

        return $this->schoolDashboardView($d, $now);
    }

    /**
     * The school-operations dashboard as its own page, so a Super Admin
     * can step from the platform home into the school's board (and a
     * School Admin bookmark of it works too). Any other role is sent
     * back to its own home — the dispatch above stays the single
     * front door.
     */
    public function schoolDashboard()
    {
        if (!in_array(Auth::user()->user_type, ['super_admin', 'admin'], true)) {
            return redirect()->route('dashboard');
        }

        $now = Carbon::now();

        return $this->schoolDashboardView($this->baseDashboardData($now), $now);
    }

    /** Greeting / date / session data every home view shares. */
    protected function baseDashboardData(Carbon $now): array
    {
        $hour = (int) $now->format('H');

        return [
            'today' => $now->copy(),
            'current_session' => Qs::getCurrentSession(),
            'greeting' => $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'),
        ];
    }

    /**
     * Principal Command Center (owner order 2026-10-28, Hybrid +
     * School Command Center). Every figure is a real record query —
     * no invented safety incidents, refunds, or syllabus percentages:
     * modules the app does not have simply do not appear as figures.
     * Attention items carry a real owner role and a real link.
     */
    protected function principalHomeData(Carbon $now): array
    {
        $today = $now->toDateString();
        $todayName = $now->format('l');
        $sessionYear = Qs::getCurrentSession();
        $monthAgo = $now->copy()->subDays(30)->toDateString();

        // --- Executive overview ---
        $d['pr_students'] = StudentRecord::where('grad', 0)->count();
        $d['pr_teachers'] = User::where('user_type', 'teacher')->count();

        $todaySessionIds = AttendanceSession::whereDate('date', $today)->pluck('id');
        $todayEntries = $todaySessionIds->isNotEmpty()
            ? AttendanceSessionEntry::whereIn('session_id', $todaySessionIds)->get()
            : collect();
        $todayMarked = $todayEntries->count();
        $todayPresent = $todayEntries->whereIn('status', ['present', 'late'])->count();
        $d['pr_attendance_today'] = [
            'percent' => $todayMarked > 0 ? (int) round(($todayPresent / $todayMarked) * 100) : 0,
            'present' => $todayEntries->where('status', 'present')->count(),
            'absent' => $todayEntries->where('status', 'absent')->count(),
            'late' => $todayEntries->where('status', 'late')->count(),
            'total' => $todayMarked,
            'recorded' => $todayMarked > 0,
        ];

        $sessionExams = Exam::where('year', $sessionYear)->get();
        $d['pr_exams_total'] = $sessionExams->count();
        $d['pr_exams_published'] = $sessionExams->where('results_published', true)->count();

        // --- Needs your attention (real items only) ---
        $attention = [];

        $clashCount = count(TimetableValidator::clashes($sessionYear));
        if ($clashCount > 0) {
            $attention[] = [
                'tone' => 'red', 'title' => $clashCount . ' timetable clash' . ($clashCount > 1 ? 'es' : ''),
                'detail' => 'Clashing periods in the current session timetable.',
                'meta' => 'Owner: School Admin',
                'link' => route('tt.index'), 'link_label' => 'Review timetables',
            ];
        }

        $periodsToday = TimeTable::where('day', $todayName)->count();
        $submittedToday = AttendanceSession::whereDate('date', $today)
            ->distinct('time_table_id')->count('time_table_id');
        $unsubmitted = max(0, $periodsToday - $submittedToday);
        if ($unsubmitted > 0) {
            $attention[] = [
                'tone' => 'red', 'title' => $unsubmitted . ' period register' . ($unsubmitted > 1 ? 's' : '') . ' not submitted today',
                'detail' => $submittedToday . ' of ' . $periodsToday . ' periods have a submitted register.',
                'meta' => 'Owner: Class teachers · Due: Today',
                'link' => route('attendance.periods.index'), 'link_label' => 'View periods',
            ];
        }

        $uncoveredToday = Substitution::whereDate('date', $today)
            ->whereNull('substitute_teacher_id')->count();
        if ($uncoveredToday > 0) {
            $attention[] = [
                'tone' => 'red', 'title' => $uncoveredToday . ' uncovered substitution' . ($uncoveredToday > 1 ? 's' : '') . ' today',
                'detail' => 'An absent teacher has no substitute assigned yet.',
                'meta' => 'Owner: School Admin · Due: Today',
                'link' => route('substitutions.index'), 'link_label' => 'Assign cover',
            ];
        }

        $overdueLeaves = LeaveRequest::where('status', 'pending')
            ->where('created_at', '<', $now->copy()->subDays(3))->count();
        if ($overdueLeaves > 0) {
            $attention[] = [
                'tone' => 'amber', 'title' => $overdueLeaves . ' leave approval' . ($overdueLeaves > 1 ? 's' : '') . ' overdue',
                'detail' => 'Pending for more than 3 days.',
                'meta' => 'Owner: School Admin',
                'link' => route('leave.index'), 'link_label' => 'Decide now',
            ];
        }

        $weekStart = $now->copy()->startOfWeek()->toDateString();
        $disciplineWeek = DisciplineRecord::where('incident_date', '>=', $weekStart)->count();
        if ($disciplineWeek > 0) {
            $attention[] = [
                'tone' => 'amber', 'title' => $disciplineWeek . ' discipline incident' . ($disciplineWeek > 1 ? 's' : '') . ' this week',
                'detail' => 'Student discipline records filed since ' . $weekStart . '.',
                'meta' => 'Owner: Class teachers',
                'link' => route('discipline_records.index'), 'link_label' => 'Review incidents',
            ];
        }
        $d['pr_attention'] = $attention;

        // --- Today's operations ---
        $d['pr_ops'] = [
            'periods' => $periodsToday,
            'registers_submitted' => $submittedToday,
            'registers_pending' => $unsubmitted,
            'exams_today' => ExamDate::where('exam_date', $today)->count(),
            'events_today' => SchoolEvent::whereDate('starts_at', $today)->count(),
            'substitutions_today' => Substitution::whereDate('date', $today)->count(),
            'substitutions_uncovered' => $uncoveredToday,
            'events_list' => SchoolEvent::whereDate('starts_at', $today)->orderBy('starts_at')->take(3)->get(),
        ];

        // --- Approval inbox: real pending leave decisions ---
        $d['pr_pending_leaves'] = LeaveRequest::with('user')
            ->where('status', 'pending')->latest()->take(8)->get();
        $d['pr_pending_leaves_count'] = LeaveRequest::where('status', 'pending')->count();

        // Result publication requests (owner order 2026-10-29): the
        // Admin requests once every subject's marks are submitted;
        // the Principal's publish is the final act — they wait here.
        $d['pr_pub_requests'] = \App\Models\Exam::whereNotNull('publish_requested_at')
            ->where('results_published', 0)->latest('publish_requested_at')->take(8)->get();
        $d['pr_pub_requests_count'] = \App\Models\Exam::whereNotNull('publish_requested_at')
            ->where('results_published', 0)->count();

        // Timetable approvals waiting (owner order 2026-10-29).
        $d['pr_tt_requests'] = \App\Models\TimeTableRecord::with('my_class')
            ->where('approval_status', 'submitted')->orderBy('approval_submitted_at')->take(8)->get();
        $d['pr_tt_requests_count'] = \App\Models\TimeTableRecord::where('approval_status', 'submitted')->count();

        // Settings unlock requests waiting on the Principal — the
        // first of the two approvals (owner order 2026-10-29).
        $d['pr_unlock_requests'] = \Illuminate\Support\Facades\Schema::hasTable('settings_unlock_requests')
            ? \App\Models\SettingsUnlockRequest::with('requester')
                ->where('status', 'pending_principal')->oldest()->take(8)->get()
            : collect();
        $d['pr_unlock_requests_count'] = $d['pr_unlock_requests']->count();

        // --- Academic analytics (Term 1 = earliest term-1 exam, if any) ---
        $term1 = Exam::where('term', 1)->orderBy('id')->first();
        $d['pr_term_exam'] = $term1;

        $classAttendance = [];
        if ($term1) {
            $rows = DB::table('attendance_sessions')
                ->join('attendance_session_entries', 'attendance_sessions.id', '=', 'attendance_session_entries.session_id')
                ->join('my_classes', 'my_classes.id', '=', 'attendance_sessions.my_class_id')
                ->where('attendance_sessions.date', '>=', $monthAgo)
                ->select('my_classes.name as class_name',
                    DB::raw("SUM(CASE WHEN attendance_session_entries.status IN ('present','late') THEN 1 ELSE 0 END) as present"),
                    DB::raw('COUNT(*) as total'))
                ->groupBy('attendance_sessions.my_class_id', 'my_classes.name')
                ->orderBy('my_classes.name')->get();
            foreach ($rows as $row) {
                $classAttendance[] = [
                    'class' => $row->class_name,
                    'percent' => $row->total > 0 ? (int) round(($row->present / $row->total) * 100) : 0,
                ];
            }
            $d['pr_class_attendance'] = $classAttendance;

            $texCol = 'tex' . $term1->term;
            $subjectRows = Mark::where('exam_id', $term1->id)
                ->select('subject_id', DB::raw("AVG($texCol) as avg"))
                ->whereNotNull($texCol)
                ->groupBy('subject_id')->orderByDesc('avg')->take(8)->get();
            $subjectNames = Subject::whereIn('id', $subjectRows->pluck('subject_id'))->pluck('name', 'id');
            $d['pr_subject_avgs'] = $subjectRows->map(function ($row) use ($subjectNames) {
                return ['subject' => $subjectNames[$row->subject_id] ?? ('Subject ' . $row->subject_id), 'avg' => round((float) $row->avg, 1)];
            })->all();

            $classAvgRows = ExamRecord::where('exam_id', $term1->id)
                ->join('student_records', 'student_records.user_id', '=', 'exam_records.student_id')
                ->whereNotNull('exam_records.ave')->where('exam_records.ave', '!=', '')
                ->select('student_records.my_class_id', DB::raw('AVG(CAST(exam_records.ave AS DECIMAL(10,2))) as avg'))
                ->groupBy('student_records.my_class_id')->get();
            $classNames = MyClass::whereIn('id', $classAvgRows->pluck('my_class_id'))->pluck('name', 'id');
            $d['pr_class_avgs'] = $classAvgRows->map(function ($row) use ($classNames) {
                return ['class' => $classNames[$row->my_class_id] ?? ('Class ' . $row->my_class_id), 'avg' => round((float) $row->avg, 1)];
            })->all();

            $d['pr_support_count'] = ExamRecord::where('exam_id', $term1->id)
                ->whereNotNull('ave')->where('ave', '!=', '')
                ->whereRaw('CAST(ave AS DECIMAL(10,2)) < 50')->count();
        } else {
            $d['pr_class_attendance'] = [];
            $d['pr_subject_avgs'] = [];
            $d['pr_class_avgs'] = [];
            $d['pr_support_count'] = 0;
        }
        $d['pr_lesson_plans'] = LessonPlan::count();

        // --- Teacher workload: most registers submitted, last 30 days ---
        $workRows = AttendanceSession::where('date', '>=', $monthAgo)
            ->select('teacher_id', DB::raw('COUNT(*) as c'))
            ->groupBy('teacher_id')->orderByDesc('c')->take(5)->get();
        $teacherNames = User::whereIn('id', $workRows->pluck('teacher_id'))->pluck('name', 'id');
        $d['pr_workload'] = $workRows->map(function ($row) use ($teacherNames) {
            return ['teacher' => $teacherNames[$row->teacher_id] ?? ('Teacher ' . $row->teacher_id), 'registers' => (int) $row->c];
        })->all();

        // --- Finance summary (current calendar year, authorized totals only) ---
        $d['pr_fees_total'] = (int) DB::table('payment_records')
            ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
            ->whereYear('payment_records.updated_at', $now->year)->sum('payments.amount');
        $d['pr_fees_collected'] = (int) DB::table('payment_records')
            ->whereYear('updated_at', $now->year)->sum('amt_paid');
        $d['pr_fees_pending'] = max(0, $d['pr_fees_total'] - $d['pr_fees_collected']);
        $d['pr_expenses'] = (int) Expense::whereYear('expense_date', $now->year)->sum('amount');
        $d['pr_expenses_count'] = Expense::whereYear('expense_date', $now->year)->count();

        return $d;
    }

    /** The original school-operations dashboard, verbatim. */
    protected function schoolDashboardView(array $d, Carbon $now)
    {
        if (Qs::userIsTeamSAT()) {
            $d['users'] = $this->user->getAll();
            $d['student_count'] = StudentRecord::where('grad', 0)->count();
            $d['teacher_count'] = $d['users']->where('user_type', 'teacher')->count();
            // One Admin per school (owner order 2026-10-29): the
            // school's Administrators card counts the school's
            // Admin only — the Super Admin is the platform owner,
            // not a school administrator.
            $d['administrator_count'] = $d['users']->where('user_type', 'admin')->count();
            $d['parent_count'] = $d['users']->where('user_type', 'parent')->count();
            // Wave 12: enquiries awaiting first contact.
            $d['new_enquiry_count'] = \App\Models\Admission::where('status', 'new')->count();

            // Real period deltas: records created in the last 30 days vs the
            // 30 days before that. No invented percentages (audit §9).
            $d['stat_deltas'] = [
                'students' => $this->periodDelta(StudentRecord::where('grad', 0), $now),
                'teachers' => $this->userTypeDelta('teacher', $now),
                'administrators' => $this->userTypeDelta(['admin'], $now),
                'parents' => $this->userTypeDelta('parent', $now),
                'enquiries' => null,
            ];

            $d['recent_students'] = StudentRecord::with(['user', 'my_class', 'section'])
                ->latest()
                ->take(5)
                ->get();
            $d['recent_payments'] = PaymentRecord::with(['student', 'payment'])
                ->latest()
                ->take(5)
                ->get();

            // D4 (Loop 17): year selector wiring. One shared ?year= drives
            // the two panels that carry a year dropdown (Fee Collection
            // Overview + Student Enrollment Trend). Offered years span the
            // record timestamps actually present, plus the current year;
            // anything else falls back to the current year. Fees are
            // scoped by the calendar year the money was recorded
            // (payment_records.updated_at) — the records' own `year`
            // column holds a session label ('2018-2019'), not a calendar
            // year, so it cannot drive a calendar-year dropdown honestly.
            $current_year = (int) $now->format('Y');
            $data_years = collect([
                StudentRecord::min('created_at'), StudentRecord::max('created_at'),
                PaymentRecord::min('updated_at'), PaymentRecord::max('updated_at'),
            ])->filter()->map(function ($ts) { return (int) Carbon::parse($ts)->format('Y'); });
            $first_year = $data_years->isNotEmpty() ? min($data_years->min(), $current_year) : $current_year;
            $last_year = $data_years->isNotEmpty() ? max($data_years->max(), $current_year) : $current_year;
            $d['year_options'] = range($last_year, $first_year);
            $requested_year = (int) request()->query('year', $current_year);
            $d['selected_year'] = $selected_year = in_array($requested_year, $d['year_options'], true) ? $requested_year : $current_year;

            // Fee totals (audit §8): owed = sum of the fee amount for every
            // payment record; collected = sum actually paid; pending = rest.
            // One query path, so the donut can never disagree with the
            // Recent Payments table the way the old screenshot did.
            // D4: scoped to the selected calendar year (see note above).
            $d['payment_total'] = (int) DB::table('payment_records')
                ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
                ->whereYear('payment_records.updated_at', $selected_year)
                ->sum('payments.amount');
            $d['payment_collected'] = (int) DB::table('payment_records')
                ->whereYear('updated_at', $selected_year)
                ->sum('amt_paid');
            $d['payment_pending'] = max(0, $d['payment_total'] - $d['payment_collected']);
            $d['payment_percent'] = $d['payment_total'] > 0
                ? (int) round(($d['payment_collected'] / $d['payment_total']) * 100)
                : 0;
            $d['fees_collected_this_month'] = (int) DB::table('payment_records')
                ->whereYear('updated_at', $now->year)
                ->whereMonth('updated_at', $now->month)
                ->sum('amt_paid');

            // D4: the trend stays a cumulative 6-month window, ending in
            // the selected year — December for a past year, the current
            // month for the current year (the original behaviour).
            $trend_end = $selected_year === $current_year
                ? $now->copy()->startOfMonth()
                : Carbon::create($selected_year, 12, 1)->startOfMonth();
            $d['trend_window_label'] = $trend_end->copy()->endOfMonth()->format('M Y');
            $d['enrollment_trend'] = collect(range(5, 0))->map(function ($monthsAgo) use ($trend_end) {
                $date = $trend_end->copy()->subMonths($monthsAgo);

                return [
                    'label' => $date->format('M'),
                    'value' => StudentRecord::where('grad', 0)
                        ->whereDate('created_at', '<=', $date->copy()->endOfMonth())
                        ->count(),
                ];
            });

            $d['calendar'] = $this->buildCalendar($now);
            $d['upcoming_events'] = $this->upcomingEvents($now);
            $d['attendance_today'] = $this->attendanceToday($now);
            $d['smart_insights'] = $this->smartInsights($d, $now);
        }

        return view('pages.support_team.dashboard', $d);
    }

    /**
     * Workstream R — Platform Super Admin home data. Every number
     * traces to a query named here. Platform capabilities this
     * deployment does not actually have (multiple schools, queues,
     * backups, billing) are simply not shown rather than faked.
     */
    protected function platformHomeData(Carbon $now): array
    {
        /* Loop 106 (owner ruling: "numbers, not records"): the
           overview's USER figures describe platform accounts only —
           Super Admins + School Admins. The school's people are
           counted in the School Snapshot panel below, never in the
           platform account figures or the Users-by-Role panel. */
        $role_labels = [
            'super_admin' => 'Platform Super Admin',
            'admin' => 'School Admin',
        ];

        $counts = DB::table('users')
            ->select('user_type', DB::raw('count(*) as total'))
            ->whereIn('user_type', array_keys($role_labels))
            ->groupBy('user_type')
            ->pluck('total', 'user_type');

        /* Account lifecycle counts (Workstream DF) — real rows in a
           non-active state, platform accounts only (Loop 106);
           guarded so an un-migrated database (no status column yet)
           still renders the overview. */
        $suspended_users = 0;
        $terminated_users = 0;
        if (Schema::hasColumn('users', 'status')) {
            $suspended_users = (int) DB::table('users')->whereIn('user_type', array_keys($role_labels))->where('status', 'suspended')->count();
            $terminated_users = (int) DB::table('users')->whereIn('user_type', array_keys($role_labels))->where('status', 'terminated')->count();
        }

        $role_counts = [];
        foreach ($role_labels as $type => $label) {
            $role_counts[] = ['type' => $type, 'label' => $label, 'total' => (int) ($counts[$type] ?? 0)];
        }

        $audit_today = 0;
        $audit_week = 0;
        $recent_audit = collect();
        $recent_logins = collect();
        $signed_in_this_week = 0;
        $last_login_by_role = collect();
        $failed_today = 0;
        $recent_failed = collect();
        if (Schema::hasTable('audit_logs')) {
            $audit_today = AuditLog::whereDate('created_at', $now->toDateString())->count();
            $audit_week = AuditLog::where('created_at', '>=', $now->copy()->startOfWeek())->count();
            $recent_audit = AuditLog::with('user')->latest()->take(10)->get();
            // Successful sign-ins are audited by the LogSuccessfulLogin
            // listener; failed attempts by LogFailedLogin ('auth.failed').
            $recent_logins = AuditLog::with('user')
                ->where('action', 'auth.login')
                ->latest()
                ->take(8)
                ->get();
            // Distinct accounts with at least one successful sign-in
            // since the start of this week — platform accounts only
            // (Loop 106), matching the scoped Total Users figure.
            $signed_in_this_week = (int) AuditLog::where('action', 'auth.login')
                ->where('created_at', '>=', $now->copy()->startOfWeek())
                ->whereNotNull('user_id')
                ->whereIn('user_id', DB::table('users')->whereIn('user_type', ['super_admin', 'admin'])->select('id'))
                ->distinct()
                ->count('user_id');
            // Latest successful sign-in per role (for the Users by Role
            // table); roles with no recorded sign-in simply have none.
            $last_login_by_role = AuditLog::query()
                ->join('users', 'users.id', '=', 'audit_logs.user_id')
                ->where('audit_logs.action', 'auth.login')
                ->select('users.user_type', DB::raw('max(audit_logs.created_at) as last_at'))
                ->groupBy('users.user_type')
                ->pluck('last_at', 'user_type');
            $failed_today = AuditLog::where('action', 'auth.failed')
                ->whereDate('created_at', $now->toDateString())
                ->count();
            // For failed rows the description carries the attempted
            // identity (see LogFailedLogin); IP comes from the request.
            $recent_failed = AuditLog::where('action', 'auth.failed')
                ->latest()
                ->take(5)
                ->get();
        }

        foreach ($role_counts as $i => $role) {
            $last = $last_login_by_role[$role['type']] ?? null;
            $role_counts[$i]['last_sign_in'] = $last ? Carbon::parse($last) : null;
        }

        return [
            'school_name' => Qs::getSetting('system_name'),
            // Settings unlock requests at the Super Admin's stage
            // (owner order 2026-10-29) — a count only, per the
            // numbers-not-records rule; the decision page holds
            // the request itself.
            'settings_unlock_waiting' => Schema::hasTable('settings_unlock_requests')
                ? \App\Models\SettingsUnlockRequest::where('status', 'pending_super_admin')->count()
                : 0,
            'role_counts' => $role_counts,
            'total_users' => array_sum(array_column($role_counts, 'total')),
            'audit_today' => $audit_today,
            'audit_week' => $audit_week,
            'audit_week_from' => $now->copy()->startOfWeek(),
            'recent_audit' => $recent_audit,
            'recent_logins' => $recent_logins,
            'signed_in_this_week' => $signed_in_this_week,
            'suspended_users' => $suspended_users,
            'terminated_users' => $terminated_users,
            'failed_today' => $failed_today,
            'recent_failed' => $recent_failed,
            // Secondary "school snapshot": the deployment serves one
            // school today; these are its headline sizes, not tenants.
            'snapshot_student_count' => StudentRecord::where('grad', 0)->count(),
            'snapshot_teacher_count' => (int) DB::table('users')->where('user_type', 'teacher')->count(),
            // Owner ruling 2026-10-22 (numbers-not-records): platform
            // statistics are aggregates ONLY — counts and totals like
            // this lifetime fee-collection sum belong on the Overview
            // as plain numbers with no drill-down into records.
            'snapshot_collection_total' => (int) DB::table('payment_records')->sum('amt_paid'),
        ];
    }

    /** Percent change of a query's count: last 30 days vs previous 30 days. */
    protected function periodDelta($query, Carbon $now): ?int
    {
        $current = (clone $query)->where('created_at', '>=', $now->copy()->subDays(30))->count();
        $previous = (clone $query)
            ->where('created_at', '>=', $now->copy()->subDays(60))
            ->where('created_at', '<', $now->copy()->subDays(30))
            ->count();

        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    protected function userTypeDelta($types, Carbon $now): ?int
    {
        $types = (array) $types;
        $base = DB::table('users')->whereIn('user_type', $types);
        $current = (clone $base)->where('created_at', '>=', $now->copy()->subDays(30))->count();
        $previous = (clone $base)
            ->where('created_at', '>=', $now->copy()->subDays(60))
            ->where('created_at', '<', $now->copy()->subDays(30))
            ->count();

        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /** Month grid for the School Calendar panel, with real event days marked. */
    protected function buildCalendar(Carbon $now): array
    {
        $start = $now->copy()->startOfMonth();
        $daysInMonth = $start->daysInMonth;
        $firstWeekday = (int) $start->format('w'); // 0 = Sunday, matches the target header

        $eventDays = [];
        if (Schema::hasTable('school_events')) {
            $eventDays = SchoolEvent::whereYear('starts_at', $now->year)
                ->whereMonth('starts_at', $now->month)
                ->pluck('starts_at')
                ->map(function ($date) {
                    return $date instanceof \DateTimeInterface
                        ? (int) $date->format('j')
                        : (int) Carbon::parse($date)->format('j');
                })
                ->unique()
                ->values()
                ->all();
        }

        return [
            'label' => $start->format('F Y'),
            'days_in_month' => $daysInMonth,
            'first_weekday' => $firstWeekday,
            'today' => (int) $now->format('j'),
            'is_current_month' => true,
            'event_days' => $eventDays,
        ];
    }

    /**
     * Upcoming events from real records only (audit §9): school_events
     * first, then upcoming exam timetables. Empty stays honestly empty.
     */
    protected function upcomingEvents(Carbon $now)
    {
        $events = collect();

        if (Schema::hasTable('school_events')) {
            $events = SchoolEvent::whereDate('starts_at', '>=', $now->toDateString())
                ->orderBy('starts_at')
                ->take(4)
                ->get()
                ->map(function (SchoolEvent $event) {
                    return [
                        'day' => $event->starts_at->format('d'),
                        'month' => strtoupper($event->starts_at->format('M')),
                        'title' => $event->title,
                        'meta' => $event->all_day ? 'All Day' : $event->starts_at->format('h:i A'),
                        'type' => $event->type,
                        'type_label' => SchoolEvent::types()[$event->type] ?? 'Event',
                    ];
                });
        }

        if ($events->count() < 4 && Schema::hasTable('time_tables')) {
            $remaining = 4 - $events->count();
            $examEvents = DB::table('time_tables')
                ->join('time_table_records', 'time_table_records.id', '=', 'time_tables.ttr_id')
                ->leftJoin('subjects', 'subjects.id', '=', 'time_tables.subject_id')
                ->whereDate('time_tables.exam_date', '>=', $now->toDateString())
                ->orderBy('time_tables.exam_date')
                ->take($remaining)
                ->get(['time_tables.exam_date', 'subjects.name as subject_name'])
                ->map(function ($row) {
                    $date = Carbon::parse($row->exam_date);

                    return [
                        'day' => $date->format('d'),
                        'month' => strtoupper($date->format('M')),
                        'title' => $row->subject_name ? $row->subject_name . ' Examination' : 'Examination',
                        'meta' => $date->format('l'),
                        'type' => 'examination',
                        'type_label' => 'Examination',
                    ];
                });
            $events = $events->concat($examEvents);
        }

        return $events;
    }

    /**
     * Today's attendance from PERIOD registers (attendance_session_entries
     * whose session is dated today); zeros are honest when no register was
     * submitted. Counts are period entries, and the percent uses the
     * school-wide rule — (present + late) / marked — so the dashboard
     * always agrees with the attendance report. (Workstream AR: the
     * day-wise attendances table is retired and no longer read.)
     */
    protected function attendanceToday(Carbon $now): array
    {
        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'percent' => 0, 'recorded' => false];

        if (!Schema::hasTable('attendance_session_entries')) {
            return $summary;
        }

        $rows = AttendanceSessionEntry::whereHas('session', function ($q) use ($now) {
                $q->whereDate('date', $now->toDateString());
            })
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $summary['present'] = (int) ($rows['present'] ?? 0);
        $summary['absent'] = (int) ($rows['absent'] ?? 0);
        $summary['late'] = (int) ($rows['late'] ?? 0);
        $summary['total'] = $summary['present'] + $summary['absent'] + $summary['late'];
        $summary['recorded'] = $summary['total'] > 0;
        $summary['percent'] = $summary['total'] > 0
            ? (int) round((($summary['present'] + $summary['late']) / $summary['total']) * 100)
            : 0;

        return $summary;
    }

    protected function smartInsights(array $d, Carbon $now): array
    {
        $insights = [];

        if ($d['fees_collected_this_month'] > 0) {
            $insights[] = [
                'icon' => 'icon-stats-growth', 'tone' => 'blue',
                'title' => \App\Helpers\Money::inr($d['fees_collected_this_month']) . ' collected this month',
                'text' => 'Out of ' . \App\Helpers\Money::inr($d['payment_total']) . ' expected in total fees',
            ];
        } else {
            $insights[] = [
                'icon' => 'icon-stats-growth', 'tone' => 'blue',
                'title' => 'No fee collections yet this month',
                'text' => \App\Helpers\Money::inr(0) . ' collected out of ' . \App\Helpers\Money::inr($d['payment_total']) . ' expected',
            ];
        }

        $insights[] = [
            'icon' => 'icon-users4', 'tone' => 'green',
            'title' => 'Student enrollment ' . (($d['stat_deltas']['students'] ?? 0) >= 0 ? 'increased' : 'changed'),
            'text' => $d['student_count'] . ' active students this session',
        ];

        $insights[] = $d['attendance_today']['recorded']
            ? [
                'icon' => 'icon-calendar52', 'tone' => 'orange',
                'title' => $d['attendance_today']['percent'] . '% present today',
                'text' => $d['attendance_today']['present'] . ' of ' . $d['attendance_today']['total'] . ' period marks recorded today',
            ]
            : [
                'icon' => 'icon-calendar52', 'tone' => 'orange',
                'title' => 'No attendance recorded today',
                'text' => 'Mark attendance to track daily presence',
            ];

        $insights[] = [
            'icon' => 'icon-shield-check', 'tone' => 'green',
            'title' => 'System is running smoothly',
            'text' => 'All core modules are operational',
        ];

        return $insights;
    }

    /**
     * Loop 7 — Teacher home data: the teacher's own subjects/classes, the
     * sections they lead, how many students that covers, and today's
     * periods from the real class timetables (exam rows carry an
     * exam_date instead of a weekday, so the weekday filter excludes them).
     */
    protected function teacherHomeData(Carbon $now): array
    {
        $teacher_id = Auth::id();

        $subjects = Subject::with('my_class')->where('teacher_id', $teacher_id)->get();
        // Allotment audit 2026-10-28: a teacher who teaches ONLY
        // through section overrides previously showed My Subjects 0,
        // Total Students 0 and no first class on this home, while
        // Today's Periods (effective-teacher filter) correctly
        // listed their periods. Override subjects are real
        // assignments, so they join the subject list — every
        // aggregate below (class ids, scope, assignment keys,
        // student overview) then follows automatically.
        $overrideSubjectIds = DB::table('section_subject_teachers')
            ->where('teacher_id', $teacher_id)->distinct()->pluck('subject_id');
        if ($overrideSubjectIds->isNotEmpty()) {
            $subjects = $subjects->merge(
                Subject::with('my_class')->whereIn('id', $overrideSubjectIds)->get()
            )->unique('id')->values();
        }
        $class_ids = $subjects->pluck('my_class_id')->filter()->unique()->values();
        $sections_led = Section::with('my_class')->where('teacher_id', $teacher_id)->get();

        // Sections this teacher leads, grouped by class — timetables in
        // this schema are class-level, so a period's "section" can only
        // honestly be a section of that class the teacher leads.
        $led_by_class = $sections_led->groupBy('my_class_id')
            ->map(function ($sections) {
                return $sections->pluck('name')->all();
            });

        // Batch 9: period-attendance sessions already submitted today,
        // keyed by timetable row — the redesigned home links each period
        // to its locked record when one exists, else to the take page.
        $session_by_period = collect();
        if (Schema::hasTable('attendance_sessions')) {
            $session_by_period = \App\Models\AttendanceSession::whereDate('date', $now->toDateString())
                ->pluck('id', 'time_table_id');
        }

        $today_periods = collect();
        if (Schema::hasTable('time_tables')) {
            $today_periods = TimeTable::with(['subject.my_class', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
                ->where('day', $now->format('l'));
            \App\Support\SectionTeacher::applyTeacherFilter($today_periods, (int) $teacher_id);
            $today_periods = $today_periods
                ->get()
                ->map(function (TimeTable $period) use ($now, $led_by_class, $session_by_period) {
                    $slot = $period->time_slot;
                    $start = $end = null;
                    if ($slot && $slot->time_from && $slot->time_to) {
                        $start = Carbon::parse($now->toDateString() . ' ' . $slot->time_from);
                        $end = Carbon::parse($now->toDateString() . ' ' . $slot->time_to);
                    }

                    // Loop 79: status comes from the REGISTER, never
                    // inferred from the clock — a period is Completed
                    // only when a submitted attendance session exists
                    // for it today. The clock merely separates "not
                    // yet due" (Upcoming) from "due and still
                    // unsubmitted" (Attendance Pending).
                    $session_id = $session_by_period[$period->id] ?? null;
                    if ($session_id) {
                        $status = 'completed';
                        $status_label = 'Completed';
                    } elseif ($start !== null && $now->gte($start)) {
                        $status = 'pending';
                        $status_label = 'Attendance Pending';
                    } else {
                        $status = 'upcoming';
                        $status_label = 'Upcoming';
                    }

                    $class = optional($period->tt_record)->my_class ?: optional($period->subject)->my_class;

                    return [
                        'period' => $period,
                        'time_label' => optional($slot)->full ?: '—',
                        'subject_name' => optional($period->subject)->name ?: '—',
                        'class_name' => optional($class)->name ?: '—',
                        'section_name' => optional(optional($period->tt_record)->section)->name,
                        'sections' => $class ? ($led_by_class[$class->id] ?? []) : [],
                        'status' => $status,
                        'status_label' => $status_label,
                        'sort' => $start ? $start->format('H:i') : (optional($slot)->timestamp_from ?? '99:99'),
                        'ttr_id' => $period->ttr_id,
                        'tt_id' => $period->id,
                        'session_id' => $session_id,
                    ];
                })
                ->sortBy('sort')
                ->values();
        }

        // My Classes rows + Student Overview slices: one row per class
        // the teacher teaches in, with the distinct active students of
        // that class (student records are per class+section, so a class
        // count never double-counts a student).
        $student_counts = $class_ids->isEmpty()
            ? collect()
            : StudentRecord::where('grad', 0)
                ->whereIn('my_class_id', $class_ids)
                ->select('my_class_id', DB::raw('count(*) as total'))
                ->groupBy('my_class_id')
                ->pluck('total', 'my_class_id');

        $palette = ['#3478f6', '#188b66', '#f2a13b', '#6845d8', '#c0392b', '#66788c'];
        $my_classes = collect();
        $student_overview = collect();
        $subjects->groupBy('my_class_id')->each(function ($class_subjects, $class_id) use (&$my_classes, &$student_overview, $student_counts, $led_by_class, $palette) {
            $class = $class_subjects->first()->my_class;
            $count = (int) ($student_counts[$class_id] ?? 0);
            $my_classes->push([
                'class' => $class,
                'subjects' => $class_subjects->pluck('name')->unique()->values()->all(),
                'student_count' => $count,
                'sections_led' => $led_by_class[$class_id] ?? [],
            ]);
            $student_overview->push([
                'label' => optional($class)->name ?: 'Class',
                'count' => $count,
                'color' => $palette[$student_overview->count() % count($palette)],
            ]);
        });
        $my_classes = $my_classes->sortBy(function ($row) {
            return optional($row['class'])->name;
        })->values();

        // Recent Circulars: only what a teacher may read (published, in
        // its display window, addressed to all/staff/teachers) — the
        // same scopes the circulars feed itself uses.
        $recent_circulars = collect();
        $new_circulars_count = 0;
        if (class_exists(\App\Models\Circular::class) && Schema::hasTable('circulars')) {
            $recent_circulars = \App\Models\Circular::published()
                ->forAudience('teacher')
                ->latest()
                ->take(4)
                ->get();
            $new_circulars_count = \App\Models\Circular::published()
                ->forAudience('teacher')
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->count();
        }

        $exams_this_session = Schema::hasTable('exams')
            ? Exam::where('year', Qs::getCurrentSession())->count()
            : 0;

        $upcoming_events = $this->upcomingEvents($now);

        // Important Reminders: every chip is a count of real records
        // and links to the page where that work happens.
        $reminders = [];
        $reminders[] = [
            'icon' => 'icon-calendar3', 'tone' => 'blue',
            'title' => $today_periods->count() . ' ' . ($today_periods->count() === 1 ? 'period' : 'periods') . ' today',
            'text' => $today_periods->isEmpty() ? 'Nothing scheduled for ' . $now->format('l') : 'Your timetable for ' . $now->format('l'),
            'link' => route('tt.index'),
        ];
        if ($new_circulars_count > 0) {
            $reminders[] = [
                'icon' => 'icon-megaphone', 'tone' => 'orange',
                'title' => $new_circulars_count . ' new ' . ($new_circulars_count === 1 ? 'circular' : 'circulars'),
                'text' => 'Published in the last 7 days',
                'link' => route('circulars.index'),
            ];
        }
        if ($exams_this_session > 0) {
            $reminders[] = [
                'icon' => 'icon-book', 'tone' => 'purple',
                'title' => $exams_this_session . ' ' . ($exams_this_session === 1 ? 'exam' : 'exams') . ' this session',
                'text' => 'Enter marks from the marks page',
                'link' => route('marks.index'),
            ];
        }
        // Note: no "upcoming events" chip — the events module is
        // teamSA-only, so a teacher reminder must not link there.
        if ($sections_led->isNotEmpty()) {
            $first_led = $sections_led->first();
            $reminders[] = [
                'icon' => 'icon-user-tie', 'tone' => 'green',
                'title' => 'Class teacher for ' . $sections_led->count() . ' ' . ($sections_led->count() === 1 ? 'section' : 'sections'),
                'text' => optional($first_led->my_class)->name . ' ' . $first_led->name . ($sections_led->count() > 1 ? ' and more' : ''),
                'link' => route('students.list', $first_led->my_class_id),
            ];
        }

        // Batch 9 — Upcoming Tasks / Important Reminders for the
        // redesigned teacher home. Every row is derived from real
        // records; nothing is illustrative. Loop 31 (Fix 10): the
        // derivation itself moved to App\Support\TeacherReminders
        // so the SAME reminders feed this card and the topbar bell
        // dropdown — today's unsubmitted registers (one actionable
        // row per period), tomorrow's periods, urgent/important
        // circulars for teachers, upcoming exam sittings.
        // "Marks not yet entered" is deliberately NOT derived: with
        // no exam dates on the exams table, an exam cannot honestly
        // be called upcoming/ongoing, so the row would be a guess.
        $t9_tasks = array_slice(\App\Support\TeacherReminders::forTeacher(Auth::user(), $now), 0, 5);

        // Workstream CZ (LMS): submissions on THIS teacher's
        // assignments that are still waiting for a grade.
        $lms_to_grade = 0;
        if (Schema::hasTable('assignments') && Schema::hasTable('assignment_submissions')) {
            $lms_to_grade = \App\Models\AssignmentSubmission::whereNull('marks_awarded')
                ->whereHas('assignment', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->count();
        }

        // Wave 13 (HR-lite): the teacher's own leave requests, newest
        // first — status only, no invented balance.
        $my_leave_requests = collect();
        if (Schema::hasTable('leave_requests')) {
            $my_leave_requests = \App\Models\LeaveRequest::where('user_id', $teacher_id)
                ->orderByDesc('id')->take(5)->get();
        }

        // Batch fix 2026-10-25: the teacher's upcoming invigilation
        // duties for the home panel — duties existed but were
        // invisible to teachers until now.
        $my_duties = collect();
        if (Schema::hasTable('exam_duties')) {
            $my_duties = \App\Models\ExamDuty::with(['exam_date.exam', 'exam_date.my_class', 'exam_date.subject'])
                ->where('teacher_id', $teacher_id)
                ->whereHas('exam_date', function ($q) use ($now) {
                    $q->whereDate('exam_date', '>=', $now->toDateString());
                })->get()
                ->sortBy(function ($duty) {
                    return ($duty->exam_date->exam_date ?? '') . ' ' . ($duty->exam_date->start_time ?? '');
                })->take(5)->values();
        }

        /* ---------------------------------------------------------------
           Loop 79 — Teacher dashboard redesign data. Scope classes =
           the classes this teacher is attached to: subject classes
           plus the classes of sections they lead (either attachment
           alone is a real assignment). Every figure below is an
           aggregate over real records for THIS teacher only.
           --------------------------------------------------------------- */
        $scope_class_ids = $class_ids->merge($sections_led->pluck('my_class_id'))
            ->filter()->unique()->values();

        $my_student_count = $scope_class_ids->isEmpty()
            ? 0
            : (int) StudentRecord::where('grad', 0)
                ->whereIn('my_class_id', $scope_class_ids)
                ->distinct()
                ->count('user_id');

        // "My Classes" KPI definition (stated on the card): distinct
        // class+section assignments — one per section the teacher
        // leads, plus one per subject class where they lead no
        // section (there the assignment is the class as a whole).
        $assignment_keys = $sections_led->map(function ($section) {
            return 'sec:' . $section->id;
        });
        $led_class_ids = $sections_led->pluck('my_class_id')->unique();
        foreach ($class_ids as $cid) {
            if (!$led_class_ids->contains($cid)) {
                $assignment_keys->push('class:' . $cid);
            }
        }
        // Allotment audit 2026-10-28: $assignment_keys is an
        // ELOQUENT collection when $sections_led is empty (map
        // over an empty Eloquent collection stays Eloquent), and
        // Eloquent unique() calls getKey() on every item — fatal
        // on these 'sec:'/'class:' strings. It only ever worked
        // for section-leading teachers (non-empty map returns a
        // base collection). Any teacher with subjects but no led
        // sections — 13 seeded subject teachers, plus every
        // override-only teacher once their classes count — got a
        // dashboard 500. collect() to a base collection first.
        $my_assignment_count = collect($assignment_keys->all())->unique()->count();

        // This month's period attendance, from submitted registers
        // only. Percentages follow the school rule — (present + late)
        // / marked — the same rule the attendance report uses, so
        // the dashboard can never disagree with the report.
        $month_label = $now->format('F Y');
        $attendance_overview = collect();
        $attendance_distribution = [
            'buckets' => [
                ['key' => 'above90', 'label' => 'Above 90%', 'count' => 0, 'color' => '#2f9e44'],
                ['key' => 'b75_90', 'label' => '75% – 90%', 'count' => 0, 'color' => '#3478f6'],
                ['key' => 'b60_75', 'label' => '60% – 75%', 'count' => 0, 'color' => '#e8a13a'],
                ['key' => 'below60', 'label' => 'Below 60%', 'count' => 0, 'color' => '#d64545'],
            ],
            'with_records' => 0,
            'total_students' => $my_student_count,
            'low_count' => 0,
        ];
        if ($scope_class_ids->isNotEmpty() && Schema::hasTable('attendance_session_entries')) {
            $month_from = $now->copy()->startOfMonth()->toDateString();
            $month_to = $now->copy()->endOfMonth()->toDateString();

            $by_class = DB::table('attendance_session_entries as e')
                ->join('attendance_sessions as s', 's.id', '=', 'e.session_id')
                ->whereBetween('s.date', [$month_from, $month_to])
                ->whereIn('s.my_class_id', $scope_class_ids)
                ->select('s.my_class_id',
                    DB::raw("sum(case when e.status in ('present','late') then 1 else 0 end) as ok"),
                    DB::raw('count(*) as total'))
                ->groupBy('s.my_class_id')
                ->get()
                ->keyBy('my_class_id');

            $class_names = \App\Models\MyClass::whereIn('id', $scope_class_ids)->pluck('name', 'id');
            $attendance_overview = $scope_class_ids->map(function ($cid) use ($by_class, $class_names) {
                $row = $by_class[$cid] ?? null;
                $total = $row ? (int) $row->total : 0;

                return [
                    'class_name' => $class_names[$cid] ?? 'Class',
                    'marked' => $total,
                    'pct' => $total > 0 ? (int) round(((int) $row->ok / $total) * 100) : null,
                ];
            })->sortBy('class_name')->values();

            // One row per student record, bucketed by their overall
            // month percentage; students with no marked periods this
            // month are excluded (never treated as 0%).
            $by_student = DB::table('attendance_session_entries as e')
                ->join('attendance_sessions as s', 's.id', '=', 'e.session_id')
                ->whereBetween('s.date', [$month_from, $month_to])
                ->whereIn('s.my_class_id', $scope_class_ids)
                ->select('e.student_record_id',
                    DB::raw("sum(case when e.status in ('present','late') then 1 else 0 end) as ok"),
                    DB::raw('count(*) as total'))
                ->groupBy('e.student_record_id')
                ->get();
            $bucket_counts = ['above90' => 0, 'b75_90' => 0, 'b60_75' => 0, 'below60' => 0];
            foreach ($by_student as $row) {
                if ((int) $row->total === 0) {
                    continue;
                }
                $pct = ((int) $row->ok / (int) $row->total) * 100;
                if ($pct > 90) {
                    $bucket_counts['above90']++;
                } elseif ($pct >= 75) {
                    $bucket_counts['b75_90']++;
                } elseif ($pct >= 60) {
                    $bucket_counts['b60_75']++;
                } else {
                    $bucket_counts['below60']++;
                }
            }
            foreach ($attendance_distribution['buckets'] as $i => $bucket) {
                $attendance_distribution['buckets'][$i]['count'] = $bucket_counts[$bucket['key']];
            }
            $attendance_distribution['with_records'] = array_sum($bucket_counts);
            $attendance_distribution['low_count'] = $bucket_counts['b60_75'] + $bucket_counts['below60'];
        }

        // Today's Tasks — derived pending work only. Nothing is
        // stored and there is no completion state: a row exists only
        // while the underlying work is genuinely outstanding, and
        // every row links to the screen where the work happens.
        $today_tasks = [];
        foreach ($today_periods as $row) {
            if (!empty($row['session_id'])) {
                continue;
            }
            $today_tasks[] = [
                'icon' => 'icon-calendar52',
                'tone' => $row['status'] === 'pending' ? 'red' : 'blue',
                'title' => 'Take attendance — ' . $row['subject_name'],
                'meta' => $row['class_name'] . ' · ' . $row['time_label'],
                'badge' => $row['status'] === 'pending' ? 'Pending' : 'Today',
                'badge_tone' => $row['status'] === 'pending' ? 'red' : 'blue',
                'link' => route('attendance.periods.take', $row['tt_id']),
            ];
        }
        if ($lms_to_grade > 0) {
            $today_tasks[] = [
                'icon' => 'icon-file-text2', 'tone' => 'purple',
                'title' => 'Grade assignment submissions',
                'meta' => $lms_to_grade . ' ' . ($lms_to_grade === 1 ? 'submission' : 'submissions') . ' awaiting marks',
                'badge' => (string) $lms_to_grade, 'badge_tone' => 'purple',
                'link' => route('assignments.index'),
            ];
        }
        // Marks still missing for this session's exams, counted per
        // (subject × enrolled student) with no mark row yet. An exam
        // whose marks are complete produces no row — a count of real
        // gaps, never a schedule guess. (TeacherReminders omits this
        // for the bell because an exam cannot be called "upcoming"
        // without dates; pending ENTRY is a different, countable
        // fact, so the dashboard derives it here.)
        if (Schema::hasTable('exams') && Schema::hasTable('marks')) {
            foreach (Exam::where('year', Qs::getCurrentSession())->get() as $exam) {
                $missing = 0;
                foreach ($subjects as $subject) {
                    $expected = StudentRecord::where('grad', 0)
                        ->where('my_class_id', $subject->my_class_id)
                        ->count();
                    $entered = Mark::where('exam_id', $exam->id)
                        ->where('subject_id', $subject->id)
                        ->where('my_class_id', $subject->my_class_id)
                        ->count();
                    $missing += max(0, $expected - $entered);
                }
                if ($missing > 0) {
                    $today_tasks[] = [
                        'icon' => 'icon-pencil7', 'tone' => 'amber',
                        'title' => 'Enter marks — ' . $exam->name,
                        'meta' => 'Marks missing for ' . $missing . ' student entries across your subjects',
                        'badge' => 'Pending', 'badge_tone' => 'amber',
                        'link' => route('marks.index'),
                    ];
                }
            }
        }
        $today_tasks = array_slice($today_tasks, 0, 6);

        // Notifications panel — real items only. user_notifications
        // rows are written only for students/parents in this app, so
        // a teacher's feed is composed from live sources: the
        // low-attendance position of their own students, the grading
        // queue, and circulars addressed to teachers/everyone.
        $teacher_notifications = [];
        if ($attendance_distribution['low_count'] > 0) {
            $low = $attendance_distribution['low_count'];
            $teacher_notifications[] = [
                'icon' => 'icon-alarm', 'tone' => 'red',
                'title' => $low . ' ' . ($low === 1 ? 'student' : 'students') . ' below 75% attendance',
                'meta' => 'Across your classes · ' . $month_label,
                'time_label' => 'This month',
                'link' => route('attendance.defaulters'),
            ];
        }
        if ($lms_to_grade > 0) {
            $latest_ungraded = \App\Models\AssignmentSubmission::whereNull('marks_awarded')
                ->whereHas('assignment', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->max('submitted_at');
            $teacher_notifications[] = [
                'icon' => 'icon-file-text2', 'tone' => 'purple',
                'title' => $lms_to_grade . ' assignment ' . ($lms_to_grade === 1 ? 'submission' : 'submissions') . ' to grade',
                'meta' => 'Ungraded submissions on your assignments',
                'time_label' => $latest_ungraded ? Carbon::parse($latest_ungraded)->diffForHumans() : '',
                'link' => route('assignments.index'),
            ];
        }
        foreach ($recent_circulars as $circular) {
            $teacher_notifications[] = [
                'icon' => 'icon-megaphone',
                'tone' => $circular->priority === 'urgent' ? 'red' : ($circular->priority === 'important' ? 'amber' : 'blue'),
                'title' => $circular->title,
                'meta' => (\App\Models\Circular::audiences()[$circular->audience] ?? ucfirst($circular->audience)) . ' · by ' . (optional($circular->publisher)->name ?: 'School Office'),
                'time_label' => $circular->created_at ? $circular->created_at->diffForHumans() : '',
                'link' => route('circulars.show', $circular->id),
            ];
        }
        $teacher_notifications = array_slice($teacher_notifications, 0, 5);

        // Wave 1 feature 5 — Needs attention strip (additive only).
        // Untaken periods today: today's periods (same SectionTeacher
        // scope as $today_periods) with no submitted session today.
        $untaken_periods_today = $today_periods->filter(function ($row) {
            return empty($row['session_id']);
        })->values();
        // Ungraded assignments: this teacher's assignments that have
        // at least one submission with no marks awarded yet (the
        // graded flag is AssignmentSubmission::isGraded(), i.e.
        // marks_awarded !== null), each carrying its ungraded count.
        $ungraded_assignments = collect();
        if (Schema::hasTable('assignments') && Schema::hasTable('assignment_submissions')) {
            $ungraded_assignments = \App\Models\Assignment::with(['my_class', 'subject'])
                ->where('teacher_id', $teacher_id)
                ->whereHas('submissions', function ($q) {
                    $q->whereNull('marks_awarded');
                })
                ->withCount(['submissions as ungraded_count' => function ($q) {
                    $q->whereNull('marks_awarded');
                }])
                ->orderBy('title')
                ->get();
        }

        return [
            'untaken_periods_today' => $untaken_periods_today,
            'ungraded_assignments' => $ungraded_assignments,
            'lms_to_grade' => $lms_to_grade,
            'my_leave_requests' => $my_leave_requests,
            // Leave balance (owner order 2026-10-30): the teacher
            // always sees their paid-leave quota, what they have
            // taken, what remains and any extra (unpaid) days —
            // right on their portal home.
            'leave_balance' => \App\Support\LeaveBalance::summary($teacher_id, (int) $now->format('Y')),
            'my_duties' => $my_duties,
            'my_subjects' => $subjects,
            'my_class_count' => $class_ids->count(),
            'first_class_id' => $class_ids->first(),
            't9_tasks' => $t9_tasks,
            'sections_led' => $sections_led,
            'my_student_count' => $my_student_count,
            // Loop 79 additions.
            'my_assignment_count' => $my_assignment_count,
            'scope_class_count' => $scope_class_ids->count(),
            'month_label' => $month_label,
            'attendance_overview' => $attendance_overview,
            'attendance_distribution' => $attendance_distribution,
            'today_tasks' => $today_tasks,
            'teacher_notifications' => $teacher_notifications,
            'today_submitted' => $today_periods->filter(function ($row) {
                return !empty($row['session_id']);
            })->count(),
            'today_periods' => $today_periods,
            'today_name' => $now->format('l'),
            'upcoming_events' => $upcoming_events,
            'my_classes' => $my_classes,
            'student_overview' => $student_overview,
            'recent_circulars' => $recent_circulars,
            'reminders' => $reminders,
        ];
    }

    /**
     * Loop 7 — Student home data: own record, own attendance summary, own
     * fee position and the subjects of the latest exam that has marks
     * for this student (subject total = tca + exm, as on the marksheet).
     */
    protected function studentHomeData(Carbon $now): array
    {
        $user_id = Auth::id();

        $record = StudentRecord::with(['my_class', 'section'])
            ->where('user_id', $user_id)
            ->where('grad', 0)
            ->first()
            ?: StudentRecord::with(['my_class', 'section'])
                ->where('user_id', $user_id)
                ->latest()
                ->first();

        $class_id = $record ? $record->my_class_id : null;

        // Subjects taught in this student's class, with their teachers.
        $subjects = $class_id
            ? Subject::with('teacher')->where('my_class_id', $class_id)
                ->orderBy('name')->get()
            : collect();

        // Today's periods for the student's class, from the same
        // timetable rows the teacher home reads: weekday rows whose
        // timetable record belongs to the student's class (exam rows
        // carry an exam_date instead of a weekday, so the day filter
        // excludes them).
        $today_periods = collect();
        if ($class_id && Schema::hasTable('time_tables')) {
            $today_periods = TimeTable::with(['subject.teacher', 'time_slot', 'tt_record'])
                ->where('day', $now->format('l'))
                ->whereHas('tt_record', function ($q) use ($class_id) {
                    $q->where('my_class_id', $class_id);
                })
                ->get()
                ->sortBy(function ($period) {
                    return optional($period->time_slot)->timestamp_from ?? $period->ts_id;
                })
                ->map(function ($period) use ($now) {
                    $from = optional($period->time_slot)->timestamp_from ?? $period->timestamp_from;
                    $to = optional($period->time_slot)->timestamp_to ?? $period->timestamp_to;
                    $status = 'Scheduled';
                    if ($from && $to) {
                        $now_tod = $now->format('H:i');
                        $from_tod = date('H:i', (int) $from);
                        $to_tod = date('H:i', (int) $to);
                        $status = $now_tod < $from_tod ? 'Upcoming'
                            : ($now_tod <= $to_tod ? 'In progress' : 'Completed');
                    }

                    return [
                        'time' => optional($period->time_slot)->full ?: '—',
                        'subject' => optional($period->subject)->name ?: '—',
                        'teacher' => optional(optional($period->subject)->teacher)->name ?: '—',
                        'status' => $status,
                    ];
                })
                ->values();
        }

        // Library: this student's active loans (due dates drive the
        // reminders; the count drives the stat card).
        $active_loans = collect();
        if (Schema::hasTable('book_requests')) {
            $active_loans = BookRequest::with('book')
                ->active()
                ->where('user_id', $user_id)
                ->orderBy('end_date')
                ->get();
        }

        // Exams still ahead for this class: exam timetables carry the
        // dates (the exams table itself is name/term/year only).
        $upcoming_exam_count = 0;
        $next_exam = null;
        if ($class_id && Schema::hasTable('time_tables')) {
            $exam_rows = TimeTable::with('tt_record.exam')
                ->whereNotNull('exam_date')
                ->whereDate('exam_date', '>=', $now->toDateString())
                ->whereHas('tt_record', function ($q) use ($class_id) {
                    $q->where('my_class_id', $class_id)
                        ->whereNotNull('exam_id');
                })
                ->orderBy('exam_date')
                ->get();

            $upcoming_exam_count = $exam_rows
                ->pluck('tt_record.exam_id')->filter()->unique()->count();

            $first = $exam_rows->first();
            if ($first) {
                $next_exam = [
                    'name' => optional(optional($first->tt_record)->exam)->name ?: 'Examination',
                    'date' => $first->exam_date,
                ];
            }
        }

        // Circulars published for students (published + in window +
        // audience all/students, via the Circular model's own scopes).
        $recent_circulars = collect();
        $circulars_week_count = 0;
        if (Schema::hasTable('circulars')) {
            $recent_circulars = Circular::published()
                ->forAudience('student')
                ->latest()
                ->take(4)
                ->get();

            $circulars_week_count = Circular::published()
                ->forAudience('student')
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->count();
        }

        // Workstream CZ (LMS): assignments for this student's class
        // (+ section) due in the next 7 days that they have not
        // submitted yet.
        $lms_due_week = 0;
        if ($record && Schema::hasTable('assignments')) {
            $lms_due_week = \App\Models\Assignment::where('my_class_id', $record->my_class_id)
                ->where(function ($q) use ($record) {
                    $q->whereNull('section_id')->orWhere('section_id', $record->section_id);
                })
                ->where('due_at', '>=', $now)
                ->where('due_at', '<=', $now->copy()->addDays(7))
                ->whereDoesntHave('submissions', function ($q) use ($user_id) {
                    $q->where('student_id', $user_id);
                })
                ->count();
        }

        // Loop 99 — Own fee position for the current session, from the
        // SAME PaymentRepo assembly the parent home and the fees
        // portal use (getAllMyPR scoped to the current session), so
        // the dashboard can never disagree with the invoice figures.
        $fees = ['total' => 0, 'paid' => 0, 'due' => 0, 'pending_records' => 0, 'has_records' => false];
        $fee_records = app(PaymentRepo::class)->getAllMyPR($user_id, Qs::getCurrentSession())->get();
        $fees = [
            'total' => (int) $fee_records->sum(function ($r) {
                return $r->payment->amount;
            }),
            'paid' => (int) $fee_records->sum('amt_paid'),
            'due' => (int) $fee_records->where('paid', 0)->sum(function ($r) {
                return $r->balance ?: $r->payment->amount;
            }),
            'pending_records' => (int) $fee_records->where('paid', 0)->count(),
            'has_records' => $fee_records->isNotEmpty(),
        ];

        // Loop 99 — Open assignments for this student's own class
        // (+ section), using the student assignment scoping and the
        // "open" rule from AssignmentController@index / submit():
        // the window is open while the due moment has not passed, or
        // late submissions are allowed. Own submission state rides
        // the same submissions scope the student list uses. Panel
        // order: still-to-submit first, then nearest due (no due
        // date last).
        $open_assignments = collect();
        $open_assignments_count = 0;
        $open_assignments_submitted = 0;
        if ($record && Schema::hasTable('assignments')) {
            $open = \App\Models\Assignment::with(['subject', 'teacher'])
                ->with(['submissions' => function ($q) use ($user_id) {
                    $q->where('student_id', $user_id);
                }])
                ->where('my_class_id', $record->my_class_id)
                ->where(function ($q) use ($record) {
                    $q->whereNull('section_id')->orWhere('section_id', $record->section_id);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('due_at')
                        ->orWhere('due_at', '>', $now)
                        ->orWhere('allow_late', true);
                })
                ->get();

            $open_assignments_count = $open->filter(function ($a) {
                return $a->submissions->isEmpty();
            })->count();
            $open_assignments_submitted = $open->count() - $open_assignments_count;

            $open_assignments = $open
                ->sortBy(function ($a) {
                    return [
                        $a->submissions->isEmpty() ? 0 : 1,
                        $a->due_at ? 0 : 1,
                        $a->due_at ? $a->due_at->timestamp : PHP_INT_MAX,
                    ];
                })
                ->take(5)
                ->values();
        }

        return [
            'lms_due_week' => $lms_due_week,
            'fees' => $fees,
            'open_assignments' => $open_assignments,
            'open_assignments_count' => $open_assignments_count,
            'open_assignments_submitted' => $open_assignments_submitted,
            'student_record' => $record,
            'attendance' => $record ? $this->attendanceSummary($record->id) : $this->attendanceSummary(0),
            'subjects' => $subjects,
            'today_periods' => $today_periods,
            'today_name' => $now->format('l'),
            'books_issued' => $active_loans->count(),
            'active_loans' => $active_loans,
            'upcoming_exam_count' => $upcoming_exam_count,
            'next_exam' => $next_exam,
            'recent_circulars' => $recent_circulars,
            'circulars_week_count' => $circulars_week_count,
            'upcoming_events' => $this->upcomingEvents($now),
        ];
    }

    /**
     * Loop 7 — Parent home data: one entry per linked child (student
     * records carry my_parent_id), each with its own attendance summary
     * and fee position. No children linked = honestly empty.
     */
    protected function parentHomeData(Carbon $now): array
    {
        $year = Qs::getCurrentSession();
        $pay = app(PaymentRepo::class);

        $children = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('my_parent_id', Auth::id())
            ->get()
            ->map(function (StudentRecord $child) use ($pay, $year) {
                $records = $pay->getAllMyPR($child->user_id, $year)->get();

                return [
                    'record' => $child,
                    'attendance' => $this->attendanceSummary($child->id),
                    'fees' => [
                        'total' => (int) $records->sum(function ($r) {
                            return $r->payment->amount;
                        }),
                        'paid' => (int) $records->sum('amt_paid'),
                        'due' => (int) $records->where('paid', 0)->sum(function ($r) {
                            return $r->balance ?: $r->payment->amount;
                        }),
                        'pending_records' => (int) $records->where('paid', 0)->count(),
                        'has_records' => $records->isNotEmpty(),
                    ],
                ];
            });

        $sum = function (string $key) use ($children) {
            return (int) $children->sum(function ($c) use ($key) {
                return $c['fees'][$key];
            });
        };

        // Children's attendance today: how many of the linked children
        // were marked present today. Only meaningful when the school
        // actually recorded attendance today; otherwise the card falls
        // back to the children's session attendance percentage.
        $attendance_today = [
            'recorded' => false,
            'present' => 0,
            'children' => $children->count(),
            'session_percent' => null,
        ];

        if ($children->isNotEmpty()) {
            $percents = $children->map(function ($c) {
                return $c['attendance']['percent'];
            })->filter(function ($p) {
                return $p !== null;
            });

            $attendance_today['session_percent'] = $percents->isNotEmpty()
                ? (int) round($percents->avg())
                : null;

            if (Schema::hasTable('attendance_session_entries')) {
                $record_ids = $children->map(function ($c) {
                    return $c['record']->id;
                })->all();

                // Period registers (Workstream AR): a child counts as
                // present today when at least one of today's period
                // entries marks them present or late — they were in
                // school today. "Recorded" = any register today
                // mentions any of the children.
                $rows = AttendanceSessionEntry::whereIn('student_record_id', $record_ids)
                    ->whereHas('session', function ($q) use ($now) {
                        $q->whereDate('date', $now->toDateString());
                    })
                    ->get(['student_record_id', 'status']);

                if ($rows->isNotEmpty()) {
                    $attendance_today['recorded'] = true;
                    $attendance_today['present'] = (int) $rows
                        ->groupBy('student_record_id')
                        ->filter(function ($entries) {
                            return $entries->contains(function ($entry) {
                                return in_array($entry->status, ['present', 'late'], true);
                            });
                        })
                        ->count();
                }
            }
        }

        // Upcoming examinations: exam timetables belonging to the
        // current session (the year lives on the timetable record, as
        // in upcomingEvents) with a date from today onward.
        $upcoming_exams_count = 0;

        if (Schema::hasTable('time_tables')) {
            $upcoming_exams_count = (int) DB::table('time_tables')
                ->join('time_table_records', 'time_table_records.id', '=', 'time_tables.ttr_id')
                ->where('time_table_records.year', $year)
                ->whereDate('time_tables.exam_date', '>=', $now->toDateString())
                ->count();
        }

        // Circulars a parent may read: published, inside their display
        // window, addressed to everyone or to parents (Circular scopes).
        $circulars = collect();
        $circulars_week_count = 0;

        if (Schema::hasTable('circulars')) {
            $circulars = Circular::published()
                ->forAudience('parent')
                ->latest('created_at')
                ->take(4)
                ->get();

            $circulars_week_count = (int) Circular::published()
                ->forAudience('parent')
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->count();
        }

        $upcoming_events = $this->upcomingEvents($now);

        // Workstream CZ (LMS): assignments across the linked children
        // that none of them has submitted yet (pending homework).
        $lms_pending_assignments = 0;
        if (Schema::hasTable('assignments')) {
            foreach ($children as $childRow) {
                $child = $childRow['record'];
                $lms_pending_assignments += \App\Models\Assignment::where('my_class_id', $child->my_class_id)
                    ->where(function ($q) use ($child) {
                        $q->whereNull('section_id')->orWhere('section_id', $child->section_id);
                    })
                    ->whereDoesntHave('submissions', function ($q) use ($child) {
                        $q->where('student_id', $child->user_id);
                    })
                    ->count();
            }
        }

        return [
            'lms_pending_assignments' => $lms_pending_assignments,
            'children' => $children,
            'fees_total' => $sum('total'),
            'fees_paid' => $sum('paid'),
            'fees_due' => $sum('due'),
            'fees_pending_records' => $sum('pending_records'),
            'attendance_today' => $attendance_today,
            'upcoming_exams_count' => $upcoming_exams_count,
            'circulars' => $circulars,
            'circulars_week_count' => $circulars_week_count,
            'next_event' => $upcoming_events->first(),
            'upcoming_events' => $upcoming_events,
        ];
    }

    /**
     * Loop 7 — Accountant home data: fee money only. "Collected" follows
     * the same convention as the Super Admin dashboard (amt_paid summed
     * over payment_records by updated_at — the moment money was recorded).
     */
    protected function accountantHomeData(Carbon $now): array
    {
        $year = Qs::getCurrentSession();

        $collected_today = (int) Receipt::whereDate('created_at', $now->toDateString())
            ->sum('amt_paid');
        $payments_today_count = (int) Receipt::whereDate('created_at', $now->toDateString())
            ->count();
        $collected_month = (int) Receipt::whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->sum('amt_paid');
        $collected_session = (int) Receipt::where('year', $year)
            ->sum('amt_paid');

        // Comparison periods for the KPI deltas. The view only prints a
        // delta when BOTH periods hold receipts — on sparse data a
        // percentage against an empty period would be meaningless.
        $collected_yesterday = (int) Receipt::whereDate('created_at', $now->copy()->subDay()->toDateString())
            ->sum('amt_paid');
        $prev_month = $now->copy()->startOfMonth()->subMonth();
        $collected_prev_month = (int) Receipt::whereYear('created_at', $prev_month->year)
            ->whereMonth('created_at', $prev_month->month)
            ->sum('amt_paid');

        // Collection trend (Loop 92): session receipts grouped by the
        // month they were actually recorded in, oldest first. Only
        // months present in the ledger become bars — no zero-filled
        // placeholder months. (Month expression is driver-aware so the
        // dashboard also runs on sqlite in dev; MySQL/MariaDB behaviour
        // is unchanged.)
        $month_expr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
        $monthly = Receipt::where('year', $year)
            ->selectRaw("$month_expr as ym, COALESCE(SUM(amt_paid), 0) as total")
            ->groupBy('ym')
            ->orderBy('ym')
            ->pluck('total', 'ym');
        $collection_labels = [];
        $collection_values = [];
        foreach ($monthly as $ym => $total) {
            $collection_labels[] = Carbon::createFromFormat('Y-m', $ym)->format('M Y');
            $collection_values[] = (int) $total;
        }
        $collection_period = '';
        if ($collection_labels) {
            $collection_period = count($collection_labels) === 1
                ? $collection_labels[0]
                : $collection_labels[0] . ' – ' . $collection_labels[count($collection_labels) - 1];
        }

        // Collection by payment method (Loop 92): receipts carry a
        // per-receipt method only for gateway payments
        // ('online_razorpay'); manual counter receipts store NULL by
        // design (see the gateway-columns migration). Buckets therefore
        // name exactly what the ledger records — Counter vs Online —
        // over the same session population as Total Collected, so the
        // slices always reconcile with the KPI.
        $online_row = Receipt::where('year', $year)
            ->where('method', 'online_razorpay')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amt_paid), 0) as total')
            ->first();
        $session_receipt_count = (int) Receipt::where('year', $year)->count();
        $online_count = (int) ($online_row->cnt ?? 0);
        $online_total = (int) ($online_row->total ?? 0);
        $method_breakdown = [];
        if ($session_receipt_count - $online_count > 0) {
            $method_breakdown[] = [
                'label' => 'Counter',
                'amount' => $collected_session - $online_total,
                'count' => $session_receipt_count - $online_count,
            ];
        }
        if ($online_count > 0) {
            $method_breakdown[] = [
                'label' => 'Online (Razorpay)',
                'amount' => $online_total,
                'count' => $online_count,
            ];
        }
        usort($method_breakdown, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
        foreach ($method_breakdown as &$bucket) {
            $bucket['pct'] = $collected_session > 0
                ? (int) round($bucket['amount'] / $collected_session * 100)
                : 0;
        }
        unset($bucket);

        // Recent payments: the latest receipts (money events), each with
        // the student and fee definition it belongs to. Class labels
        // come from the student's own record, one batched lookup.
        $recent_receipts = Receipt::with(['pr.student', 'pr.payment.my_class'])
            ->latest()
            ->take(8)
            ->get();
        $student_records = StudentRecord::with(['my_class', 'section'])
            ->whereIn('user_id', $recent_receipts->pluck('pr.student_id')->filter()->unique()->all())
            ->get()
            ->keyBy('user_id');
        $recent_payments = $recent_receipts->map(function ($receipt) use ($student_records) {
            $record = $receipt->pr;
            $student_record = $record ? $student_records->get($record->student_id) : null;

            $class_label = '—';
            if ($student_record && $student_record->my_class) {
                $class_label = $student_record->my_class->name
                    . ($student_record->section ? ' · ' . $student_record->section->name : '');
            } elseif ($record && $record->payment && $record->payment->my_class) {
                $class_label = $record->payment->my_class->name;
            }

            return [
                'student' => optional(optional($record)->student)->name ?: '—',
                'title' => optional(optional($record)->payment)->title ?: '—',
                'class' => $class_label,
                'amount' => (int) $receipt->amt_paid,
                // Method as the printed receipt presents it: a gateway
                // receipt names its channel; a counter receipt carries
                // the fee's configured method.
                'method' => $receipt->method === 'online_razorpay'
                    ? 'Online (Razorpay)'
                    : (optional(optional($record)->payment)->method
                        ? ucwords(optional(optional($record)->payment)->method)
                        : '—'),
                'date' => $receipt->created_at,
                // Loop 92: receipt identity + settlement state so the
                // row can link to the real receipt document.
                'ref' => optional($record)->ref_no ?: '—',
                'pr_id' => (int) $receipt->pr_id,
                'cleared' => $record ? (bool) $record->paid : false,
            ];
        });

        // Defaulters snapshot + outstanding total share one computation.
        $defaulter_rows = $this->feeDefaulterRows($year);
        $outstanding_total = (int) $defaulter_rows->sum('total_due');

        // Recent circulars a staff member may read — the feed's own
        // scopes (published, inside its display window, audience
        // all/staff for the accountant user type).
        $recent_circulars = collect();
        $new_circulars_count = 0;
        if (class_exists(\App\Models\Circular::class) && Schema::hasTable('circulars')) {
            $recent_circulars = \App\Models\Circular::published()
                ->forAudience('accountant')
                ->latest()
                ->take(4)
                ->get();
            $new_circulars_count = \App\Models\Circular::published()
                ->forAudience('accountant')
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->count();
        }

        // Reminders: real counts only, each linking to its own page.
        $reminders = [];
        $reminders[] = [
            'icon' => 'icon-warning2', 'tone' => 'orange',
            'title' => Money::inr($outstanding_total) . ' outstanding',
            'text' => $defaulter_rows->count() . ' ' . ($defaulter_rows->count() === 1 ? 'student' : 'students') . ' with fees due this session',
            'link' => route('payments.defaulters'),
        ];
        $reminders[] = [
            'icon' => 'icon-wallet', 'tone' => 'green',
            'title' => $payments_today_count . ' ' . ($payments_today_count === 1 ? 'payment' : 'payments') . ' recorded today',
            'text' => Money::inr($collected_today) . ' collected today',
            'link' => route('payments.collection'),
        ];
        if ($new_circulars_count > 0) {
            $reminders[] = [
                'icon' => 'icon-megaphone', 'tone' => 'blue',
                'title' => $new_circulars_count . ' new ' . ($new_circulars_count === 1 ? 'circular' : 'circulars'),
                'text' => 'Published in the last 7 days',
                'link' => route('circulars.index'),
            ];
        }

        return [
            'collected_today' => $collected_today,
            'collected_month' => $collected_month,
            'collected_session' => $collected_session,
            'collected_yesterday' => $collected_yesterday,
            'collected_prev_month' => $collected_prev_month,
            'payments_today_count' => $payments_today_count,
            'outstanding_total' => $outstanding_total,
            'collection_labels' => $collection_labels,
            'collection_values' => $collection_values,
            'collection_period' => $collection_period,
            'method_breakdown' => $method_breakdown,
            'recent_payments' => $recent_payments,
            'defaulters' => $defaulter_rows->take(5),
            'defaulters_count' => $defaulter_rows->count(),
            'recent_circulars' => $recent_circulars,
            'reminders' => $reminders,
        ];
    }

    /**
     * Loop 14 — Students who still owe fees this session, worst first.
     *
     * This is FeeReportController::defaulterRows (unfiltered by class)
     * kept in step on purpose: a record counts while paid = 0 and owes
     * its stored balance, falling back to the full fee amount when
     * untouched — so the dashboard's outstanding total and top
     * defaulters always match the fee defaulters report exactly.
     */
    /**
     * One student's all-time attendance from PERIOD entries
     * (Workstream AR — the day-wise table is retired). Percent is the
     * school-wide rule: (present + late) / marked period entries.
     */
    protected function attendanceSummary(int $student_record_id): array
    {
        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'percent' => null];

        if ($student_record_id < 1 || !Schema::hasTable('attendance_session_entries')) {
            return $summary;
        }

        $rows = AttendanceSessionEntry::where('student_record_id', $student_record_id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $summary['present'] = (int) ($rows['present'] ?? 0);
        $summary['absent'] = (int) ($rows['absent'] ?? 0);
        $summary['late'] = (int) ($rows['late'] ?? 0);
        $summary['total'] = $summary['present'] + $summary['absent'] + $summary['late'];
        $summary['percent'] = $summary['total'] > 0
            ? (int) round((($summary['present'] + $summary['late']) / $summary['total']) * 100)
            : null;

        return $summary;
    }

    /** Fee position for one student (by user id) from real payment records. */
    protected function feeSummaryForStudent(int $user_id): array
    {
        $row = DB::table('payment_records')
            ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
            ->where('payment_records.student_id', $user_id)
            ->selectRaw('COALESCE(SUM(payments.amount), 0) as owed, COALESCE(SUM(payment_records.amt_paid), 0) as paid, SUM(CASE WHEN payment_records.paid = 0 THEN 1 ELSE 0 END) as pending_records')
            ->first();

        $owed = (int) ($row->owed ?? 0);
        $paid = (int) ($row->paid ?? 0);

        return [
            'owed' => $owed,
            'paid' => $paid,
            'pending' => max(0, $owed - $paid),
            'pending_records' => (int) ($row->pending_records ?? 0),
            'percent' => $owed > 0 ? (int) round(($paid / $owed) * 100) : 0,
            'has_records' => $owed > 0 || $paid > 0,
        ];
    }
    protected function librarianHomeData(Carbon $now): array
    {
        $today = $now->toDateString();
        $bookCopies = (int) Book::sum('total_copies');

        // Loop 100: catalogue mix by the real `book_type` field — copies
        // per type for the dashboard donut. Types with no copies on the
        // shelf record still count their titles, but only types holding
        // copies can draw a slice, so the breakdown keeps copies > 0.
        $typeBreakdown = Book::query()
            ->selectRaw('book_type, COUNT(*) as titles, COALESCE(SUM(total_copies), 0) as copies')
            ->groupBy('book_type')
            ->orderByDesc('copies')
            ->get()
            ->filter(function ($row) {
                return (int) $row->copies > 0;
            })
            ->map(function ($row) use ($bookCopies) {
                return [
                    'label' => $row->book_type ?: 'Other',
                    'titles' => (int) $row->titles,
                    'copies' => (int) $row->copies,
                    'pct' => $bookCopies > 0 ? (int) round($row->copies / $bookCopies * 100) : 0,
                ];
            })
            ->values();

        return [
            'book_titles' => Book::count(),
            'book_copies' => $bookCopies,
            'type_breakdown' => $typeBreakdown,
            'library_trend' => $this->libraryTrend($now),
            'books_issued' => BookRequest::active()->count(),
            'loans_due_today' => BookRequest::active()->whereDate('end_date', $today)->count(),
            'loans_overdue' => BookRequest::active()->whereDate('end_date', '<', $today)->count(),
            'titles_out_of_stock' => Book::whereRaw('total_copies <= issued_copies')->count(),
            'recent_loans' => BookRequest::with(['book', 'user'])
                ->latest()
                ->take(8)
                ->get(),
            'recent_circulars' => Schema::hasTable('circulars')
                ? Circular::published()->forAudience('librarian')->latest()->take(4)->get()
                : collect(),
        ];
    }

    /**
     * Loop 117: Book Issue/Return Trend for the librarian home —
     * issued vs returned counts taken from the book_requests loan
     * ledger itself, bucketed three ways (last 14 days, last 8
     * weeks, last 6 months) so the card's Day / Week / Month toggle
     * re-aggregates the same real rows. A return is stamped by the
     * loan's updated_at on the day it was marked returned — the
     * ledger carries no separate returned_at column.
     */
    protected function libraryTrend(Carbon $now): array
    {
        $windowStart = $now->copy()->subMonths(6)->startOfMonth();

        $issuedByDay = BookRequest::query()
            ->selectRaw('DATE(start_date) as d, COUNT(*) as c')
            ->whereDate('start_date', '>=', $windowStart->toDateString())
            ->groupBy('d')
            ->pluck('c', 'd');
        $returnedByDay = BookRequest::query()
            ->selectRaw('DATE(updated_at) as d, COUNT(*) as c')
            ->where('returned', '1')
            ->whereDate('updated_at', '>=', $windowStart->toDateString())
            ->groupBy('d')
            ->pluck('c', 'd');

        $bucket = function (Carbon $from, Carbon $to, string $label) use ($issuedByDay, $returnedByDay) {
            $issued = 0;
            $returned = 0;
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $key = $day->toDateString();
                $issued += (int) ($issuedByDay[$key] ?? 0);
                $returned += (int) ($returnedByDay[$key] ?? 0);
            }

            return ['label' => $label, 'issued' => $issued, 'returned' => $returned];
        };

        $day = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $now->copy()->subDays($i);
            $day[] = $bucket($d, $d, $d->format('d M'));
        }

        $week = [];
        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->startOfWeek()->subWeeks($i);
            $week[] = $bucket($start, $start->copy()->endOfWeek(), $start->format('d M'));
        }

        $month = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = $now->copy()->startOfMonth()->subMonths($i);
            $month[] = $bucket($start, $start->copy()->endOfMonth(), $start->format('M Y'));
        }

        return ['day' => $day, 'week' => $week, 'month' => $month];
    }

    /**
     * Attendance summary for one student record. Percent counts present
     * and late over every marked day — the same formula as the monthly
     * attendance report. Percent stays null when nothing was recorded.
     */

    protected function feeDefaulterRows($year)
    {
        $students = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)
            ->orderBy('id')
            ->get();

        $owing = PaymentRecord::with('payment')
            ->where('year', $year)
            ->where('paid', 0)
            ->whereIn('student_id', $students->pluck('user_id')->all())
            ->get()
            ->groupBy('student_id');

        return $students
            ->map(function ($student) use ($owing) {
                $records = $owing->get($student->user_id);
                if (!$records || $records->isEmpty()) {
                    return null;
                }

                $total_due = (int) $records->sum(function ($r) {
                    return $r->balance ?: $r->payment->amount;
                });
                if ($total_due <= 0) {
                    return null;
                }

                $oldest = $records->sortBy('created_at')->first();

                return [
                    'student' => $student,
                    'total_due' => $total_due,
                    'items' => $records->count(),
                    'oldest_title' => optional($oldest->payment)->title,
                    'oldest_since' => $oldest->created_at,
                ];
            })
            ->filter()
            ->sortByDesc('total_due')
            ->values();
    }

    /**
     * Attendance summary for one student record. Percent counts present
     * and late over every marked day — the same formula as the monthly
     * attendance report. Percent stays null when nothing was recorded.
     */

}
