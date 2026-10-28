<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\CsvExport;
use App\Support\SectionTeacher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Period-wise (subject) attendance — Workstream AC.
 *
 * The day-wise register (AttendanceController) says a student was in
 * school; this log says which periods they attended. Each timetable
 * period's own teacher takes the register for that period, records
 * the topic taught, and submits — submission is FINAL. There is no
 * edit or delete route for a session, the store action refuses a
 * second session for the same period + date, and the
 * (date, time_table_id) unique key enforces it in the database.
 *
 * Period resolution mirrors HomeController::teacherHomeData: weekday
 * TimeTable rows (exam rows carry an exam_date instead of a weekday,
 * so the weekday filter excludes them) joined through Subject, whose
 * teacher_id decides whose period it is.
 */
class PeriodAttendanceController extends Controller
{
    public function __construct()
    {
        // Staff only: Super Admin / Admin (oversight, read-only) and
        // teachers (they take their own periods).
        // Limited principal role (owner order 2026-10-28): the
        // register READS (index/report/export/show/print/pdf) are
        // school-wide monitoring for the principal; take/store and
        // the teacher roster outputs stay teamSAT — a principal
        // never takes attendance, and the take/store guards would
        // redirect a non-teacher anyway.
        $this->middleware('teamSAT')->except(['index', 'report', 'export', 'show', 'printRegister', 'pdfRegister', 'exportRegister']);
        $this->middleware('teamOrPrincipal:SAT')->only(['index', 'report', 'export', 'show', 'printRegister', 'pdfRegister', 'exportRegister']);
    }

    /**
     * Period list for a chosen date. A teacher sees only their own
     * periods; Super Admin / Admin get a class picker and a read-only
     * view of every period in that class.
     */
    public function index(Request $request)
    {
        $d['date'] = $this->dateFromRequest($request);
        $d['is_teacher'] = Qs::userIsTeacher();
        $d['classes'] = collect();
        $d['selected_class'] = null;

        if ($d['is_teacher']) {
            $d['periods'] = $this->periodsFor($d['date'], (int) $request->user()->id, null);
        } else {
            $d['classes'] = MyClass::orderBy('name')->get();
            $d['selected_class'] = $request->query('class_id')
                ? $d['classes']->firstWhere('id', (int) $request->query('class_id'))
                : $d['classes']->first();
            $d['periods'] = $d['selected_class']
                ? $this->periodsFor($d['date'], null, (int) $d['selected_class']->id)
                : collect();
        }

        $d['periods'] = $this->attachStudentCounts($d['periods']);
        $d['stats'] = $this->indexStats($d['periods']);

        return view('pages.support_team.attendance_periods.index', $d);
    }

    /**
     * Presentation aggregates for the index summary cards (Loop 77
     * redesign). Read-only: total / taken / pending count the very
     * rows the page lists, and the present percentage reuses the
     * report's rule — (present + late) over marked entries — across
     * the submitted sessions in view, from one grouped query.
     */
    protected function indexStats($periods): array
    {
        $total = $periods->count();
        $takenRows = $periods->filter(function ($row) {
            return !empty($row['session']);
        });
        $taken = $takenRows->count();

        $stats = [
            'total' => $total,
            'taken' => $taken,
            'pending' => $total - $taken,
            'taken_pct' => $total > 0 ? (int) round($taken / $total * 100) : 0,
            'pending_pct' => $total > 0 ? (int) round(($total - $taken) / $total * 100) : 0,
            'present_pct' => null,
            'present_count' => 0,
            'marked_count' => 0,
            // Teacher-flavour cards (Loop 77 addendum): how many
            // distinct subjects / classes the listed periods span.
            'subjects_today' => $periods->map(function ($row) {
                return optional($row['subject'])->id;
            })->filter()->unique()->count(),
            'classes_today' => $periods->map(function ($row) {
                return optional($row['class'])->id;
            })->filter()->unique()->count(),
        ];

        $sessionIds = $takenRows->map(function ($row) {
            return $row['session']->id;
        })->all();

        if ($sessionIds) {
            $counts = AttendanceSessionEntry::whereIn('session_id', $sessionIds)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status');

            $present = (int) ($counts[AttendanceSessionEntry::STATUS_PRESENT] ?? 0);
            $late = (int) ($counts[AttendanceSessionEntry::STATUS_LATE] ?? 0);
            $absent = (int) ($counts[AttendanceSessionEntry::STATUS_ABSENT] ?? 0);
            $marked = $present + $late + $absent;

            $stats['marked_count'] = $marked;
            $stats['present_count'] = $present + $late;
            if ($marked > 0) {
                $stats['present_pct'] = (int) round(($present + $late) / $marked * 100);
            }
        }

        return $stats;
    }

