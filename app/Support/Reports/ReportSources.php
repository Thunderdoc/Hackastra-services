<?php

namespace App\Support\Reports;

use App\Models\Admission;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\Book;
use App\Models\BookRequest;
use App\Models\Certificate;
use App\Models\Circular;
use App\Models\Exam;
use App\Models\ExamSubjectComponent;
use App\Models\LibraryFine;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTableRecord;
use App\Support\FeeDefaulter;
use App\Support\MarkScheme;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Whitelisted report data sources (wave 31b, Universal Report Builder).
 *
 * The builder NEVER lets a user pick a table, a raw query, or an
 * arbitrary column: every source key below is a fixed definition with
 * a fixed column list and a fixed resolver. Anything not listed here
 * is refused before any query runs. Role gates live here too, so the
 * controller, the engine, and the builder UI can never disagree about
 * which source a role may use.
 *
 * Row values are RAW (strings, numbers, nulls). Display formatting
 * (dates, currency) happens in ReportEngine, never here, so totals
 * always sum the real numbers.
 */
class ReportSources
{
    /** Source key => [label, roles, columns[key => [label, type, weight]]]. */
    public static function definitions(): array
    {
        return [
            'students' => [
                'label' => 'Students',
                'roles' => ['teacher', 'admin'],
                'columns' => [
                    'roll_no' => ['Roll No', 'number', 8],
                    'student' => ['Student', 'text', 26],
                    'adm_no' => ['Admission No', 'text', 16],
                    'class' => ['Class', 'text', 12],
                    'section' => ['Section', 'text', 8],
                    'gender' => ['Gender', 'text', 8],
                    'dob' => ['Date of Birth', 'date', 12],
                    'phone' => ['Phone', 'text', 14],
                    'parent' => ['Parent/Guardian', 'text', 20],
                    'house' => ['House', 'text', 10],
                ],
            ],
            'marks' => [
                'label' => 'Marks',
                'roles' => ['teacher', 'admin'],
                'columns' => [
                    'roll_no' => ['Roll No', 'number', 8],
                    'student' => ['Student', 'text', 24],
                    'adm_no' => ['Admission No', 'text', 15],
                    'class' => ['Class', 'text', 12],
                    'section' => ['Section', 'text', 8],
                    'subject' => ['Subject', 'text', 18],
                    'exam' => ['Exam', 'text', 18],
                    'total' => ['Total', 'number', 10],
                    'grade' => ['Grade', 'text', 10],
                    'position' => ['Position', 'number', 10],
                ],
            ],
            'attendance' => [
                'label' => 'Attendance (submitted registers)',
                'roles' => ['teacher', 'admin'],
                'columns' => [
                    'date' => ['Date', 'date', 12],
                    'student' => ['Student', 'text', 24],
                    'adm_no' => ['Admission No', 'text', 15],
                    'class' => ['Class', 'text', 12],
                    'section' => ['Section', 'text', 8],
                    'subject' => ['Subject', 'text', 18],
                    'topic' => ['Topic', 'text', 22],
                    'status' => ['Status', 'text', 10],
                    'roll_no' => ['Roll No', 'number', 8],
                ],
            ],
            'assignments' => [
                'label' => 'Assignments',
                'roles' => ['admin'],
                'columns' => [
                    'title' => ['Title', 'text', 26],
                    'class' => ['Class', 'text', 12],
                    'section' => ['Section', 'text', 8],
                    'subject' => ['Subject', 'text', 18],
                    'teacher' => ['Teacher', 'text', 20],
                    'due_at' => ['Due On', 'date', 12],
                    'max_marks' => ['Max Marks', 'number', 10],
                    'submissions' => ['Submissions', 'number', 10],
                ],
            ],
            'fees' => [
                'label' => 'Fee Records',
                'roles' => ['accountant', 'admin'],
                'columns' => [
                    'student' => ['Student', 'text', 22],
                    'adm_no' => ['Admission No', 'text', 14],
                    'class' => ['Class', 'text', 11],
                    'fee_title' => ['Fee Title', 'text', 20],
                    'amount' => ['Amount', 'currency', 12],
                    'amt_paid' => ['Paid', 'currency', 12],
                    'balance' => ['Balance', 'currency', 12],
                    'status' => ['Status', 'text', 10],
                    'ref_no' => ['Receipt Ref', 'text', 14],
                    'date' => ['Date', 'date', 12],
                ],
            ],
            'defaulters' => [
                'label' => 'Fee Defaulters',
                'roles' => ['accountant', 'admin'],
                'columns' => [
                    'student' => ['Student', 'text', 22],
                    'adm_no' => ['Admission No', 'text', 14],
                    'class' => ['Class', 'text', 11],
                    'section' => ['Section', 'text', 8],
                    'total_due' => ['Amount Due', 'currency', 13],
                    'total_paid' => ['Paid', 'currency', 12],
                    'status' => ['Status', 'text', 10],
                    'items' => ['Unpaid Items', 'number', 10],
                    'oldest_title' => ['Oldest Fee', 'text', 20],
                ],
            ],
            'fines' => [
                'label' => 'Library Fines',
                'roles' => ['accountant', 'librarian', 'admin'],
                'columns' => [
                    'student' => ['Student', 'text', 22],
                    'book' => ['Book', 'text', 24],
                    'amount' => ['Fine', 'currency', 12],
                    'days_overdue' => ['Days Overdue', 'number', 10],
                    'reason' => ['Reason', 'text', 22],
                    'status' => ['Status', 'text', 10],
                    'imposed_by' => ['Imposed By', 'text', 18],
                ],
            ],
            'books' => [
                'label' => 'Books',
                'roles' => ['librarian', 'admin'],
                'columns' => [
                    'name' => ['Book Title', 'text', 28],
                    'class' => ['Class', 'text', 11],
                    'author' => ['Author', 'text', 20],
                    'book_type' => ['Type', 'text', 12],
                    'location' => ['Location', 'text', 14],
                    'total_copies' => ['Total', 'number', 8],
                    'issued_copies' => ['Issued', 'number', 8],
                    'available' => ['Available', 'number', 9],
                ],
            ],
            'issues' => [
                'label' => 'Book Issues / Returns',
                'roles' => ['librarian', 'admin'],
                'columns' => [
                    'book' => ['Book', 'text', 26],
                    'borrower' => ['Borrower', 'text', 22],
                    'start_date' => ['Issued On', 'date', 12],
                    'end_date' => ['Due On', 'date', 12],
                    'status' => ['Status', 'text', 11],
                    'returned' => ['Returned', 'text', 10],
                ],
            ],
            'staff' => [
                'label' => 'Staff',
                'roles' => ['admin'],
                'columns' => [
                    'name' => ['Name', 'text', 24],
                    'email' => ['Email', 'text', 24],
                    'phone' => ['Phone', 'text', 14],
                    'user_type' => ['Role', 'text', 12],
                    'code' => ['Staff Code', 'text', 12],
                    'emp_date' => ['Joined', 'date', 12],
                ],
            ],
            'admissions' => [
                'label' => 'Admission Enquiries',
                'roles' => ['admin'],
                'columns' => [
                    'name' => ['Name', 'text', 24],
                    'phone' => ['Phone', 'text', 14],
                    'class' => ['Class', 'text', 12],
                    'status' => ['Status', 'text', 13],
                    'session' => ['Session', 'text', 12],
                ],
            ],
            'certificates' => [
                'label' => 'Certificates Issued',
                'roles' => ['admin'],
                'columns' => [
                    'student_name' => ['Student', 'text', 22],
                    'adm_no' => ['Admission No', 'text', 14],
                    'class_name' => ['Class', 'text', 11],
                    'section_name' => ['Section', 'text', 8],
                    'type' => ['Type', 'text', 12],
                    'serial' => ['Serial', 'text', 16],
                    'session' => ['Session', 'text', 11],
                    'issued_at' => ['Issued On', 'date', 12],
                ],
            ],
            'classes' => [
                'label' => 'Classes',
                'roles' => ['admin'],
                'columns' => [
                    'name' => ['Class', 'text', 20],
                    'sections' => ['Sections', 'number', 12],
                    'students' => ['Students', 'number', 12],
                ],
            ],
            'circulars' => [
                'label' => 'Circulars',
                'roles' => ['admin'],
                'columns' => [
                    'title' => ['Title', 'text', 30],
                    'audience' => ['Audience', 'text', 14],
                    'priority' => ['Priority', 'text', 10],
                    'published' => ['Published', 'text', 10],
                    'starts_at' => ['Starts', 'date', 12],
                    'expires_at' => ['Expires', 'date', 12],
                ],
            ],
            'timetables' => [
                'label' => 'Timetables',
                'roles' => ['admin'],
                'columns' => [
                    'name' => ['Timetable', 'text', 26],
                    'class' => ['Class', 'text', 12],
                    'section' => ['Section', 'text', 8],
                    'exam' => ['Exam', 'text', 20],
                    'year' => ['Session', 'text', 12],
                ],
            ],
            'fee_summary' => [
                'label' => 'Fee Summary (per fee)',
                'roles' => ['accountant', 'admin'],
                'columns' => [
                    'fee_title' => ['Fee Title', 'text', 24],
                    'class' => ['Class', 'text', 12],
                    'records' => ['Records', 'number', 10],
                    'invoiced' => ['Invoiced', 'currency', 14],
                    'collected' => ['Collected', 'currency', 14],
                    'outstanding' => ['Outstanding', 'currency', 14],
                ],
            ],
            'attendance_summary' => [
                'label' => 'Attendance Summary',
                'roles' => ['admin'],
                'columns' => [
                    'class' => ['Class', 'text', 12],
                    'subject' => ['Subject', 'text', 20],
                    'sessions' => ['Sessions', 'number', 10],
                    'present' => ['Present', 'number', 10],
                    'absent' => ['Absent', 'number', 10],
                    'late' => ['Late', 'number', 10],
                    'percentage' => ['Attendance %', 'number', 12],
                ],
            ],
            'results_summary' => [
                'label' => 'Results Summary (per exam + class)',
                'roles' => ['admin'],
                'columns' => [
                    'exam' => ['Exam', 'text', 24],
                    'class' => ['Class', 'text', 12],
                    'students' => ['Students', 'number', 10],
                    'average_total' => ['Average Total', 'number', 14],
                ],
            ],
            'library_summary' => [
                'label' => 'Library Summary (per type)',
                'roles' => ['librarian', 'admin'],
                'columns' => [
                    'book_type' => ['Type', 'text', 20],
                    'titles' => ['Titles', 'number', 10],
                    'copies' => ['Copies', 'number', 10],
                    'issued' => ['Issued', 'number', 10],
                    'available' => ['Available', 'number', 10],
                ],
            ],
            'platform' => [
                'label' => 'Platform Metrics',
                'roles' => ['super_admin'],
                'columns' => [
                    'metric' => ['Metric', 'text', 40],
                    'value' => ['Value', 'number', 20],
                ],
            ],
            'blank' => [
                'label' => 'Blank Sheet (manual writing)',
                'roles' => ['teacher', 'accountant', 'librarian', 'admin'],
                'columns' => [],
            ],
        ];
    }

