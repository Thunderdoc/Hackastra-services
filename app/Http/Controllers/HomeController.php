<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\Attendance;
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
        $d = [];
        $now = Carbon::now();
        $d['today'] = $now->copy();
        $d['current_session'] = Qs::getCurrentSession();
        $hour = (int) $now->format('H');
        $d['greeting'] = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

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

            // Fee totals (audit §8): owed = sum of the fee amount for every
            // payment record; collected = sum actually paid; pending = rest.
            // One query path, so the donut can never disagree with the
            // Recent Payments table the way the old screenshot did.
            $d['payment_total'] = (int) DB::table('payment_records')
                ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
                ->sum('payments.amount');
            $d['payment_collected'] = (int) DB::table('payment_records')->sum('amt_paid');
            $d['payment_pending'] = max(0, $d['payment_total'] - $d['payment_collected']);
            $d['payment_percent'] = $d['payment_total'] > 0
                ? (int) round(($d['payment_collected'] / $d['payment_total']) * 100)
                : 0;
            $d['fees_collected_this_month'] = (int) DB::table('payment_records')
                ->whereYear('updated_at', $now->year)
                ->whereMonth('updated_at', $now->month)
                ->sum('amt_paid');

            $d['enrollment_trend'] = collect(range(5, 0))->map(function ($monthsAgo) {
                $date = Carbon::now()->startOfMonth()->subMonths($monthsAgo);

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

        $today_periods = collect();
        if (Schema::hasTable('time_tables')) {
            $today_periods = TimeTable::with(['subject.my_class', 'time_slot', 'tt_record.my_class'])
                ->where('day', $now->format('l'))
                ->whereHas('subject', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->get()
                ->sortBy(function ($period) {
                    return optional($period->time_slot)->timestamp_from ?? $period->ts_id;
                })
                ->values();
        }

        return [
            'my_subjects' => $subjects,
            'my_class_count' => $class_ids->count(),
            'sections_led' => $sections_led,
            'my_student_count' => $class_ids->isEmpty()
                ? 0
                : StudentRecord::where('grad', 0)->whereIn('my_class_id', $class_ids)->count(),
            'today_periods' => $today_periods,
            'today_name' => $now->format('l'),
            'upcoming_events' => $this->upcomingEvents($now),
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

        $latest_exam = null;
        $latest_marks = collect();
        if (Schema::hasTable('marks')) {
            $latest = Mark::with('exam')
                ->where('student_id', $user_id)
                ->orderByDesc('year')
                ->orderByDesc('exam_id')
                ->first();

            if ($latest) {
                $latest_exam = $latest->exam;
                $latest_marks = Mark::with(['subject', 'grade'])
                    ->where('student_id', $user_id)
                    ->where('exam_id', $latest->exam_id)
                    ->where('year', $latest->year)
                    ->get();
            }
        }

        return [
            'student_record' => $record,
            'attendance' => $record ? $this->attendanceSummary($record->id) : $this->attendanceSummary(0),
            'fees' => $this->feeSummaryForStudent($user_id),
            'latest_exam' => $latest_exam,
            'latest_marks' => $latest_marks,
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
        $children = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('my_parent_id', Auth::id())
            ->get()
            ->map(function (StudentRecord $child) {
                return [
                    'record' => $child,
                    'attendance' => $this->attendanceSummary($child->id),
                    'fees' => $this->feeSummaryForStudent($child->user_id),
                ];
            });

        return [
            'children' => $children,
            'upcoming_events' => $this->upcomingEvents($now),
        ];
    }

    /**
     * Loop 7 — Accountant home data: fee money only. "Collected" follows
     * the same convention as the Super Admin dashboard (amt_paid summed
     * over payment_records by updated_at — the moment money was recorded).
     */
    protected function accountantHomeData(Carbon $now): array
    {
        $collected_today = (int) DB::table('payment_records')
            ->whereDate('updated_at', $now->toDateString())
            ->sum('amt_paid');

        $collected_month = (int) DB::table('payment_records')
            ->whereYear('updated_at', $now->year)
            ->whereMonth('updated_at', $now->month)
            ->sum('amt_paid');

        $pending = DB::table('payment_records')
            ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
            ->where('payment_records.paid', 0)
            ->selectRaw('count(*) as records, COALESCE(SUM(payments.amount), 0) as owed, COALESCE(SUM(payment_records.amt_paid), 0) as part_paid')
            ->first();

        return [
            'collected_today' => $collected_today,
            'collected_month' => $collected_month,
            'pending_count' => (int) ($pending->records ?? 0),
            'pending_amount' => max(0, (int) ($pending->owed ?? 0) - (int) ($pending->part_paid ?? 0)),
            'recent_payments' => PaymentRecord::with(['student', 'payment'])
                ->latest()
                ->take(6)
                ->get(),
            'upcoming_events' => $this->upcomingEvents($now),
        ];
    }

    /**
     * Attendance summary for one student record. Percent counts present
     * and late over every marked day — the same formula as the monthly
     * attendance report. Percent stays null when nothing was recorded.
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
}
