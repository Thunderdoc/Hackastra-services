<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Support\CsvExport;
use App\Support\TeacherScope;
use Illuminate\Support\Facades\Auth;
use App\Models\MyClass;
use App\Models\StudentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attendance reporting (Workstream AR — period-only unification).
 *
 * Attendance is taken in exactly one way now: the period register
 * (PeriodAttendanceController — one register per timetable period,
 * topic taught required, submission locked). The legacy day-wise
 * register was retired by the owner's decision of 2026-10-21: its
 * take page and store no longer accept entries (both redirect into
 * the period flow), and everything this controller still serves —
 * the monthly report, the defaulter list, the CSV export and the
 * student/parent "my attendance" page — computes from period data
 * only (attendance_sessions + attendance_session_entries).
 *
 * The attendances table and the Attendance model are deliberately
 * untouched: historical day-wise rows stay in the database exactly
 * as recorded, they are simply no longer read or written by the UI.
 *
 * The counting rule everywhere is the school-wide rule:
 * % = (present + late) / marked period entries — a period with no
 * submitted register is excluded, never counted as absent. A
 * student's "marked" count is therefore a count of period entries,
 * not of days.
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

    /**
     * Where a retired day-wise URL sends the user: teachers land on
     * their period list (where registers are taken); Super Admin /
     * Admin land on the monthly report, which is period-based now.
     */
    private function periodLanding(Request $request)
    {
        if (Qs::userIsTeacher()) {
            $params = [];
            if ($request->query('date')) {
                $params['date'] = $request->query('date');
            }

            return redirect()->route('attendance.periods.index', $params);
        }

        return redirect()->route('attendance.report');
    }

    /**
     * The day-wise take page is retired — attendance is taken per
     * period. This route stays alive so existing links and bookmarks
     * land somewhere useful instead of breaking.
     */
    public function index(Request $request)
    {
        return $this->periodLanding($request)
            ->with('flash_success', 'Attendance is taken per period now — choose a period to take its register.');
    }

    /**
     * The day-wise store accepts nothing any more. A stale form post
     * is redirected into the period flow with an explanation, and no
     * row is ever written to the attendances table.
     */
    public function store(Request $request)
    {
        return $this->periodLanding($request)
            ->with('flash_danger', 'Day-wise attendance has been retired — attendance is taken per period, with the subject and topic taught. Nothing was saved.');
    }

    /**
     * Monthly report — one row per student in the chosen class, with
     * the shared % rule, computed from period entries in the month.
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
        $d['sessions_held'] = 0;

        if ($d['selected_class']) {
            $students = StudentRecord::with('user', 'section')
                ->where('my_class_id', $d['selected_class']->id)
                ->where('grad', 0)
                ->orderBy('id')
                ->get();

            $from = $d['month']->copy()->startOfMonth();
            $to = $d['month']->copy()->endOfMonth();

            $stats = $this->statusCounts($students->pluck('id')->all(), $from, $to);

            $d['sessions_held'] = AttendanceSession::forClass($d['selected_class']->id)
                ->betweenDates($from, $to)
                ->count();

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
     * month falls below the chosen threshold, from period entries.
     * Students with no period entries at all in the month are
     * excluded (no records is not 0%), exactly as the report
     * treats them.
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
     * more than one). Staff are sent to the report instead. All
     * numbers come from period entries: subject-wise totals plus the
     * individual period registers behind them.
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
        $d['subjects'] = collect();
        $d['entries'] = collect();

        if ($selected) {
            $from = $d['month']->copy()->startOfMonth();
            $to = $d['month']->copy()->endOfMonth();

            $entries = AttendanceSessionEntry::with(['session.subject', 'session.time_table.time_slot'])
                ->where('student_record_id', $selected->id)
                ->whereHas('session', function ($q) use ($from, $to) {
                    $q->betweenDates($from, $to);
                })
                ->get()
                ->sortByDesc(function ($entry) {
                    return $entry->session->date->format('Y-m-d') . '-' . str_pad((string) $entry->session_id, 10, '0', STR_PAD_LEFT);
                })
                ->values();

            $d['entries'] = $entries;

            foreach ($entries as $entry) {
                if (isset($d['summary'][$entry->status])) {
                    $d['summary'][$entry->status]++;
                }
                $d['summary']['marked']++;
            }
            $d['summary']['percent'] = $this->percentFor(
                $d['summary']['present'],
                $d['summary']['late'],
                $d['summary']['marked']
            );

            $d['subjects'] = $entries
                ->groupBy(function ($entry) {
                    return $entry->session->subject_id;
                })
                ->map(function ($subjectEntries) {
                    $present = $subjectEntries->where('status', AttendanceSessionEntry::STATUS_PRESENT)->count();
                    $absent = $subjectEntries->where('status', AttendanceSessionEntry::STATUS_ABSENT)->count();
                    $late = $subjectEntries->where('status', AttendanceSessionEntry::STATUS_LATE)->count();
                    $marked = $present + $absent + $late;

                    return [
                        'subject' => $subjectEntries->first()->session->subject,
                        'present' => $present,
                        'absent' => $absent,
                        'late' => $late,
                        'marked' => $marked,
                        'percent' => $this->percentFor($present, $late, $marked),
                    ];
                })
                ->sortBy(function ($row) {
                    return strtolower(optional($row['subject'])->name ?? '');
                })
                ->values();
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
     * student_record_id: [present, absent, late, marked]. Counts
     * PERIOD entries (attendance_session_entries whose session falls
     * in the range) — the day-wise attendances table is retired and
     * is never read here.
     */
    private function statusCounts(array $studentIds, Carbon $from, Carbon $to): array
    {
        if (empty($studentIds)) {
            return [];
        }

        $rows = AttendanceSessionEntry::whereIn('student_record_id', $studentIds)
            ->whereHas('session', function ($q) use ($from, $to) {
                $q->betweenDates($from, $to);
            })
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
     * CSV export of the monthly report. Mirrors report()'s
     * computation exactly — same class+month filter, same percent
     * rule, and the same period-entry source.
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
        $month = $monthParam && preg_match('/^\d{4}-\d{2}$/', $monthParam)
            ? Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth()
            : Carbon::today()->startOfMonth();

        $students = StudentRecord::with('user', 'section')
            ->where('my_class_id', $selected_class->id)
            ->where('grad', 0)
            ->orderBy('id')
            ->get();

        $stats = $this->statusCounts(
            $students->pluck('id')->all(),
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()
        );

        $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];

        $rows = $students->map(function ($student) use ($stats, &$totals) {
            $s = $stats[$student->id] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
            $totals['present'] += $s['present'];
            $totals['absent'] += $s['absent'];
            $totals['late'] += $s['late'];
            $totals['marked'] += $s['marked'];

            return [
                optional($student->user)->name,
                optional($student->section)->name,
                $s['present'],
                $s['absent'],
                $s['late'],
                $s['marked'],
                // Same rule as the on-screen report; blank when nothing was marked.
                $s['marked'] > 0 ? round((($s['present'] + $s['late']) / $s['marked']) * 100, 1) : '',
            ];
        })->all();

        $rows[] = ['TOTAL', '', $totals['present'], $totals['absent'], $totals['late'], $totals['marked'], ''];

        $filename = 'attendance-' . Str::slug($selected_class->name) . '-' . $month->format('Y-m') . '.csv';

        return CsvExport::download($filename,
            ['Student', 'Section', 'Present', 'Absent', 'Late', 'Periods Marked', 'Attendance %'],
            $rows);
    }
}
