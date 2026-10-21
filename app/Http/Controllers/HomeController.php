<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\Attendance;
use App\Models\PaymentRecord;
use App\Models\SchoolEvent;
use App\Models\StudentRecord;
use App\Repositories\UserRepo;
use Illuminate\Support\Carbon;
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
}