    /**
     * Per-row student figures for the schedule's Students column
     * (Loop 77): the roster size for every row — the section size on
     * section rows, the class size otherwise — and the marked
     * count (saved entries) for rows whose register was submitted.
     * Two grouped queries regardless of row count; read-only.
     */
    protected function attachStudentCounts($periods)
    {
        $classIds = $periods->map(function ($row) {
            return optional($row['class'])->id;
        })->filter()->unique()->all();

        $rosterCounts = $classIds
            ? StudentRecord::whereIn('my_class_id', $classIds)
                ->where('grad', 0)
                ->selectRaw('my_class_id, COUNT(*) as c')
                ->groupBy('my_class_id')
                ->pluck('c', 'my_class_id')
            : collect();

        // A section period's Students column counts that section
        // only — the class total on a section row misled teachers
        // into expecting the whole class in the register.
        $sectionCounts = $classIds
            ? StudentRecord::whereIn('my_class_id', $classIds)
                ->where('grad', 0)
                ->whereNotNull('section_id')
                ->selectRaw('section_id, COUNT(*) as c')
                ->groupBy('section_id')
                ->pluck('c', 'section_id')
            : collect();

        $sessionIds = $periods->filter(function ($row) {
            return !empty($row['session']);
        })->map(function ($row) {
            return $row['session']->id;
        })->all();

        $entryCounts = $sessionIds
            ? AttendanceSessionEntry::whereIn('session_id', $sessionIds)
                ->selectRaw('session_id, COUNT(*) as c')
                ->groupBy('session_id')
                ->pluck('c', 'session_id')
            : collect();

        return $periods->map(function ($row) use ($rosterCounts, $sectionCounts, $entryCounts) {
            $sectionId = optional($row['section'])->id;
            $row['students_total'] = $sectionId
                ? (int) ($sectionCounts[$sectionId] ?? 0)
                : (int) ($rosterCounts[optional($row['class'])->id] ?? 0);
            $row['students_marked'] = $row['session']
                ? (int) ($entryCounts[$row['session']->id] ?? 0)
                : null;

            return $row;
        });
    }

    /**
     * The take-attendance page for one period on one date. This page
     * only ever renders for an untaken period and only for that
     * period's own teacher; an already-submitted period redirects
     * to the index — submitted registers are gone from the
     * teacher's end (owner rule).
     */
    public function take(Request $request, $tt_id)
    {
        $period = $this->resolvePeriod((int) $tt_id);
        $date = $this->dateFromRequest($request);
        $this->guardDateMatchesPeriod($date, $period['tt']);

        $existing = $this->existingSession($date, $period['tt']->id);
        if ($existing) {
            // Owner rule: submitted registers are gone from the
            // teacher's end — no view-back, just the index.
            if (Qs::userIsTeacher()) {
                return redirect()->route('attendance.periods.index', ['date' => $date->format('Y-m-d')])
                    ->with('flash_success', 'Attendance for this period was already submitted.');
            }
            return redirect()->route('attendance.periods.show', $existing->id)
                ->with('flash_success', 'Attendance for this period was already submitted — the record is locked.');
        }

        $denied = $this->guardCanTake($request, $period);
        if ($denied) {
            return $denied;
        }

        $d['period'] = $period;
        $d['date'] = $date;
        // System roll numbers: the roster is admission-ordered, so
        // the row position IS the roll number — allocated by the
        // system, never typed in, identical on the page and in
        // every export/print of this roster.
        $students = $this->roster($period['class']->id, optional($period['section'])->id ?? null);
        $students->each(function ($student, $index) {
            $student->setAttribute('roll_no', $index + 1);
        });
        $d['students'] = $students;

        return view('pages.support_team.attendance_periods.take', $d);
    }

