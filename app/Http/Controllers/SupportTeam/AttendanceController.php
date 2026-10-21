<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceCreate;
use App\Models\Attendance;
use App\Support\CsvExport;
use App\Support\TeacherScope;
use Illuminate\Support\Facades\Auth;
use App\Models\MyClass;
use App\Models\StudentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily attendance (audit §5/§9) — the feature the dashboard's
 * "Today's Attendance" panel reads from. One record per student per
 * day, enforced by the unique key on the attendances table.
 *
 * Loop 7-H adds: the monthly report method its route always pointed
 * to, a defaulter list (students under a selectable attendance
 * threshold), and a read-only "my attendance" page for students and
 * parents. The counting rule everywhere is the report's rule:
 * % = (present + late) / marked days — a day with no record is
 * excluded, never counted as absent.
 */
class AttendanceController extends Controller
{
    public function __construct()
    {
        // Everything here is staff tooling except the student's /
        // parent's own read-only view of their own records.
        $this->middleware('teamSAT')->except('my');
    }

    /**
     * Batch S: the classes this user may work with. Staff teams keep
     * the whole school; a teacher gets only their assigned classes
     * (TeacherScope). Every picker, roster and export in this
     * controller derives from this one list, and guardClassScope()
     * hard-denies a hand-crafted out-of-scope class_id.
     */
    private function visibleClasses()
    {
        $classes = MyClass::orderBy('name')->get();

        if (Qs::userIsTeacher()) {
            $classes = $classes->whereIn('id', TeacherScope::classIds(Auth::user()))->values();
        }

        return $classes;
    }

