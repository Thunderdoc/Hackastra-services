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
use App\Models\Section;
use App\Models\StudentRecord;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $d = $this->monthlyReportData($request);

        return view('pages.support_team.attendance.report', $d);
    }

    /**
     * Print preview of the monthly report — the v2 document
     * (partials.monthly_report_document) on its own page with a small
     * toolbar. Same data, same guard as report(): a teacher can never
     * preview another class's document by editing the URL.
     */
    public function reportPrint(Request $request)
    {
        $d = $this->monthlyReportData($request);

        if (!$d['selected_class'] && empty($d['all_classes'])) {
            return redirect()->route('attendance.report')
                ->with('flash_danger', 'No class available to preview.');
        }

        $d['autoprint'] = $request->boolean('autoprint');

        return view('pages.support_team.attendance.report_print', $d);
    }

    /**
     * Downloadable PDF of the monthly report — the SAME document
     * template as the print preview, rendered by dompdf. Page
     * numbers are stamped by a canvas page script ("Page X of Y" on
     * every page); the filename comes from the report metadata.
     */
    public function reportPdf(Request $request)
    {
        $d = $this->monthlyReportData($request);

        if (!$d['selected_class'] && empty($d['all_classes'])) {
            return redirect()->route('attendance.report')
                ->with('flash_danger', 'No class available to download.');
        }

        $filename = 'attendance-report-'
            . Str::slug($d['class_label']) . '-'
            . Str::slug(strtolower($d['range_label'])) . '.pdf';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.attendance.report_pdf', $d);
        $pdf->setPaper('a4', 'portrait');

        // dompdf's canvas page_text draws on the pages that exist at
        // call time, so render first (through the wrapper, which
        // then serves download() from this same render) and stamp
        // the running footer onto every finished page.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $grey = [0.44, 0.47, 0.53];
        $width = $canvas->get_width();
        $height = $canvas->get_height();

        $canvas->page_text(34, $height - 26,
            'Attendance Report - ' . $d['class_label'] . ' - ' . $d['range_label'],
            $font, 8, $grey);
        $labelWidth = $metrics->getTextWidth('Page 10 of 10', $font, 8);
        $canvas->page_text($width - 34 - $labelWidth, $height - 26,
            'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, $grey);

        return $pdf->download($filename);
    }

    /**
     * Shared computation behind report(), reportPrint() and
     * reportPdf() — one source of truth so the screen, the printed
     * document and the PDF can never disagree. The counting rule is
     * unchanged: period entries only, % = (present + late) / marked,
     * unsubmitted registers excluded, zero-marked students get a
     * null percent (never 0%).
     *
     * Beyond the original report keys this adds: per-row status
     * band/label (the settled app-wide tier scale, aligned in audit
     * Fix Wave 1: Excellent >= 90, Good >= 75, Needs Attention
     * 50-74, Critical < 50 — the same thresholds the Low Attendance
     * page uses), the totals' shares of all marked entries, the
     * class summary figures, and the school identity block the
     * document letterhead renders.
     */
    protected function monthlyReportData(Request $request): array
    {
        if ($request->query('class_id') !== 'all') {
            $this->guardClassScope($request->query('class_id'));
        }

        $d['classes'] = $this->visibleClasses();
        /* Owner (2026-10-23): customizable period downloads — the
           report covers the chosen month plus the N-1 months before
           it (months = 1..12), and teamSA / teachers may aggregate
           ALL their visible classes at once (class_id=all). Teacher
           scope stays enforced by visibleClasses() itself. */
        $d['all_classes'] = $request->query('class_id') === 'all' && $d['classes']->count() > 1;
        $d['selected_class'] = $d['all_classes']
            ? null
            : ($request->query('class_id')
                ? $d['classes']->firstWhere('id', (int) $request->query('class_id'))
                : $d['classes']->first());
        $d['month'] = $this->monthFromRequest($request);
        $d['months'] = max(1, min(12, (int) $request->query('months', 1)));
        $rangeFrom = $d['month']->copy()->subMonths($d['months'] - 1)->startOfMonth();
        $rangeTo = $d['month']->copy()->endOfMonth();
        $d['range_label'] = $d['months'] === 1
            ? $d['month']->format('F Y')
            : $rangeFrom->format('F Y') . ' – ' . $rangeTo->format('F Y');
        $d['class_label'] = $d['all_classes']
            ? 'All Classes'
            : ($d['selected_class'] ? $d['selected_class']->name : '');
        $d['rows'] = collect();
        $d['totals'] = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
        $d['sessions_held'] = 0;
        $d['shares'] = ['present' => 0.0, 'absent' => 0.0, 'late' => 0.0];
        $d['class_summary'] = ['students' => 0, 'with_100' => 0, 'below_75' => 0, 'registers' => 0];
        $d['generated_on'] = now();
        $d['school'] = $this->schoolIdentity();

        $reportClasses = $d['all_classes']
            ? $d['classes']
            : ($d['selected_class'] ? collect([$d['selected_class']]) : collect());
        $totalStudents = 0;

        foreach ($reportClasses as $reportClass) {
            $students = StudentRecord::with('user', 'section')
                ->where('my_class_id', $reportClass->id)
                ->where('grad', 0)
                ->orderBy('id')
                ->get();
            $totalStudents += $students->count();

            $stats = $this->statusCounts($students->pluck('id')->all(), $rangeFrom, $rangeTo);

            $d['sessions_held'] += AttendanceSession::forClass($reportClass->id)
                ->betweenDates($rangeFrom, $rangeTo)
                ->count();

            foreach ($students as $student) {
                $s = $stats[$student->id] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
                $d['totals']['present'] += $s['present'];
                $d['totals']['absent'] += $s['absent'];
                $d['totals']['late'] += $s['late'];
                $d['totals']['marked'] += $s['marked'];

                $percent = $this->percentFor($s['present'], $s['late'], $s['marked']);
                [$band, $label] = $this->statusBand($percent);

                $d['rows']->push([
                    'student' => $student,
                    'class_name' => $reportClass->name,
                    'present' => $s['present'],
                    'absent' => $s['absent'],
                    'late' => $s['late'],
                    'marked' => $s['marked'],
                    'percent' => $percent,
                    'status_band' => $band,
                    'status_label' => $label,
                ]);
            }
        }

        if ($reportClasses->isNotEmpty()) {
            $marked = $d['totals']['marked'];
            if ($marked > 0) {
                $d['shares'] = [
                    'present' => round($d['totals']['present'] / $marked * 100, 1),
                    'absent' => round($d['totals']['absent'] / $marked * 100, 1),
                    'late' => round($d['totals']['late'] / $marked * 100, 1),
                ];
            }

            $d['class_summary'] = [
                'students' => $totalStudents,
                'with_100' => $d['rows']->where('percent', 100)->count(),
                'below_75' => $d['rows']->filter(function ($row) {
                    return $row['percent'] !== null && $row['percent'] < 75;
                })->count(),
                'registers' => $d['sessions_held'],
            ];
        }

        return $d;
    }

    /**
     * The document status policy, shared by screen and document.
     * Audit Fix Wave 1: aligned to the settled app-wide tiers —
     * Critical strictly below 50%, the at-risk band 50-74% (the Low
     * Attendance page's line is 75%), Good from 75%, Excellent
     * from 90%. This method previously ran a private 60/80 scale,
     * so the same student could be "Needs Attention" here and not
     * a defaulter there.
     */
    private function statusBand($percent): array
    {
        if ($percent === null) {
            return ['none', 'No Records'];
        }
        if ($percent >= 90) {
            return ['excellent', 'Excellent'];
        }
        if ($percent >= 75) {
            return ['good', 'Good'];
        }
        if ($percent >= 50) {
            return ['attention', 'Needs Attention'];
        }

        return ['critical', 'Critical'];
    }

    /**
     * School identity for the document letterhead — Settings only,
     * nothing invented. Logo/stamp are exposed twice: the stored
     * value for the browser surfaces, and a local file path for
     * dompdf when the upload resolves to a file on disk (null when
     * it does not, so the PDF falls back to the monogram tile
     * instead of a broken image).
     */
    private function schoolIdentity(): array
    {
        $initials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSystemName())) as $word) {
            if ($word !== '') {
                $initials .= strtoupper(substr($word, 0, 1));
                if (strlen($initials) >= 2) {
                    break;
                }
            }
        }

        return [
            'name' => Qs::getSystemName(),
            'address' => Qs::getSetting('address'),
            'phone' => Qs::getSetting('phone'),
            'email' => Qs::getSetting('system_email'),
            'session' => Qs::getCurrentSession(),
            'code' => strtoupper((string) Qs::getAppCode()),
            'initials' => $initials !== '' ? $initials : 'S',
            'principal_name' => Qs::getSetting('principal_name'),
            'logo' => Qs::getSetting('logo') ?: null,
            'stamp' => Qs::getSetting('school_stamp') ?: null,
            'logo_file' => $this->localFileFor(Qs::getSetting('logo')),
            'stamp_file' => $this->localFileFor(Qs::getSetting('school_stamp')),
        ];
    }

    /** Resolve a stored upload value to an on-disk path, if it is one. */
    private function localFileFor($value)
    {
        if (!$value || !is_string($value)) {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            $path = parse_url($value, PHP_URL_PATH);
            $candidate = $path ? public_path(ltrim($path, '/')) : null;
        } else {
            $candidate = public_path(ltrim($value, '/'));
        }

        return $candidate && file_exists($candidate) ? $candidate : null;
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
        $d = $this->defaulterData($request);

        // The screen table is paginated (15/page, the house pattern);
        // $d['rows'] keeps the FULL filtered set so the print
        // document and the summary cards always cover every match,
        // not just the visible page.
        $perPage = 15;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $d['paginator'] = new LengthAwarePaginator(
            $d['rows']->forPage($page, $perPage)->values(),
            $d['rows']->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('pages.support_team.attendance.defaulters', $d);
    }

    /**
     * CSV export of the defaulter list — the same filtered set the
     * screen shows, computed by the same defaulterData() so the
     * figures can never drift from the page or the print document.
     */
    public function defaultersExport(Request $request)
    {
        $d = $this->defaulterData($request);

        $rows = $d['rows']->map(function ($row) {
            return [
                optional($row['student']->user)->name,
                $row['student']->adm_no,
                optional($row['student']->my_class)->name,
                optional($row['student']->section)->name,
                $row['present'],
                $row['absent'],
                $row['late'],
                $row['marked'],
                $row['percent'] . '%',
                $row['status'],
            ];
        })->all();

        $filename = 'low-attendance-' . $d['month']->format('Y-m') . '-below-' . $d['threshold'] . '.csv';

        return CsvExport::download($filename,
            ['Student', 'Admission No', 'Class', 'Section', 'Present', 'Absent', 'Late', 'Periods Marked', 'Attendance %', 'Status'],
            $rows);
    }

    /**
     * The defaulter computation, shared by the page and its CSV
     * export. Filters: class, section (only within the chosen
     * class), month, threshold, search (student name / admission
     * no.) — all server-side and combinable. Summary aggregates are
     * computed over the full filtered set: below-threshold count,
     * critical count (< 50%, labelled as such in the UI), the mean
     * of the listed students' percentages, and total enrolled
     * students in the class/section scope.
     */
    private function defaulterData(Request $request): array
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

        // Sections are limited to the chosen class; a section id
        // that does not belong to it is ignored, never honoured.
        $d['sections'] = $d['selected_class']
            ? Section::where('my_class_id', $d['selected_class']->id)->orderBy('name')->get()
            : collect();
        $d['selected_section'] = ($d['selected_class'] && $request->query('section_id'))
            ? $d['sections']->firstWhere('id', (int) $request->query('section_id'))
            : null;
        $d['search'] = trim((string) $request->query('search', ''));

        $scope = function (bool $withSearch) use ($d) {
            return StudentRecord::with('user', 'my_class', 'section')
                ->where('grad', 0)
                ->when(Qs::userIsTeacher(), function ($q) {
                    // Batch S: a teacher's "every class" defaulter scan
                    // covers only their assigned classes.
                    return $q->whereIn('my_class_id', TeacherScope::classIds(Auth::user()));
                })
                ->when($d['selected_class'], function ($q) use ($d) {
                    return $q->where('my_class_id', $d['selected_class']->id);
                })
                ->when($d['selected_section'], function ($q) use ($d) {
                    return $q->where('section_id', $d['selected_section']->id);
                })
                ->when($withSearch && $d['search'] !== '', function ($q) use ($d) {
                    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $d['search']) . '%';
                    return $q->where(function ($w) use ($like) {
                        $w->where('adm_no', 'like', $like)
                            ->orWhereHas('user', function ($u) use ($like) {
                                $u->where('name', 'like', $like);
                            });
                    });
                });
        };

        // Total enrolled in the class/section scope (search is a
        // table finder, not an enrolment filter).
        $d['total_students'] = $scope(false)->count();

        $students = $scope(true)->orderBy('id')->get();

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
                    // Honest tiers: Critical is strictly below 50%;
                    // every other listed student is At Risk (50% up
                    // to the chosen threshold).
                    'status' => $percent < 50 ? 'Critical' : 'At Risk',
                ];
            })
            ->filter()
            ->sortBy(function ($row) {
                return [$row['percent'], strtolower(optional($row['student']->user)->name ?? '')];
            })
            ->values();
        $d['scanned'] = $scanned;
        $d['summary'] = [
            'below' => $d['rows']->count(),
            'critical' => $d['rows']->where('status', 'Critical')->count(),
            // Loop 117: distinct classes carrying at least one listed
            // student — the reference KPI set's Classes card, counted
            // from the same filtered rows as every other card.
            'classes' => $d['rows']->map(function ($row) {
                return $row['student']->my_class_id;
            })->filter()->unique()->count(),
            'average' => $d['rows']->isEmpty() ? null : round((float) $d['rows']->avg('percent'), 1),
            'total_students' => $d['total_students'],
        ];

        return $d;
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
     * CSV export of the attendance report. Runs on the SAME
     * monthlyReportData() as the screen/print/PDF so the four
     * outputs can never disagree (owner, 2026-10-23: every Excel
     * download must be systematic and well-labelled) — the file
     * opens with the school name, the report title, the exact
     * period and class scope, and a totals row at the foot.
     */
    public function export(Request $request)
    {
        $d = $this->monthlyReportData($request);

        if (!$d['selected_class'] && empty($d['all_classes'])) {
            return redirect()->route('attendance.report')
                ->with('flash_danger', 'No class available to export.');
        }

        $rows = [];
        foreach ($d['rows'] as $row) {
            $rows[] = [
                optional($row['student']->user)->name,
                $row['student']->adm_no,
                $row['class_name'],
                optional($row['student']->section)->name,
                $row['present'],
                $row['absent'],
                $row['late'],
                $row['marked'],
                $row['percent'] === null ? '' : $row['percent'],
                $row['status_label'],
            ];
        }
        $t = $d['totals'];
        $rows[] = ['TOTAL', '', '', '', $t['present'], $t['absent'], $t['late'], $t['marked'], '', ''];

        $filename = 'attendance-report-'
            . Str::slug($d['class_label']) . '-'
            . Str::slug(strtolower($d['range_label'])) . '.csv';

        return response()->streamDownload(function () use ($d, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, [Qs::getSystemName() . ' — Attendance Report']);
            fputcsv($out, ['Period', $d['range_label']]);
            fputcsv($out, ['Class', $d['class_label']]);
            fputcsv($out, ['Generated on', now()->format('d M Y, h:i A')]);
            fputcsv($out, []);
            fputcsv($out, ['Student', 'Admission No', 'Class', 'Section', 'Present', 'Absent', 'Late', 'Periods Marked', 'Attendance %', 'Status']);
            foreach ($rows as $row) {
                fputcsv($out, array_map([\App\Support\CsvExport::class, 'cell'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * ONE STUDENT's own attendance report (owner, 2026-10-23: the
     * Low Attendance row action opened the whole CLASS report —
     * it must open that student's individual report). Same period
     * machinery as the class report: month + months range, counts
     * from statusCounts(), plus a per-subject breakdown and the
     * day-by-day register (latest 60 entries) from the sessions.
     * Scope: teamSA roles; a teacher only reaches students in
     * classes they teach (TeacherScope), everyone else 403s.
     */
    private function studentReportData(Request $request, $student_id): array
    {
        $student = StudentRecord::with(['user', 'my_class', 'section'])
            ->find(Qs::resolveId($student_id));
        abort_if(!$student, 404);

        $user = Auth::user();
        if ($user->user_type === 'teacher'
            && !TeacherScope::canTeachClass($user, (int) $student->my_class_id)) {
            abort(403);
        }

        $d['student'] = $student;
        $d['month'] = $this->monthFromRequest($request);
        $months = (int) $request->query('months', 1);
        $d['months'] = ($months >= 1 && $months <= 12) ? $months : 1;
        $rangeTo = $d['month']->copy()->endOfMonth();
        $rangeFrom = $d['month']->copy()->startOfMonth()->subMonths($d['months'] - 1);
        $d['range_label'] = $d['months'] === 1
            ? $d['month']->format('F Y')
            : $rangeFrom->format('F Y') . ' – ' . $d['month']->format('F Y');

        $stats = $this->statusCounts([$student->id], $rangeFrom, $rangeTo);
        $c = $stats[$student->id] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
        $d['counts'] = $c;
        $d['percent'] = $this->percentFor($c['present'], $c['late'], $c['marked']);
        [$d['status_band'], $d['status_label']] = $this->statusBand($d['percent']);

        $entries = AttendanceSessionEntry::where('student_record_id', $student->id)
            ->whereHas('session', function ($q) use ($rangeFrom, $rangeTo) {
                $q->betweenDates($rangeFrom, $rangeTo);
            })
            ->with(['session.subject', 'session.time_table.time_slot'])
            ->get()
            ->sortByDesc(function ($entry) {
                return optional($entry->session)->date;
            })
            ->values();

        $subjects = [];
        foreach ($entries as $entry) {
            $subject = optional($entry->session)->subject;
            $key = $subject ? $subject->id : 0;
            if (!isset($subjects[$key])) {
                $subjects[$key] = ['subject' => $subject, 'present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
            }
            if (isset($subjects[$key][$entry->status])) {
                $subjects[$key][$entry->status]++;
            }
            $subjects[$key]['marked']++;
        }
        foreach ($subjects as &$row) {
            $row['percent'] = $this->percentFor($row['present'], $row['late'], $row['marked']);
        }
        unset($row);
        $d['subjects'] = collect($subjects)->sortBy(function ($row) {
            return optional($row['subject'])->name;
        })->values();
        $d['school'] = $this->schoolIdentity();
        $d['entries'] = $entries->take(60);
        $d['entries_total'] = $entries->count();

        return $d;
    }

    public function studentReport(Request $request, $student_id)
    {
        $d = $this->studentReportData($request, $student_id);
        $d['student_hash'] = Qs::hash($d['student']->id);

        return view('pages.support_team.attendance.student_report', $d);
    }

    public function studentReportPdf(Request $request, $student_id)
    {
        $d = $this->studentReportData($request, $student_id);

        $filename = 'student-attendance-'
            . Str::slug($d['student']->adm_no) . '-'
            . Str::slug(strtolower($d['range_label'])) . '.pdf';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.attendance.student_report_pdf', $d)
            ->setPaper('a4', 'portrait');

        $studentName = optional($d['student']->user)->name;
        $rangeLabel = $d['range_label'];
        $pdf->output();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $height = $canvas->get_height();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $grey = [0.55, 0.57, 0.61];
        $canvas->page_text(34, $height - 26,
            'Student Attendance Report - ' . $studentName . ' - ' . $rangeLabel,
            $font, 8, $grey);
        $canvas->page_text($canvas->get_width() - 92, $height - 26,
            'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, $grey);

        return $pdf->download($filename);
    }

    public function studentReportExport(Request $request, $student_id)
    {
        $d = $this->studentReportData($request, $student_id);
        $student = $d['student'];
        $filename = 'student-attendance-'
            . Str::slug($student->adm_no) . '-'
            . Str::slug(strtolower($d['range_label'])) . '.csv';
        $c = $d['counts'];

        return response()->streamDownload(function () use ($d, $student, $c) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [Qs::getSystemName() . ' — Student Attendance Report']);
            fputcsv($out, ['Student', \App\Support\CsvExport::cell(optional($student->user)->name)]);
            fputcsv($out, ['Admission No', $student->adm_no]);
            fputcsv($out, ['Class', optional($student->my_class)->name . ' · ' . optional($student->section)->name]);
            fputcsv($out, ['Period', $d['range_label']]);
            fputcsv($out, ['Generated on', now()->format('d M Y, h:i A')]);
            fputcsv($out, []);
            fputcsv($out, ['Present', 'Absent', 'Late', 'Periods Marked', 'Attendance %', 'Status']);
            fputcsv($out, [$c['present'], $c['absent'], $c['late'], $c['marked'],
                $d['percent'] === null ? '' : $d['percent'], $d['status_label']]);
            fputcsv($out, []);
            fputcsv($out, ['Date', 'Subject', 'Status']);
            foreach ($d['entries'] as $entry) {
                $session = $entry->session;
                fputcsv($out, array_map([\App\Support\CsvExport::class, 'cell'], [
                    $session && $session->date ? \Illuminate\Support\Carbon::parse($session->date)->format('d M Y') : '',
                    optional(optional($session)->subject)->name,
                    ucfirst($entry->status),
                ]));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * PDF of the defaulter list itself (owner, 2026-10-23: the old
     * "View Full Report" button navigated away to the class report;
     * everything must open and download from THIS page). Runs on
     * the same defaulterData() as the screen and the CSV.
     */
    public function defaultersPdf(Request $request)
    {
        $d = $this->defaulterData($request);
        $d['school'] = $this->schoolIdentity();

        $filename = 'low-attendance-' . $d['month']->format('Y-m')
            . '-below-' . $d['threshold'] . '.pdf';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.attendance.defaulters_pdf', $d)
            ->setPaper('a4', 'landscape');

        $monthLabel = $d['month']->format('F Y');
        $threshold = $d['threshold'];
        $pdf->output();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $height = $canvas->get_height();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $grey = [0.55, 0.57, 0.61];
        $canvas->page_text(34, $height - 26,
            'Low Attendance Report - ' . $monthLabel . ' - Below ' . $threshold . '%',
            $font, 8, $grey);
        $canvas->page_text($canvas->get_width() - 92, $height - 26,
            'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, $grey);

        return $pdf->download($filename);
    }
}
