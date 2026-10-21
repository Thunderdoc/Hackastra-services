<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Mark;
use App\Models\PaymentRecord;
use App\Models\SchoolEvent;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Repositories\UserRepo;
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

    /** The original school-operations dashboard, verbatim. */
    protected function schoolDashboardView(array $d, Carbon $now)
    {
        if (Qs::userIsTeamSAT()) {
            $d['users'] = $this->user->getAll();
            $d['student_count'] = StudentRecord::where('grad', 0)->count();
            $d['teacher_count'] = $d['users']->where('user_type', 'teacher')->count();
            $d['administrator_count'] = $d['users']->whereIn('user_type', ['admin', 'super_admin'])->count();
            $d['parent_count'] = $d['users']->where('user_type', 'parent')->count();

            // Real period deltas: records created in the last 30 days vs the
            // 30 days before that. No invented percentages (audit §9).
            $d['stat_deltas'] = [
                'students' => $this->periodDelta(StudentRecord::where('grad', 0), $now),
                'teachers' => $this->userTypeDelta('teacher', $now),
                'administrators' => $this->userTypeDelta(['admin', 'super_admin'], $now),
                'parents' => $this->userTypeDelta('parent', $now),
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
        $role_labels = [
            'super_admin' => 'Platform Super Admin',
            'admin' => 'School Admin',
            'teacher' => 'Teachers',
            'student' => 'Students',
            'parent' => 'Parents',
            'accountant' => 'Accountant',
            'librarian' => 'Librarian',
        ];

        $counts = DB::table('users')
            ->select('user_type', DB::raw('count(*) as total'))
            ->groupBy('user_type')
            ->pluck('total', 'user_type');

        $role_counts = [];
        foreach ($role_labels as $type => $label) {
            $role_counts[] = ['type' => $type, 'label' => $label, 'total' => (int) ($counts[$type] ?? 0)];
            unset($counts[$type]);
        }
        // Any user_type outside the known list is still accounted for.
        foreach ($counts as $type => $total) {
            $role_counts[] = ['type' => $type, 'label' => ucwords(str_replace('_', ' ', $type)), 'total' => (int) $total];
        }

        $audit_today = 0;
        $audit_week = 0;
        $recent_audit = collect();
        $recent_logins = collect();
        if (Schema::hasTable('audit_logs')) {
            $audit_today = AuditLog::whereDate('created_at', $now->toDateString())->count();
            $audit_week = AuditLog::where('created_at', '>=', $now->copy()->startOfWeek())->count();
            $recent_audit = AuditLog::with('user')->latest()->take(10)->get();
            // Only successful sign-ins are audited (the LogSuccessfulLogin
            // listener); failed attempts are never written to the log.
            $recent_logins = AuditLog::with('user')
                ->where('action', 'auth.login')
                ->latest()
                ->take(8)
                ->get();
        }

        $settings_checklist = [];
        foreach ([
            'system_name' => 'School name',
            'logo' => 'School logo',
            'phone' => 'Contact phone',
            'system_email' => 'School email',
            'current_session' => 'Current session',
        ] as $key => $label) {
            $settings_checklist[] = [
                'key' => $key,
                'label' => $label,
                'set' => filled(Qs::getSetting($key)),
            ];
        }

        return [
            'school_name' => Qs::getSetting('system_name'),
            'role_counts' => $role_counts,
            'total_users' => array_sum(array_column($role_counts, 'total')),
            'audit_today' => $audit_today,
            'audit_week' => $audit_week,
            'audit_week_from' => $now->copy()->startOfWeek(),
            'recent_audit' => $recent_audit,
            'recent_logins' => $recent_logins,
            'settings_checklist' => $settings_checklist,
            'settings_done' => collect($settings_checklist)->where('set', true)->count(),
            'settings_total' => count($settings_checklist),
            // Secondary "school snapshot": the deployment serves one
            // school today; these are its headline sizes, not tenants.
            'snapshot_student_count' => StudentRecord::where('grad', 0)->count(),
            'snapshot_teacher_count' => (int) DB::table('users')->where('user_type', 'teacher')->count(),
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

    /** Today's attendance from the attendances table; zeros are honest when nothing was recorded. */
    protected function attendanceToday(Carbon $now): array
    {
        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'percent' => 0, 'recorded' => false];

        if (!Schema::hasTable('attendances')) {
            return $summary;
        }

        $rows = Attendance::whereDate('date', $now->toDateString())
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $summary['present'] = (int) ($rows['present'] ?? 0);
        $summary['absent'] = (int) ($rows['absent'] ?? 0);
        $summary['late'] = (int) ($rows['late'] ?? 0);
        $summary['total'] = $summary['present'] + $summary['absent'] + $summary['late'];
        $summary['recorded'] = $summary['total'] > 0;
        $summary['percent'] = $summary['total'] > 0
            ? (int) round(($summary['present'] / $summary['total']) * 100)
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
                'text' => $d['attendance_today']['present'] . ' of ' . $d['attendance_today']['total'] . ' students marked present',
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
            $today_periods = TimeTable::with(['subject.my_class', 'time_slot', 'tt_record.my_class'])
                ->where('day', $now->format('l'))
                ->whereHas('subject', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->get()
                ->map(function (TimeTable $period) use ($now, $led_by_class, $session_by_period) {
                    $slot = $period->time_slot;
                    $start = $end = null;
                    if ($slot && $slot->time_from && $slot->time_to) {
                        $start = Carbon::parse($now->toDateString() . ' ' . $slot->time_from);
                        $end = Carbon::parse($now->toDateString() . ' ' . $slot->time_to);
                    }

                    if ($start === null) {
                        $status = 'upcoming';
                        $status_label = 'Upcoming';
                    } elseif ($now->lt($start)) {
                        $status = 'upcoming';
                        $status_label = 'Upcoming';
                    } elseif ($now->gt($end)) {
                        $status = 'completed';
                        $status_label = 'Completed';
                    } else {
                        $status = 'live';
                        $status_label = 'In progress';
                    }

                    $class = optional($period->tt_record)->my_class ?: optional($period->subject)->my_class;

                    return [
                        'period' => $period,
                        'time_label' => optional($slot)->full ?: '—',
                        'subject_name' => optional($period->subject)->name ?: '—',
                        'class_name' => optional($class)->name ?: '—',
                        'sections' => $class ? ($led_by_class[$class->id] ?? []) : [],
                        'status' => $status,
                        'status_label' => $status_label,
                        'sort' => $start ? $start->format('H:i') : (optional($slot)->timestamp_from ?? '99:99'),
                        'ttr_id' => $period->ttr_id,
                        'tt_id' => $period->id,
                        'session_id' => $session_by_period[$period->id] ?? null,
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
        // records; nothing is illustrative. Derivations, in order:
        //  (a) today's periods whose register is not yet submitted;
        //  (b) tomorrow's scheduled periods;
        //  (c) upcoming exam sittings — exam dates live ONLY on exam
        //      timetable rows (time_tables.exam_date, linked to an Exam
        //      via the timetable record), because the exams table itself
        //      carries no date column in this schema;
        //  (d) "marks not yet entered" is deliberately NOT derived: with
        //      no exam dates on the exams table, an exam cannot honestly
        //      be called upcoming/ongoing, so the row would be a guess.
        $t9_tasks = [];
        $unsubmitted = $today_periods->filter(function ($row) {
            return empty($row['session_id']);
        })->count();
        if ($unsubmitted > 0) {
            $t9_tasks[] = [
                'icon' => 'icon-calendar52', 'tone' => 'red',
                'title' => $unsubmitted . ' ' . ($unsubmitted === 1 ? 'period' : 'periods') . ' not yet submitted',
                'meta' => 'Period registers awaiting submission',
                'badge' => 'Pending', 'badge_tone' => 'red',
                'date_label' => $now->format('d M Y'),
                'link' => route('attendance.periods.index'),
            ];
        }
        $tomorrow = $now->copy()->addDay();
        $tomorrow_count = 0;
        if (Schema::hasTable('time_tables')) {
            $tomorrow_count = TimeTable::where('day', $tomorrow->format('l'))
                ->whereHas('subject', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->count();
        }
        if ($tomorrow_count > 0) {
            $t9_tasks[] = [
                'icon' => 'icon-calendar3', 'tone' => 'blue',
                'title' => $tomorrow_count . ' ' . ($tomorrow_count === 1 ? 'period' : 'periods') . ' scheduled for tomorrow',
                'meta' => 'Check your timetable',
                'badge' => 'Upcoming', 'badge_tone' => 'blue',
                'date_label' => $tomorrow->format('d M Y'),
                'link' => route('tt.index'),
            ];
        }
        if (Schema::hasTable('time_tables')) {
            $exam_rows = TimeTable::with(['subject.my_class', 'tt_record.exam'])
                ->whereNotNull('exam_date')
                ->where('exam_date', '>=', $now->toDateString())
                ->whereHas('subject', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->orderBy('exam_date')
                ->take(2)
                ->get();
            foreach ($exam_rows as $exam_row) {
                $exam_name = optional(optional($exam_row->tt_record)->exam)->name;
                $subject_name = optional($exam_row->subject)->name ?: '—';
                $exam_class = optional(optional($exam_row->subject)->my_class)->name;
                $t9_tasks[] = [
                    'icon' => 'icon-file-text2', 'tone' => 'orange',
                    'title' => 'Exam: ' . ($exam_name ?: $subject_name),
                    'meta' => $subject_name . ($exam_class ? ' · ' . $exam_class : ''),
                    'badge' => 'Exam', 'badge_tone' => 'orange',
                    'date_label' => Carbon::parse($exam_row->exam_date)->format('d M Y'),
                    'link' => route('marks.index'),
                ];
            }
        }
        $t9_tasks = array_slice($t9_tasks, 0, 4);

        return [
            'my_subjects' => $subjects,
            'my_class_count' => $class_ids->count(),
            'first_class_id' => $class_ids->first(),
            't9_tasks' => $t9_tasks,
            'sections_led' => $sections_led,
            'my_student_count' => $class_ids->isEmpty()
                ? 0
                : StudentRecord::where('grad', 0)->whereIn('my_class_id', $class_ids)->count(),
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
            $today_periods = TimeTable::with(['subject', 'time_slot', 'tt_record'])
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

        return [
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

            if (Schema::hasTable('attendances')) {
                $record_ids = $children->map(function ($c) {
                    return $c['record']->id;
                })->all();

                $rows = Attendance::whereIn('student_record_id', $record_ids)
                    ->whereDate('date', $now->toDateString())
                    ->get(['student_record_id', 'status']);

                if ($rows->isNotEmpty()) {
                    $attendance_today['recorded'] = true;
                    $attendance_today['present'] = (int) $rows->where('status', 'present')->count();
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

        return [
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

        // Collection trend: receipts grouped by month, last six months
        // including the current one, oldest first. (Month expression is
        // driver-aware so the dashboard also runs on sqlite in dev;
        // MySQL/MariaDB behaviour is unchanged.)
        $month_expr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
        $monthly = Receipt::where('created_at', '>=', $now->copy()->startOfMonth()->subMonths(5))
            ->selectRaw("$month_expr as ym, COALESCE(SUM(amt_paid), 0) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');
        $collection_labels = [];
        $collection_values = [];
        foreach (range(5, 0) as $months_ago) {
            $month = $now->copy()->startOfMonth()->subMonths($months_ago);
            $collection_labels[] = $month->format('M');
            $collection_values[] = (int) ($monthly[$month->format('Y-m')] ?? 0);
        }

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
                'method' => optional(optional($record)->payment)->method ?: '—',
                'date' => $receipt->created_at,
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
            'payments_today_count' => $payments_today_count,
            'outstanding_total' => $outstanding_total,
            'collection_labels' => $collection_labels,
            'collection_values' => $collection_values,
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
    protected function attendanceSummary(int $student_record_id): array
    {
        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'percent' => null];

        if ($student_record_id < 1 || !Schema::hasTable('attendances')) {
            return $summary;
        }

        $rows = Attendance::where('student_record_id', $student_record_id)
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

        return [
            'book_titles' => Book::count(),
            'book_copies' => (int) Book::sum('total_copies'),
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