    /**
     * Submit a period register. Final: creates the session and one
     * entry per roster student in a single transaction, and refuses
     * (with a pointer to the locked record) when the period already
     * has a session for the date.
     */
    public function store(Request $request, $tt_id)
    {
        $period = $this->resolvePeriod((int) $tt_id);

        $data = $request->validate([
            'topic' => 'required|string|max:255',
            'date' => 'required|date_format:Y-m-d',
            'statuses' => 'required|array',
            'statuses.*' => 'required|in:present,absent,late',
        ]);

        $date = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();
        $this->guardDateMatchesPeriod($date, $period['tt']);

        if ($date->isFuture()) {
            throw ValidationException::withMessages([
                'date' => 'Attendance cannot be taken for a future date.',
            ]);
        }

        $denied = $this->guardCanTake($request, $period);
        if ($denied) {
            return $denied;
        }

        $existing = $this->existingSession($date, $period['tt']->id);
        if ($existing) {
            return redirect()->route('attendance.periods.index', ['date' => $date->toDateString()])
                ->with('flash_danger', 'Attendance for this period was already submitted and is locked — it cannot be taken again.');
        }

        $students = $this->roster($period['class']->id, optional($period['section'])->id ?? null);
        $rosterIds = $students->pluck('id')->all();
        $submittedIds = array_map('intval', array_keys($data['statuses']));
        sort($rosterIds);
        sort($submittedIds);
        if ($submittedIds !== $rosterIds) {
            throw ValidationException::withMessages([
                'statuses' => 'Every student on the class roster must be marked present, absent or late before submitting.',
            ]);
        }

        try {
            DB::transaction(function () use ($data, $date, $period, $students, $request) {
                $session = AttendanceSession::create([
                    'date' => $date->toDateString(),
                    'time_table_id' => $period['tt']->id,
                    'my_class_id' => $period['class']->id,
                    'section_id' => optional($period['section'])->id ?? null,
                    'subject_id' => $period['subject']->id,
                    'teacher_id' => $request->user()->id,
                    'topic' => $data['topic'],
                    'status' => AttendanceSession::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                ]);

                $now = now();
                $entries = $students->map(function ($student) use ($session, $data, $now) {
                    return [
                        'session_id' => $session->id,
                        'student_record_id' => $student->id,
                        'status' => $data['statuses'][$student->id],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                AttendanceSessionEntry::insert($entries);
            });
        } catch (QueryException $e) {
            // The (date, time_table_id) unique key fired — a register
            // for this period already exists (double submit / race).
            $existing = $this->existingSession($date, $period['tt']->id);
            if ($existing) {
                return redirect()->route('attendance.periods.index', ['date' => $date->toDateString()])
                    ->with('flash_danger', 'Attendance for this period was already submitted and is locked — it cannot be taken again.');
            }
            throw $e;
        }

        return redirect()
            ->route('attendance.periods.index', ['date' => $date->toDateString()])
            ->with('flash_success', 'Attendance submitted for ' . $period['subject']->name . ' (' . $period['class']->name . ') — ' . $date->format('d M Y') . '. It is now locked.');
    }

    /** A submitted session, read-only. The lock made visible. */
    public function show(Request $request, $session_id)
    {
        return view('pages.support_team.attendance_periods.show', $this->registerData($request, $session_id));
    }

    /**
     * The register as a printable v2 document (browser print view).
     * Same data and scoping as show(); the PDF below renders this
     * exact blade through dompdf, so screen print and PDF agree.
     */
    public function printRegister(Request $request, $session_id)
    {
        $d = $this->registerData($request, $session_id);
        $d['s'] = Setting::all()->flatMap(function ($s) {
            return [$s->type => $s->description];
        });

        return view('pages.support_team.attendance_periods.print', $d);
    }

    /* Map a stored upload URL (/storage/...) to a base64 data URI read
       from the local file, for renderers that cannot fetch URLs
       (dompdf). Anything that is not a local storage file is returned
       unchanged. (Same helper as MarkController/CircularController.) */
    protected function pdfImageDataUri($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && \Illuminate\Support\Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . \Illuminate\Support\Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }
        return $url;
    }

    /** The register document as a PDF download (dompdf). */
    public function pdfRegister(Request $request, $session_id)
    {
        $d = $this->registerData($request, $session_id);
        $d['s'] = Setting::all()->flatMap(function ($s) {
            return [$s->type => $s->description];
        });

        /* Documents Premium pass: mark the dompdf rendering so the
           blade hides its screen-only Print bar (dompdf ignores the
           @media print rule that hides it in the browser) and uses
           compact spacing; inline the Settings logo as a data URI —
           dompdf cannot fetch the URL, the browser print keeps it. */
        $d['for_pdf'] = true;
        if (!empty($d['s']['logo'])) {
            $d['s']['logo'] = $this->pdfImageDataUri($d['s']['logo']);
        }

        $session = $d['session'];
        $pdf_name = 'Attendance_Register_'
            . Str::slug(optional($session->subject)->name ?: 'period') . '_'
            . $session->date->format('Y-m-d') . '.pdf';

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.attendance_periods.print', $d)
            ->download($pdf_name);
    }

    /** The register's rows as CSV — same pattern as the report export. */
    public function exportRegister(Request $request, $session_id)
    {
        $d = $this->registerData($request, $session_id);
        $session = $d['session'];

        // registerData already attached the effective section (the
        // period's own section for legacy NULL-section sessions).
        $rolls = $this->rollMap($session->my_class_id, optional($session->section)->id ?? $session->section_id);
        $rows = $d['entries']->map(function ($entry) use ($rolls) {
            return [
                $rolls[$entry->student_record_id] ?? '—',
                optional(optional($entry->student_record)->user)->name,
                optional(optional($entry->student_record)->section)->name,
                optional($entry->student_record)->adm_no,
                ucfirst($entry->status),
            ];
        })->all();

        $filename = 'attendance-register-'
            . Str::slug(optional($session->my_class)->name ?: 'class') . '-'
            . Str::slug(optional($session->subject)->name ?: 'period') . '-'
            . $session->date->format('Y-m-d') . '.csv';

        return CsvExport::download($filename, ['Roll No', 'Student', 'Section', 'Admission No', 'Status'], $rows);
    }

    /**
     * System roll numbers for a class (or one section): the
     * admission-ordered roster position, 1-based. One definition
     * shared by the take page, the submitted register and every
     * roster export/print, so the number never disagrees.
     */
    protected function rollMap(int $classId, $sectionId = null): array
    {
        $map = [];
        foreach ($this->roster($classId, $sectionId) as $index => $student) {
            $map[$student->id] = $index + 1;
        }
        return $map;
    }

    /**
     * Roster outputs (take-page export/PDF): a teacher opens only
     * periods they teach; Admin / Super Admin oversight may open
     * any roster, like the submitted-register views.
     */
    protected function guardCanViewRoster(Request $request, array $period): void
    {
        if (Qs::userIsTeacher() && (int) optional($period['teacher'])->id !== (int) $request->user()->id) {
            abort(403, 'You can only export the roster of periods you teach.');
        }
    }

    /** The take-page roster as CSV — same rows and roll numbers as the page. */
    public function rosterExport(Request $request, $tt_id)
    {
        $period = $this->resolvePeriod((int) $tt_id);
        $this->guardCanViewRoster($request, $period);

        $students = $this->roster($period['class']->id, optional($period['section'])->id ?? null);
        $rows = $students->map(function ($student, $index) {
            return [
                $index + 1,
                optional($student->user)->name,
                optional($student->section)->name ?: '—',
                $student->adm_no ?: '—',
            ];
        })->all();

        $filename = 'class-roster-'
            . Str::slug(optional($period['class'])->name ?: 'class') . '-'
            . Str::slug(optional($period['subject'])->name ?: 'period') . '.csv';

        return CsvExport::download($filename, ['Roll No', 'Student', 'Section', 'Admission No'], $rows);
    }

    /** The take-page roster as a PDF (dompdf) — blank P/A/L boxes
     * for manual classroom use, with the same system roll numbers
     * as the page. */
    public function rosterPdf(Request $request, $tt_id)
    {
        $period = $this->resolvePeriod((int) $tt_id);
        $this->guardCanViewRoster($request, $period);

        $students = $this->roster($period['class']->id, optional($period['section'])->id ?? null);
        $students->each(function ($student, $index) {
            $student->setAttribute('roll_no', $index + 1);
        });

        $d['period'] = $period;
        $d['students'] = $students;
        $d['date'] = $this->dateFromRequest($request);
        $d['for_pdf'] = true;
        $d['s'] = Setting::all()->flatMap(function ($s) {
            return [$s->type => $s->description];
        });
        if (!empty($d['s']['logo'])) {
            $d['s']['logo'] = $this->pdfImageDataUri($d['s']['logo']);
        }

        $pdf_name = 'Class_Roster_'
            . Str::slug(optional($period['class'])->name ?: 'class') . '_'
            . Str::slug(optional($period['subject'])->name ?: 'period') . '.pdf';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.attendance_periods.roster_print', $d);
        // "Page X of Y" via canvas (dompdf renders the CSS pages
        // counter as 0 — see App\Support\PdfFooter).
        \App\Support\PdfFooter::stampPageNumbers($pdf, 'portrait');

        return $pdf->download($pdf_name);
    }

    /**
     * Shared data + scoping behind every submitted-register output
     * (page, print, PDF, CSV). A teacher opens only registers they
     * submitted — the same rule show() has always enforced; an
     * unknown id is a 404 via findOrFail.
     */
    protected function registerData(Request $request, $session_id): array
    {
        $session = AttendanceSession::with([
            'subject', 'my_class', 'section', 'teacher',
            'time_table.time_slot',
            'entries.student_record.user', 'entries.student_record.section',
        ])->findOrFail($session_id);

        // Owner rule: submitted registers are gone from the
        // teacher's end — once submitted, a teacher cannot view
        // them back, not even their own. Admin / Super Admin
        // oversight keeps full view access. This single guard
        // covers show, print, PDF and CSV, which all read through
        // registerData.
        if (Qs::userIsTeacher()) {
            abort(403, 'Submitted attendance registers are not available on teacher accounts.');
        }

        $d['session'] = $session;
        // A section period's register lists only that section — even
        // for sessions seeded before the seeder learned sections
        // (section_id NULL, entries covering the whole class). The
        // period's own section is the fallback, and its name is
        // attached so the header names it too.
        $effectiveSectionId = $session->section_id
            ?? optional(optional($session->time_table)->tt_record)->section_id;
        if (!$session->section && $effectiveSectionId) {
            $session->setRelation('section', Section::find($effectiveSectionId));
        }
        $entries = $session->entries;
        if ($effectiveSectionId) {
            $entries = $entries->filter(function ($entry) use ($effectiveSectionId) {
                return (int) optional($entry->student_record)->section_id === (int) $effectiveSectionId;
            });
        }
        $d['entries'] = $entries->sortBy(function ($entry) {
            return strtolower(optional(optional($entry->student_record)->user)->name ?? '');
        })->values();
        // Same system roll numbers as the take page, even though
        // this view sorts by name for reading.
        $rolls = $this->rollMap($session->my_class_id, $effectiveSectionId);
        foreach ($d['entries'] as $entry) {
            if ($entry->student_record) {
                $entry->student_record->setAttribute('roll_no', $rolls[$entry->student_record_id] ?? null);
            }
        }
        $d['counts'] = [
            'present' => $d['entries']->where('status', AttendanceSessionEntry::STATUS_PRESENT)->count(),
            'absent' => $d['entries']->where('status', AttendanceSessionEntry::STATUS_ABSENT)->count(),
            'late' => $d['entries']->where('status', AttendanceSessionEntry::STATUS_LATE)->count(),
            'total' => $d['entries']->count(),
        ];
        $slot = optional($session->time_table)->time_slot;
        $d['time_label'] = optional($slot)->full ?: '—';

        return $d;
    }

    /**
     * Subject-wise report: per-student attendance in one subject for
     * one class over a date range, plus the session log (date, time,
     * teacher, topic, present count) those numbers came from.
     * Percentage rule matches the day-wise report: (present + late)
     * over marked entries.
     */
    public function report(Request $request)
    {
        $d = $this->reportData($request);

        return view('pages.support_team.attendance_periods.report', $d);
    }

    /**
     * CSV export of the period-attendance SESSION LOG for the
     * requested scope — one row per submitted session (audit Fix
     * Wave 1). The old export silently reused the report's per-student
     * summary AND the report's defaulted class/subject, so the bare
     * URL produced 3 student rows that represented none of the 36
     * seeded sessions. Now the filters mean what they say: class_id
     * and subject_id restrict the scope ONLY when actually supplied
     * (no silent defaulting); from/to default to the current month,
     * exactly like the report. The filename names the scope it
     * carries ('all-classes' when unscoped), and the row count always
     * equals the number of sessions in the scope.
     */
    public function export(Request $request)
    {
        $from = $this->dateParam($request, 'from') ?: Carbon::today()->startOfMonth();
        $to = $this->dateParam($request, 'to') ?: Carbon::today();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $class = $request->query('class_id')
            ? MyClass::find((int) $request->query('class_id'))
            : null;

        $subject = null;
        if ($request->query('subject_id')) {
            $candidate = Subject::find((int) $request->query('subject_id'));
            // A subject outside the chosen class would empty the file;
            // fall back to the class scope instead of exporting nothing.
            if ($candidate && (!$class || (int) $candidate->my_class_id === (int) $class->id)) {
                $subject = $candidate;
            }
        }

        // Inclusive calendar bounds via whereDate: the shared
        // betweenDates scope string-compares the datetime column
        // against 'Y-m-d', which silently drops the final day's
        // sessions (their stored value carries a time component) —
        // an export "to 2026-10-23" must contain 2026-10-23.
        $query = AttendanceSession::with(['my_class', 'subject', 'teacher', 'time_table.time_slot', 'entries'])
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->orderBy('date')
            ->orderBy('id');
        if ($class) {
            $query->forClass($class->id);
        }
        if ($subject) {
            $query->forSubject($subject->id);
        }
        $sessions = $query->get();

        $rows = $sessions->map(function ($session) {
            return [
                $session->date->format('Y-m-d'),
                optional($session->my_class)->name,
                optional($session->subject)->name,
                optional(optional($session->time_table)->time_slot)->full ?: '—',
                optional($session->teacher)->name,
                $session->topic,
                $session->countByStatus(AttendanceSessionEntry::STATUS_PRESENT),
                $session->countByStatus(AttendanceSessionEntry::STATUS_ABSENT),
                $session->countByStatus(AttendanceSessionEntry::STATUS_LATE),
                $session->entries->count(),
            ];
        })->all();

        $scope = $class
            ? Str::slug($class->name) . ($subject ? '-' . Str::slug($subject->name) : '-all-subjects')
            : 'all-classes';
        $filename = 'period-attendance-sessions-'
            . $scope . '-'
            . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv';

        return CsvExport::download($filename,
            ['Date', 'Class', 'Subject', 'Time', 'Teacher', 'Topic Taught', 'Present', 'Absent', 'Late', 'Students Marked'],
            $rows);
    }

    /** Shared computation behind report() and export(). */
    protected function reportData(Request $request): array
    {
        $d['classes'] = MyClass::orderBy('name')->get();
        $d['selected_class'] = $request->query('class_id')
            ? $d['classes']->firstWhere('id', (int) $request->query('class_id'))
            : $d['classes']->first();

        $d['subjects'] = $d['selected_class']
            ? Subject::where('my_class_id', $d['selected_class']->id)->orderBy('name')->get()
            : collect();
        $d['selected_subject'] = $request->query('subject_id')
            ? $d['subjects']->firstWhere('id', (int) $request->query('subject_id'))
            : $d['subjects']->first();

        $d['from'] = $this->dateParam($request, 'from') ?: Carbon::today()->startOfMonth();
        $d['to'] = $this->dateParam($request, 'to') ?: Carbon::today();
        if ($d['to']->lt($d['from'])) {
            [$d['from'], $d['to']] = [$d['to'], $d['from']];
        }

        $d['rows'] = collect();
        $d['log'] = collect();
        $d['held'] = 0;

        if ($d['selected_class'] && $d['selected_subject']) {
            $sessions = AttendanceSession::with(['teacher', 'time_table.time_slot', 'entries'])
                ->forClass($d['selected_class']->id)
                ->forSubject($d['selected_subject']->id)
                ->betweenDates($d['from'], $d['to'])
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            $d['held'] = $sessions->count();

            $entriesByStudent = AttendanceSessionEntry::whereIn('session_id', $sessions->pluck('id')->all())
                ->get()
                ->groupBy('student_record_id');

            $students = $this->roster((int) $d['selected_class']->id);
            $d['rows'] = $students->map(function ($student) use ($entriesByStudent, $d) {
                $entries = $entriesByStudent->get($student->id, collect());
                $present = $entries->where('status', AttendanceSessionEntry::STATUS_PRESENT)->count();
                $absent = $entries->where('status', AttendanceSessionEntry::STATUS_ABSENT)->count();
                $late = $entries->where('status', AttendanceSessionEntry::STATUS_LATE)->count();
                $marked = $present + $absent + $late;

                return [
                    'student' => $student,
                    'held' => $d['held'],
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'marked' => $marked,
                    'percent' => $marked > 0 ? (int) round(($present + $late) / $marked * 100) : null,
                ];
            });

            $d['log'] = $sessions->map(function ($session) {
                return [
                    'session' => $session,
                    'date' => $session->date,
                    'time_label' => optional(optional($session->time_table)->time_slot)->full ?: '—',
                    'teacher_name' => optional($session->teacher)->name ?: '—',
                    'topic' => $session->topic,
                    'present' => $session->countByStatus(AttendanceSessionEntry::STATUS_PRESENT),
                    'total' => $session->entries->count(),
                ];
            })->sortByDesc(function ($row) {
                return $row['date']->format('Y-m-d') . '-' . str_pad((string) $row['session']->id, 10, '0', STR_PAD_LEFT);
            })->values();
        }

        return $d;
    }

    /**
     * The periods of one weekday, resolved exactly like the teacher
     * home: weekday TimeTable rows through Subject.teacher_id, with
     * any already-submitted session attached.
     *
     * @param int|null $teacherId restrict to one teacher's subjects
     * @param int|null $classId   restrict to one class's timetable
     */
    protected function periodsFor(Carbon $date, ?int $teacherId, ?int $classId)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('time_tables')) {
            return collect();
        }

        $query = TimeTable::with(['subject.my_class', 'subject.teacher', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
            ->where('day', $date->format('l'))
            ->whereNotNull('subject_id');

        if ($teacherId !== null) {
            // Section build: a teacher's periods include cells they
            // teach via a section override, not only as class teacher.
            SectionTeacher::applyTeacherFilter($query, $teacherId);
        }
        if ($classId !== null) {
            $query->whereHas('tt_record', function ($q) use ($classId) {
                $q->where('my_class_id', $classId);
            });
        }

        $periods = $query->get();

        $sessions = AttendanceSession::whereDate('date', $date->toDateString())
            ->whereIn('time_table_id', $periods->pluck('id')->all())
            ->get()
            ->keyBy('time_table_id');

        // Wave 1 feature 4 (Substitution, additive only): attach the
        // day's substitution per period, if the table exists yet.
        $subs = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('substitutions')) {
            $subs = Substitution::with('substitute')
                ->whereDate('date', $date->toDateString())
                ->whereIn('time_table_id', $periods->pluck('id')->all())
                ->get()
                ->keyBy('time_table_id');
        }

        return $periods
            ->map(function (TimeTable $tt) use ($sessions, $subs, $date) {
                $slot = $tt->time_slot;
                $class = optional($tt->tt_record)->my_class ?: optional($tt->subject)->my_class;
                $start = ($slot && $slot->time_from)
                    ? Carbon::parse($date->toDateString() . ' ' . $slot->time_from)
                    : null;
                $teacher = SectionTeacher::teacherFor(optional($tt->tt_record)->section_id ?? null, $tt->subject);
                $substitution = $subs->get($tt->id);

                return [
                    'tt' => $tt,
                    'subject' => $tt->subject,
                    'class' => $class,
                    'section' => optional($tt->tt_record)->section,
                    'teacher' => $teacher,
                    'time_label' => optional($slot)->full ?: '—',
                    'sort' => $start ? $start->format('H:i') : '99:99',
                    'session' => $sessions->get($tt->id),
                    'substitution' => $substitution,
                    'effective_teacher' => $substitution && $substitution->substitute
                        ? $substitution->substitute
                        : $teacher,
                ];
            })
            ->filter(function ($row) {
                return $row['subject'] && $row['class'];
            })
            ->sortBy('sort')
            ->values();
    }

    /** One period by timetable id, in the same shape periodsFor returns. */
    protected function resolvePeriod(int $ttId): array
    {
        $tt = TimeTable::with(['subject.my_class', 'subject.teacher', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
            ->findOrFail($ttId);

        $class = optional($tt->tt_record)->my_class ?: optional($tt->subject)->my_class;
        if (!$tt->subject || !$class) {
            abort(404, 'This timetable entry has no subject or class.');
        }

        return [
            'tt' => $tt,
            'subject' => $tt->subject,
            'class' => $class,
            'section' => optional($tt->tt_record)->section,
            'teacher' => SectionTeacher::teacherFor(optional($tt->tt_record)->section_id ?? null, $tt->subject),
            'time_label' => optional($tt->time_slot)->full ?: '—',
        ];
    }

    /** Active (non-graduated) students of a class — or of one section
        when a section period is being taken, so a section register
        never lists the whole class. */
    protected function roster(int $classId, $sectionId = null)
    {
        return StudentRecord::with('user', 'section')
            ->where('my_class_id', $classId)
            ->when($sectionId, function ($q) use ($sectionId) {
                $q->where('section_id', $sectionId);
            })
            ->where('grad', 0)
            ->orderBy('id')
            ->get();
    }

    protected function existingSession(Carbon $date, int $timeTableId)
    {
        return AttendanceSession::whereDate('date', $date->toDateString())
            ->where('time_table_id', $timeTableId)
            ->first();
    }

    /**
     * Only the period's own teacher may take its attendance.
     * Super Admin / Admin are oversight — they view, they do not
     * submit. Returns a redirect when the user may not take it,
     * null when they may.
     */
    protected function guardCanTake(Request $request, array $period)
    {
        if (!Qs::userIsTeacher()) {
            return redirect()
                ->route('attendance.periods.index', ['date' => $this->dateFromRequest($request)->toDateString()])
                ->with('flash_danger', 'Period attendance is submitted by the subject teacher — this view is read-only for administrators.');
        }

        if ((int) SectionTeacher::teacherIdFor(optional($period['section'])->id ?? null, $period['subject']) !== (int) $request->user()->id) {
            abort(403, 'You can only take attendance for your own periods.');
        }

        return null;
    }

    /** A register only makes sense on the weekday the period runs. */
    protected function guardDateMatchesPeriod(Carbon $date, TimeTable $tt): void
    {
        if ($tt->day && $date->format('l') !== $tt->day) {
            throw ValidationException::withMessages([
                'date' => 'This period runs on ' . $tt->day . ' — ' . $date->format('d M Y') . ' is a ' . $date->format('l') . '.',
            ]);
        }
    }

    protected function dateFromRequest(Request $request): Carbon
    {
        return $this->dateParam($request, 'date') ?: Carbon::today();
    }

    protected function dateParam(Request $request, string $key): ?Carbon
    {
        $value = $request->query($key) ?: $request->input($key);
        if (!$value || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Exception $e) {
            return null;
        }
    }
}