    private function guardClassScope($classId)
    {
        if ($classId && Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $classId)) {
            abort(403, 'You can only work with your assigned classes.');
        }
    }

    public function index(Request $request)
    {
        $this->guardClassScope($request->query('class_id'));

        $d['classes'] = $this->visibleClasses();
        $d['selected_class'] = $request->query('class_id')
            ? $d['classes']->firstWhere('id', (int) $request->query('class_id'))
            : $d['classes']->first();
        $d['date'] = $request->query('date')
            ? Carbon::parse($request->query('date'))
            : Carbon::today();
        $d['students'] = collect();
        $d['existing'] = [];

        if ($d['selected_class']) {
            $d['students'] = StudentRecord::with('user', 'section')
                ->where('my_class_id', $d['selected_class']->id)
                ->where('grad', 0)
                ->orderBy('id')
                ->get();

            $d['existing'] = Attendance::whereDate('date', $d['date']->toDateString())
                ->whereIn('student_record_id', $d['students']->pluck('id'))
                ->pluck('status', 'student_record_id')
                ->all();
        }

        $d['summary'] = [
            'present' => count(array_filter($d['existing'], function ($s) { return $s === 'present'; })),
            'absent' => count(array_filter($d['existing'], function ($s) { return $s === 'absent'; })),
            'late' => count(array_filter($d['existing'], function ($s) { return $s === 'late'; })),
            'marked' => count($d['existing']),
            'total' => $d['students']->count(),
        ];

        return view('pages.support_team.attendance.index', $d);
    }

    public function store(AttendanceCreate $request)
    {
        $data = $request->validated();

        // Batch S: submitting attendance for a class the teacher is
        // not assigned to is denied outright (the roster filter below
        // already stops cross-class student ids in a tampered form).
        $this->guardClassScope($data['class_id']);

        $studentIds = StudentRecord::where('my_class_id', $data['class_id'])
            ->where('grad', 0)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($data, $studentIds, $request) {
            foreach ($data['statuses'] as $studentRecordId => $status) {
                if (!in_array((int) $studentRecordId, $studentIds, true)) {
                    continue; // never record attendance for another class via a tampered form
                }

                Attendance::updateOrCreate(
                    ['student_record_id' => (int) $studentRecordId, 'date' => $data['date']],
                    ['status' => $status, 'recorded_by' => $request->user()->id]
                );
            }
        });

        return redirect()
            ->route('attendance.index', ['class_id' => $data['class_id'], 'date' => $data['date']])
            ->with('flash_success', 'Attendance saved for ' . Carbon::parse($data['date'])->format('d M Y') . '.');
    }

    /**
     * Monthly report — one row per student in the chosen class, with
     * the shared % rule. This is the method the attendance.report
     * route and view were shipped against.
     */
    public function report(Request $request)
    {
        $this->guardClassScope($request->query('class_id'));

        $d['classes'] = $this->visibleClasses();
        $d['selected_class'] = $request->query('class_id')
            ? $d['classes']->firstWhere('id', (int) $request->query('class_id'))
            : $d['classes']->first();
        $d['month'] = $this->monthFromRequest($request);
        $d['rows'] = collect();
        $d['totals'] = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];

        if ($d['selected_class']) {
            $students = StudentRecord::with('user', 'section')
                ->where('my_class_id', $d['selected_class']->id)
                ->where('grad', 0)
                ->orderBy('id')
                ->get();

            $stats = $this->statusCounts(
                $students->pluck('id')->all(),
                $d['month']->copy()->startOfMonth(),
                $d['month']->copy()->endOfMonth()
            );

            $d['rows'] = $students->map(function ($student) use ($stats, &$d) {
                $s = $stats[$student->id] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
                $d['totals']['present'] += $s['present'];
                $d['totals']['absent'] += $s['absent'];
                $d['totals']['late'] += $s['late'];
                $d['totals']['marked'] += $s['marked'];

                return [
                    'student' => $student,
                    'present' => $s['present'],
                    'absent' => $s['absent'],
                    'late' => $s['late'],
                    'marked' => $s['marked'],
                    'percent' => $this->percentFor($s['present'], $s['late'], $s['marked']),
                ];
            });
        }

        return view('pages.support_team.attendance.report', $d);
    }

    /**
     * Defaulter list — students whose attendance % for the chosen
     * month falls below the chosen threshold. Students with no
     * records at all in the month are excluded (no records is not
     * 0%), exactly as the report treats them.
     */
    public function defaulters(Request $request)
    {
        $d['thresholds'] = [50, 60, 70, 75, 80, 90];
        $threshold = (int) $request->query('threshold', 75);
        $d['threshold'] = in_array($threshold, $d['thresholds'], true) ? $threshold : 75;
        $d['month'] = $this->monthFromRequest($request);
        $this->guardClassScope($request->query('class_id'));
        $d['classes'] = $this->visibleClasses();
        $d['selected_class'] = $request->query('class_id')
            ? $d['classes']->firstWhere('id', (int) $request->query('class_id'))
            : null; // null = every class the user may see

        $students = StudentRecord::with('user', 'my_class', 'section')
            ->where('grad', 0)
            ->when(Qs::userIsTeacher(), function ($q) {
                // Batch S: a teacher's "every class" defaulter scan
                // covers only their assigned classes.
                return $q->whereIn('my_class_id', TeacherScope::classIds(Auth::user()));
            })
            ->when($d['selected_class'], function ($q) use ($d) {
                return $q->where('my_class_id', $d['selected_class']->id);
            })
            ->orderBy('id')
            ->get();

        $stats = $this->statusCounts(
            $students->pluck('id')->all(),
            $d['month']->copy()->startOfMonth(),
            $d['month']->copy()->endOfMonth()
        );

        $scanned = 0;
        $d['rows'] = $students
            ->map(function ($student) use ($stats, $d, &$scanned) {
                $s = $stats[$student->id] ?? null;
                if (!$s || $s['marked'] === 0) {
                    return null; // never brand a student with zero records a defaulter
                }
                $scanned++;
                $percent = $this->percentFor($s['present'], $s['late'], $s['marked']);
                if ($percent >= $d['threshold']) {
                    return null;
                }

                return [
                    'student' => $student,
                    'present' => $s['present'],
                    'absent' => $s['absent'],
                    'late' => $s['late'],
                    'marked' => $s['marked'],
                    'percent' => $percent,
                ];
            })
            ->filter()
            ->sortBy(function ($row) {
                return [$row['percent'], strtolower(optional($row['student']->user)->name ?? '')];
            })
            ->values();
        $d['scanned'] = $scanned;

        return view('pages.support_team.attendance.defaulters', $d);
    }

    /**
     * "My attendance" — a student sees their own month; a parent
     * sees each linked child's month (child switcher when there is
     * more than one). Staff are sent to the report instead.
     */
    public function my(Request $request)
    {
        if (Qs::userIsTeamSAT()) {
            return redirect()->route('attendance.report');
        }
        if (!Qs::userIsStudent() && !Qs::userIsParent()) {
            return redirect()->route('dashboard');
        }

        $user = $request->user();
        $d['month'] = $this->monthFromRequest($request);
        $d['is_parent'] = Qs::userIsParent();

        $records = StudentRecord::with('user', 'my_class', 'section')
            ->where('grad', 0)
            ->when($d['is_parent'],
                function ($q) use ($user) { return $q->where('my_parent_id', $user->id); },
                function ($q) use ($user) { return $q->where('user_id', $user->id); })
            ->orderBy('id')
            ->get();

        $d['students'] = $records;
        $selected = $records->firstWhere('id', (int) $request->query('student_id')) ?: $records->first();
        $d['student'] = $selected;
        $d['summary'] = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0, 'percent' => null];
        $d['days'] = collect();

        if ($selected) {
            $from = $d['month']->copy()->startOfMonth();
            $to = $d['month']->copy()->endOfMonth();

            $d['days'] = Attendance::where('student_record_id', $selected->id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('date')
                ->get();

            foreach ($d['days'] as $day) {
                if (isset($d['summary'][$day->status])) {
                    $d['summary'][$day->status]++;
                }
                $d['summary']['marked']++;
            }
            $d['summary']['percent'] = $this->percentFor(
                $d['summary']['present'],
                $d['summary']['late'],
                $d['summary']['marked']
            );
        }

        return view('pages.support_team.attendance.my', $d);
    }

    /** Shared % rule: (present + late) / marked, null when nothing was marked. */
    private function percentFor(int $present, int $late, int $marked)
    {
        return $marked > 0 ? (int) round(($present + $late) / $marked * 100) : null;
    }

    /**
     * Per-student status tallies for a date range, keyed by
     * student_record_id: [present, absent, late, marked].
     */
    private function statusCounts(array $studentIds, Carbon $from, Carbon $to): array
    {
        if (empty($studentIds)) {
            return [];
        }

        $rows = Attendance::whereIn('student_record_id', $studentIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->select('student_record_id', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('student_record_id', 'status')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $id = $row->student_record_id;
            if (!isset($stats[$id])) {
                $stats[$id] = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
            }
            if (isset($stats[$id][$row->status])) {
                $stats[$id][$row->status] += (int) $row->total;
            }
            $stats[$id]['marked'] += (int) $row->total;
        }

        return $stats;
    }

    private function monthFromRequest(Request $request): Carbon
    {
        $month = $request->query('month');

        return $month && preg_match('/^\d{4}-\d{2}$/', $month)
            ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : Carbon::now()->startOfMonth();
    }

    /**
     * CSV export of the monthly report (Loop 8). Mirrors report()'s
     * computation exactly — same class+month filter, same percent rule
     * (present + late over marked days; unmarked days excluded) — and
     * report() itself is deliberately left untouched.
     */
    public function export(Request $request)
    {
        $this->guardClassScope($request->query('class_id'));

        $classes = $this->visibleClasses();
        $selected_class = $request->query('class_id')
            ? $classes->firstWhere('id', (int) $request->query('class_id'))
            : $classes->first();

        if (!$selected_class) {
            return redirect()->route('attendance.report')
                ->with('flash_danger', 'No class available to export.');
        }

        $monthParam = $request->query('month');
        $month = $monthParam
            ? Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth()
            : Carbon::today()->startOfMonth();

        $students = StudentRecord::with('user', 'section')
            ->where('my_class_id', $selected_class->id)
            ->where('grad', 0)
            ->orderBy('id')
            ->get();

        $grouped = Attendance::whereIn('student_record_id', $students->pluck('id'))
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->get()
            ->groupBy('student_record_id');

        $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];

        $rows = $students->map(function ($student) use ($grouped, &$totals) {
            $records = $grouped->get($student->id, collect());
            $present = $records->where('status', Attendance::STATUS_PRESENT)->count();
            $absent = $records->where('status', Attendance::STATUS_ABSENT)->count();
            $late = $records->where('status', Attendance::STATUS_LATE)->count();
            $marked = $present + $absent + $late;
            $totals['present'] += $present;
            $totals['absent'] += $absent;
            $totals['late'] += $late;
            $totals['marked'] += $marked;

            return [
                optional($student->user)->name,
                optional($student->section)->name,
                $present,
                $absent,
                $late,
                $marked,
                // Same rule as the on-screen report; blank when nothing was marked.
                $marked > 0 ? round((($present + $late) / $marked) * 100, 1) : '',
            ];
        })->all();

        $rows[] = ['TOTAL', '', $totals['present'], $totals['absent'], $totals['late'], $totals['marked'], ''];

        $filename = 'attendance-' . Str::slug($selected_class->name) . '-' . $month->format('Y-m') . '.csv';

        return CsvExport::download($filename,
            ['Student', 'Section', 'Present', 'Absent', 'Late', 'Marked', 'Attendance %'],
            $rows);
    }
}