    /** Source keys the given user type may use ([] for parent/student). */
    public static function allowedFor(string $userType): array
    {
        // Roles audit round 13 (F-R13-1): the limited principal role
        // maps to 'admin' here exactly as ReportPresets::forRole
        // already maps it for presets. Without this, the principal —
        // whose sidebar links "Reports & Analytics" to the builder —
        // could open the builder but got 403 on EVERY generate,
        // because no source listed 'principal' in its roles. The
        // platform source stays super_admin-only: its roles list is
        // untouched, and this mapping only renames the lookup key.
        if ($userType === 'principal') {
            $userType = 'admin';
        }
        $out = [];
        foreach (self::definitions() as $key => $def) {
            if (in_array($userType, $def['roles'], true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** Definition for one source, or null when the key is not whitelisted. */
    public static function definition(string $source): ?array
    {
        return self::definitions()[$source] ?? null;
    }

    /** True when the role may use the source. */
    public static function allowed(string $source, string $userType): bool
    {
        return in_array($source, self::allowedFor($userType), true);
    }

    /** Roll numbers for a class (or one section): roster position, 1-based. */
    public static function rollByUserId(?int $classId, $sectionId = null): array
    {
        if (!$classId) {
            return [];
        }
        $records = StudentRecord::where('my_class_id', $classId)
            ->where('grad', 0)
            ->when($sectionId, function ($q) use ($sectionId) {
                return $q->where('section_id', $sectionId);
            })
            ->orderBy('id')
            ->get(['user_id']);
        $map = [];
        foreach ($records as $i => $sr) {
            $map[(int) $sr->user_id] = $i + 1;
        }
        return $map;
    }

    /**
     * Resolve a source to raw rows for the given filters + user.
     * Scope assertions live here: a teacher outside their classes, or
     * asking for a subject that is not theirs, gets an exception the
     * controller turns into a 403 — never a silently narrowed report.
     *
     * @throws \InvalidArgumentException on missing required filters
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException on scope violations
     */
    public static function rows(string $source, array $filters, $user): array
    {
        $type = $user->user_type ?? null;
        if (!self::allowed($source, $type)) {
            abort(403, 'This report source is not available for your role.');
        }

        switch ($source) {
            case 'students':
                return self::studentRows($filters, $user);
            case 'marks':
                return self::markRows($filters, $user);
            case 'attendance':
                return self::attendanceRows($filters, $user);
            case 'assignments':
                return self::assignmentRows($filters);
            case 'fees':
                return self::feeRows($filters);
            case 'defaulters':
                return self::defaulterRows($filters);
            case 'fines':
                return self::fineRows($filters);
            case 'books':
                return self::bookRows($filters);
            case 'issues':
                return self::issueRows($filters);
            case 'staff':
                return self::staffRows($filters);
            case 'admissions':
                return self::admissionRows($filters);
            case 'certificates':
                return self::certificateRows($filters);
            case 'classes':
                return self::classRows();
            case 'circulars':
                return self::circularRows($filters);
            case 'timetables':
                return self::timetableRows($filters);
            case 'fee_summary':
                return self::feeSummaryRows($filters);
            case 'attendance_summary':
                return self::attendanceSummaryRows($filters);
            case 'results_summary':
                return self::resultsSummaryRows($filters);
            case 'library_summary':
                return self::librarySummaryRows();
            case 'platform':
                return self::platformRows();
            case 'blank':
                return [];
            default:
                abort(403, 'Unknown report source.');
        }
    }

    /** Teacher class scope: filter ids to the teacher's classes, or 403 a foreign class. */
    protected static function teacherClassIds($user, array $filters): ?array
    {
        if (!TeacherScope::appliesTo($user)) {
            return null;
        }
        $ids = TeacherScope::classIds($user);
        if (!empty($filters['class_id']) && !in_array((int) $filters['class_id'], $ids, true)) {
            abort(403, 'That class is not assigned to you.');
        }
        return $ids;
    }

    protected static function studentRows(array $filters, $user): array
    {
        $scopeIds = self::teacherClassIds($user, $filters);
        $records = StudentRecord::with(['user', 'my_class', 'section', 'my_parent'])
            ->where('grad', 0)
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->when($scopeIds !== null, function ($q) use ($scopeIds) {
                return $q->whereIn('my_class_id', $scopeIds);
            })
            ->when(!empty($filters['section_id']), function ($q) use ($filters) {
                return $q->where('section_id', $filters['section_id']);
            })
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($records as $i => $sr) {
            $rows[] = [
                'roll_no' => $i + 1,
                'student' => optional($sr->user)->name,
                'adm_no' => $sr->adm_no,
                'class' => optional($sr->my_class)->name,
                'section' => optional($sr->section)->name,
                'gender' => optional($sr->user)->gender,
                'dob' => optional($sr->user)->dob,
                'phone' => optional($sr->user)->phone,
                'parent' => optional($sr->my_parent)->name,
                'house' => $sr->house,
            ];
        }
        return $rows;
    }

    protected static function markRows(array $filters, $user): array
    {
        if (empty($filters['exam_id']) || empty($filters['class_id']) || empty($filters['subject_id'])) {
            throw new \InvalidArgumentException('Marks reports require an exam, a class and a subject.');
        }
        $exam = Exam::find($filters['exam_id']);
        $subject = Subject::find($filters['subject_id']);
        if (!$exam || !$subject) {
            throw new \InvalidArgumentException('Unknown exam or subject.');
        }
        if (TeacherScope::appliesTo($user)) {
            if (!TeacherScope::canTeachClass($user, $filters['class_id'])) {
                abort(403, 'That class is not assigned to you.');
            }
            $ownsSubject = (int) $subject->teacher_id === (int) $user->id
                || DB::table('section_subject_teachers')
                    ->where('subject_id', $subject->id)
                    ->where('teacher_id', $user->id)
                    ->exists();
            if (!$ownsSubject) {
                abort(403, 'That subject is not assigned to you.');
            }
        }

        $rolls = self::rollByUserId((int) $filters['class_id'], $filters['section_id'] ?? null);
        $marks = Mark::with(['user', 'section', 'grade', 'markComponents'])
            ->where('exam_id', $filters['exam_id'])
            ->where('my_class_id', $filters['class_id'])
            ->where('subject_id', $filters['subject_id'])
            ->when(!empty($filters['section_id']), function ($q) use ($filters) {
                return $q->where('section_id', $filters['section_id']);
            })
            ->get()
            ->sortBy(function ($m) {
                return optional($m->user)->name;
            });

        $components = MarkScheme::components($exam->id, $subject->id);
        $className = optional(MyClass::find($filters['class_id']))->name;
        $rows = [];
        foreach ($marks as $m) {
            $row = [
                'roll_no' => $rolls[(int) $m->student_id] ?? null,
                'student' => optional($m->user)->name,
                'adm_no' => optional(optional($m->user)->student_record)->adm_no,
                'class' => $className,
                'section' => optional($m->section)->name,
                'subject' => $subject->name,
                'exam' => $exam->name,
                'total' => MarkScheme::subjectTotal($m),
                'grade' => optional($m->grade)->name,
                'position' => $m->sub_pos,
            ];
            foreach ($components as $c) {
                $row['comp_' . $c['id']] = MarkScheme::scoreFor($m, $c);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Configured component columns for a marks report (appended by the engine). */
    public static function markComponentColumns(array $filters): array
    {
        if (empty($filters['exam_id']) || empty($filters['subject_id'])) {
            return [];
        }
        if (!MarkScheme::isConfigured($filters['exam_id'], $filters['subject_id'])) {
            return [];
        }
        $out = [];
        foreach (MarkScheme::components($filters['exam_id'], $filters['subject_id']) as $c) {
            $out[] = ['key' => 'comp_' . $c['id'], 'label' => $c['name'], 'type' => 'number', 'width' => 10];
        }
        return $out;
    }

    protected static function attendanceRows(array $filters, $user): array
    {
        $sessions = AttendanceSession::with(['subject', 'my_class', 'section', 'entries.student_record.user', 'entries.student_record.section'])
            ->when(TeacherScope::appliesTo($user), function ($q) use ($user) {
                return $q->where('teacher_id', $user->id);
            })
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->when(!empty($filters['section_id']), function ($q) use ($filters) {
                return $q->where('section_id', $filters['section_id']);
            })
            ->when(!empty($filters['subject_id']), function ($q) use ($filters) {
                return $q->where('subject_id', $filters['subject_id']);
            })
            ->when(!empty($filters['date_from']), function ($q) use ($filters) {
                return $q->whereDate('date', '>=', $filters['date_from']);
            })
            ->when(!empty($filters['date_to']), function ($q) use ($filters) {
                return $q->whereDate('date', '<=', $filters['date_to']);
            })
            ->orderBy('date')
            ->get();

        $rows = [];
        foreach ($sessions as $s) {
            $rolls = self::rollByUserId((int) $s->my_class_id, $s->section_id);
            foreach ($s->entries as $e) {
                $sr = $e->student_record;
                $rows[] = [
                    'date' => $s->date,
                    'student' => optional(optional($sr)->user)->name,
                    'adm_no' => optional($sr)->adm_no,
                    'class' => optional($s->my_class)->name,
                    'section' => optional(optional($sr)->section)->name,
                    'subject' => optional($s->subject)->name,
                    'topic' => $s->topic,
                    'status' => ucfirst($e->status),
                    'roll_no' => $sr ? ($rolls[(int) $sr->user_id] ?? null) : null,
                ];
            }
        }
        return $rows;
    }

    protected static function assignmentRows(array $filters): array
    {
        $assignments = Assignment::with(['my_class', 'section', 'subject', 'teacher'])
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->when(!empty($filters['section_id']), function ($q) use ($filters) {
                return $q->where('section_id', $filters['section_id']);
            })
            ->when(!empty($filters['subject_id']), function ($q) use ($filters) {
                return $q->where('subject_id', $filters['subject_id']);
            })
            ->when(!empty($filters['date_from']), function ($q) use ($filters) {
                return $q->whereDate('due_at', '>=', $filters['date_from']);
            })
            ->when(!empty($filters['date_to']), function ($q) use ($filters) {
                return $q->whereDate('due_at', '<=', $filters['date_to']);
            })
            ->orderBy('due_at')
            ->get();

        return $assignments->map(function ($a) {
            return [
                'title' => $a->title,
                'class' => optional($a->my_class)->name,
                'section' => optional($a->section)->name,
                'subject' => optional($a->subject)->name,
                'teacher' => optional($a->teacher)->name,
                'due_at' => $a->due_at,
                'max_marks' => $a->max_marks !== null ? (float) $a->max_marks : null,
                'submissions' => AssignmentSubmission::where('assignment_id', $a->id)->count(),
            ];
        })->all();
    }

    protected static function feeRows(array $filters): array
    {
        // Class/section narrow the query itself (student_id is a user
        // id, resolved through StudentRecord) — never load every
        // record of the session and filter in PHP.
        $scopedUserIds = null;
        if (!empty($filters['class_id']) || !empty($filters['section_id'])) {
            $scopedUserIds = StudentRecord::query()
                ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                    return $q->where('my_class_id', $filters['class_id']);
                })
                ->when(!empty($filters['section_id']), function ($q) use ($filters) {
                    return $q->where('section_id', $filters['section_id']);
                })
                ->pluck('user_id')->all();
        }
        $records = PaymentRecord::with(['payment', 'student'])
            ->when($scopedUserIds !== null, function ($q) use ($scopedUserIds) {
                return $q->whereIn('student_id', $scopedUserIds);
            })
            ->when(!empty($filters['year']), function ($q) use ($filters) {
                return $q->where('year', $filters['year']);
            })
            ->when(!empty($filters['date_from']), function ($q) use ($filters) {
                return $q->whereDate('created_at', '>=', $filters['date_from']);
            })
            ->when(!empty($filters['date_to']), function ($q) use ($filters) {
                return $q->whereDate('created_at', '<=', $filters['date_to']);
            })
            ->orderBy('id')
            ->get();

        $rows = [];
        $srByUser = [];
        if (!empty($records)) {
            $srs = StudentRecord::with('my_class')->whereIn('user_id', $records->pluck('student_id')->unique()->all())->get();
            foreach ($srs as $sr) {
                $srByUser[(int) $sr->user_id] = $sr;
            }
        }
        foreach ($records as $r) {
            $sr = $srByUser[(int) $r->student_id] ?? null;
            $rows[] = [
                'student' => optional($r->student)->name,
                'adm_no' => optional($sr)->adm_no,
                'class' => optional(optional($sr)->my_class)->name,
                'fee_title' => optional($r->payment)->title,
                'amount' => optional($r->payment)->amount,
                'amt_paid' => $r->amt_paid,
                'balance' => $r->balance,
                'status' => $r->paid ? 'Paid' : 'Owing',
                'ref_no' => $r->ref_no,
                'date' => $r->created_at,
            ];
        }
        return $rows;
    }

    protected static function defaulterRows(array $filters): array
    {
        $year = $filters['year'] ?? \App\Helpers\Qs::getCurrentSession();
        $class = !empty($filters['class_id']) ? MyClass::find($filters['class_id']) : null;
        $defFilters = [];
        if (!empty($filters['section_id'])) {
            $defFilters['section_id'] = $filters['section_id'];
        }
        if (!empty($filters['status'])) {
            $defFilters['status'] = $filters['status'];
        }
        $rows = [];
        foreach (FeeDefaulter::rows($year, $class, $defFilters) as $d) {
            $sr = $d['student'];
            $rows[] = [
                'student' => optional($sr->user)->name,
                'adm_no' => $sr->adm_no,
                'class' => optional($sr->my_class)->name,
                'section' => optional($sr->section)->name,
                'total_due' => $d['total_due'],
                'total_paid' => $d['total_paid'],
                'status' => ucfirst($d['status']),
                'items' => $d['items'],
                'oldest_title' => $d['oldest_title'],
            ];
        }
        return $rows;
    }

    protected static function fineRows(array $filters): array
    {
        $fines = LibraryFine::with(['book_request.book', 'book_request.user', 'user'])
            ->when(!empty($filters['status']), function ($q) use ($filters) {
                return $q->where('status', $filters['status']);
            })
            ->orderBy('id')
            ->get();

        return $fines->map(function ($f) {
            $br = $f->book_request;
            return [
                'student' => optional(optional($br)->user)->name,
                'book' => optional(optional($br)->book)->name,
                'amount' => $f->amount,
                'days_overdue' => $f->days_overdue,
                'reason' => $f->reason,
                'status' => $f->status,
                'imposed_by' => optional(User::find($f->imposed_by))->name,
            ];
        })->all();
    }

    protected static function bookRows(array $filters): array
    {
        $books = Book::with('myClass')
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->orderBy('name')
            ->get();

        return $books->map(function ($b) {
            return [
                'name' => $b->name,
                'class' => optional($b->myClass)->name ?: 'All classes',
                'author' => $b->author,
                'book_type' => $b->book_type,
                'location' => $b->location,
                'total_copies' => $b->total_copies,
                'issued_copies' => $b->issued_copies,
                'available' => (int) $b->total_copies - (int) $b->issued_copies,
            ];
        })->all();
    }

    protected static function issueRows(array $filters): array
    {
        $overdue = ($filters['status'] ?? null) === 'overdue';
        $requests = BookRequest::with(['book', 'user'])
            ->when(!$overdue && !empty($filters['status']), function ($q) use ($filters) {
                return $q->where('status', $filters['status']);
            })
            ->when($overdue, function ($q) {
                return $q->where('status', BookRequest::STATUS_ISSUED)
                    ->where('returned', 0)
                    ->whereDate('end_date', '<', date('Y-m-d'));
            })
            ->orderBy('start_date')
            ->get();

        return $requests->map(function ($r) use ($overdue) {
            return [
                'book' => optional($r->book)->name,
                'borrower' => optional($r->user)->name,
                'start_date' => $r->start_date,
                'end_date' => $r->end_date,
                'status' => $overdue ? 'Overdue' : ucfirst($r->status),
                'returned' => $r->returned ? 'Yes' : 'No',
            ];
        })->all();
    }

    protected static function staffRows(array $filters): array
    {
        $users = User::whereIn('user_type', ['teacher', 'accountant', 'librarian', 'admin'])
            ->orderBy('name')
            ->get();

        return $users->map(function ($u) {
            $sr = \App\Models\StaffRecord::where('user_id', $u->id)->first();
            return [
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'user_type' => ucfirst($u->user_type),
                'code' => optional($sr)->code,
                'emp_date' => optional($sr)->emp_date,
            ];
        })->all();
    }

    protected static function admissionRows(array $filters): array
    {
        $admissions = Admission::with('myClass')
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->when(!empty($filters['status']), function ($q) use ($filters) {
                return $q->where('status', $filters['status']);
            })
            ->when(!empty($filters['session']), function ($q) use ($filters) {
                return $q->where('session', $filters['session']);
            })
            ->orderBy('id')
            ->get();

        return $admissions->map(function ($a) {
            return [
                'name' => $a->name,
                'phone' => $a->phone,
                'class' => optional($a->myClass)->name,
                'status' => Admission::STATUS_LABELS[$a->status] ?? ucfirst($a->status),
                'session' => $a->session,
            ];
        })->all();
    }

    protected static function certificateRows(array $filters): array
    {
        $certs = Certificate::when(!empty($filters['status']), function ($q) use ($filters) {
                return $q->where('type', $filters['status']);
            })
            ->orderBy('issued_at')
            ->get();

        return $certs->map(function ($c) {
            return [
                'student_name' => $c->student_name,
                'adm_no' => $c->adm_no,
                'class_name' => $c->class_name,
                'section_name' => $c->section_name,
                'type' => $c->type,
                'serial' => $c->serial,
                'session' => $c->session,
                'issued_at' => $c->issued_at,
            ];
        })->all();
    }

    protected static function classRows(): array
    {
        return MyClass::orderBy('id')->get()->map(function ($c) {
            return [
                'name' => $c->name,
                'sections' => Section::where('my_class_id', $c->id)->count(),
                'students' => StudentRecord::where('my_class_id', $c->id)->where('grad', 0)->count(),
            ];
        })->all();
    }

    protected static function circularRows(array $filters): array
    {
        $circulars = Circular::when(!empty($filters['status']), function ($q) use ($filters) {
                return $q->where('audience', $filters['status']);
            })
            ->orderBy('starts_at')
            ->get();

        return $circulars->map(function ($c) {
            return [
                'title' => $c->title,
                'audience' => $c->audience,
                'priority' => $c->priority,
                'published' => $c->is_published ? 'Yes' : 'No',
                'starts_at' => $c->starts_at,
                'expires_at' => $c->expires_at,
            ];
        })->all();
    }

    protected static function timetableRows(array $filters): array
    {
        $records = TimeTableRecord::with(['my_class', 'section', 'exam'])
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->when(!empty($filters['section_id']), function ($q) use ($filters) {
                return $q->where('section_id', $filters['section_id']);
            })
            ->orderBy('id')
            ->get();

        return $records->map(function ($t) {
            return [
                'name' => $t->name,
                'class' => optional($t->my_class)->name,
                'section' => optional($t->section)->name ?: 'Whole class',
                'exam' => optional($t->exam)->name ?: '—',
                'year' => $t->year,
            ];
        })->all();
    }

    protected static function feeSummaryRows(array $filters): array
    {
        $year = $filters['year'] ?? \App\Helpers\Qs::getCurrentSession();
        $payments = Payment::orderBy('id')->get();
        $rows = [];
        foreach ($payments as $p) {
            $records = PaymentRecord::where('payment_id', $p->id)
                ->when($year, function ($q) use ($year) {
                    return $q->where('year', $year);
                })
                ->get();
            if ($records->isEmpty()) {
                continue;
            }
            if (!empty($filters['class_id']) && (int) $p->my_class_id !== (int) $filters['class_id']) {
                continue;
            }
            $rows[] = [
                'fee_title' => $p->title,
                'class' => optional($p->my_class)->name ?: 'All classes',
                'records' => $records->count(),
                'invoiced' => (int) $p->amount * $records->count(),
                'collected' => (int) $records->sum('amt_paid'),
                'outstanding' => (int) $records->where('paid', 0)->sum('balance'),
            ];
        }
        return $rows;
    }

    protected static function attendanceSummaryRows(array $filters): array
    {
        $query = AttendanceSession::query()
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->when(!empty($filters['subject_id']), function ($q) use ($filters) {
                return $q->where('subject_id', $filters['subject_id']);
            })
            ->when(!empty($filters['date_from']), function ($q) use ($filters) {
                return $q->whereDate('date', '>=', $filters['date_from']);
            })
            ->when(!empty($filters['date_to']), function ($q) use ($filters) {
                return $q->whereDate('date', '<=', $filters['date_to']);
            });

        $groups = [];
        foreach ($query->get(['id', 'my_class_id', 'subject_id']) as $s) {
            $key = $s->my_class_id . '|' . $s->subject_id;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'class' => optional(MyClass::find($s->my_class_id))->name,
                    'subject' => optional(Subject::find($s->subject_id))->name,
                    'sessions' => 0, 'present' => 0, 'absent' => 0, 'late' => 0,
                ];
            }
            $groups[$key]['sessions']++;
            $counts = AttendanceSessionEntry::where('session_id', $s->id)
                ->select('status', DB::raw('COUNT(*) as c'))
                ->groupBy('status')
                ->pluck('c', 'status');
            $groups[$key]['present'] += (int) ($counts[AttendanceSessionEntry::STATUS_PRESENT] ?? 0);
            $groups[$key]['absent'] += (int) ($counts[AttendanceSessionEntry::STATUS_ABSENT] ?? 0);
            $groups[$key]['late'] += (int) ($counts[AttendanceSessionEntry::STATUS_LATE] ?? 0);
        }

        $rows = [];
        foreach ($groups as $g) {
            $marked = $g['present'] + $g['absent'] + $g['late'];
            $g['percentage'] = $marked > 0 ? round(($g['present'] + $g['late']) / $marked * 100, 1) : null;
            $rows[] = $g;
        }
        return $rows;
    }

    protected static function resultsSummaryRows(array $filters): array
    {
        // One pass over the marks with components eager-loaded. The
        // scheme lookup is cached per (exam, subject) — calling
        // MarkScheme::subjectTotal per row would re-query the scheme
        // for every mark, so the total is computed inline with the
        // same rules (configured component sum, else legacy tca+exm).
        $marks = Mark::with('markComponents')
            ->when(!empty($filters['exam_id']), function ($q) use ($filters) {
                return $q->where('exam_id', $filters['exam_id']);
            })
            ->when(!empty($filters['class_id']), function ($q) use ($filters) {
                return $q->where('my_class_id', $filters['class_id']);
            })
            ->get();
        $schemes = [];
        $groups = [];
        foreach ($marks as $m) {
            $gKey = $m->exam_id . '|' . $m->my_class_id;
            if (!isset($groups[$gKey])) {
                $groups[$gKey] = ['exam_id' => $m->exam_id, 'class_id' => $m->my_class_id, 'students' => [], 'sums' => []];
            }
            $groups[$gKey]['students'][$m->student_id] = true;
            $sKey = $m->exam_id . '|' . $m->subject_id;
            if (!isset($schemes[$sKey])) {
                $schemes[$sKey] = [
                    'configured' => MarkScheme::isConfigured($m->exam_id, $m->subject_id),
                    'components' => MarkScheme::components($m->exam_id, $m->subject_id),
                ];
            }
            $scheme = $schemes[$sKey];
            $total = null;
            if ($scheme['configured']) {
                $scores = $m->markComponents->pluck('score')->filter(function ($s) {
                    return $s !== null;
                });
                $total = $scores->isEmpty() ? null : (int) $scores->sum();
            } else {
                if (!($m->t1 === null && $m->t2 === null && $m->exm === null)) {
                    $tca = $m->tca !== null ? (int) $m->tca : (int) $m->t1 + (int) $m->t2;
                    $total = $tca + (int) $m->exm;
                }
            }
            if ($total !== null) {
                $sId = $m->student_id;
                $groups[$gKey]['sums'][$sId] = ($groups[$gKey]['sums'][$sId] ?? 0) + $total;
            }
        }
        $rows = [];
        foreach ($groups as $g) {
            $sums = array_values($g['sums']);
            $rows[] = [
                'exam' => optional(Exam::find($g['exam_id']))->name,
                'class' => optional(MyClass::find($g['class_id']))->name,
                'students' => count($g['students']),
                'average_total' => $sums ? round(array_sum($sums) / count($sums), 1) : null,
            ];
        }
        return $rows;
    }

    protected static function librarySummaryRows(): array
    {
        return Book::select('book_type', DB::raw('COUNT(*) as titles'), DB::raw('SUM(total_copies) as copies'), DB::raw('SUM(issued_copies) as issued'))
            ->groupBy('book_type')
            ->orderBy('book_type')
            ->get()
            ->map(function ($r) {
                return [
                    'book_type' => $r->book_type ?: 'Uncategorised',
                    'titles' => (int) $r->titles,
                    'copies' => (int) $r->copies,
                    'issued' => (int) $r->issued,
                    'available' => (int) $r->copies - (int) $r->issued,
                ];
            })->all();
    }

    /** Platform numbers only — never names, never school records. */
    protected static function platformRows(): array
    {
        $activeStudents = StudentRecord::where('grad', 0)->count();
        $rows = [
            ['Total users', User::count()],
            ['School admins', User::where('user_type', 'admin')->count()],
            ['Teachers', User::where('user_type', 'teacher')->count()],
            ['Students (active)', $activeStudents],
            ['Parents', User::where('user_type', 'parent')->count()],
            ['Accountants', User::where('user_type', 'accountant')->count()],
            ['Librarians', User::where('user_type', 'librarian')->count()],
            ['Classes', MyClass::count()],
            ['Sections', Section::count()],
            ['Subjects', Subject::count()],
            ['Exams', Exam::count()],
            ['Books in catalogue', Book::count()],
            ['Admission enquiries', Admission::count()],
        ];
        return array_map(function ($r) {
            return ['metric' => $r[0], 'value' => $r[1]];
        }, $rows);
    }
}
