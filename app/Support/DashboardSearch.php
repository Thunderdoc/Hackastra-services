<?php

namespace App\Support;

use App\Helpers\Money;
use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Payment;
use App\User;
use Illuminate\Support\Facades\Route;

/**
 * Round 5 Slice 4 (P3) — the ONE home for the dashboard search
 * queries and the command palette's Pages/Actions catalogue.
 *
 * Before this class the queries lived inline in
 * DashboardSearchController::__invoke (the HTML results page).
 * The palette's JSON endpoint (GET /dashboard/search.json) needs
 * exactly the same rows with exactly the same scoping, so both
 * surfaces call results() here — the page and the palette can
 * never drift apart on who may see what.
 *
 * Scoping (owner role rule — a role sees only its own domain):
 *   - Data search (students / staff / classes / payments) stays
 *     gated to TeamSAT + principal, exactly like the page's 403
 *     gate. The palette does NOT widen it: roles the page refuses
 *     (student, parent, accountant, librarian, nurse) receive the
 *     catalogue only, with every data group empty.
 *   - Super Admin branch is platform-only (owner ruling
 *     2026-10-22): staff-type accounts only, never students,
 *     classes or payments.
 *   - HOLE CLOSED (audit W3 §3, holes 1–2): a teacher's CLASS
 *     results are now restricted to TeacherScope::classIds() —
 *     previously any class matching by name leaked with a
 *     students.list link — and the PAYMENTS group is excluded
 *     for teachers entirely (fee data is the admin/principal
 *     domain). Both fixes apply to the HTML page too, because
 *     the page reads from this same method.
 *   - Teacher STUDENT results were already TeacherScope-scoped
 *     (round 18) and stay so.
 *
 * The catalogue is server-built per role from the same gates the
 * sidebar menus use (partials/menu.blade.php + the per-role menu
 * fragments) — never scraped from the DOM. Actions are deep
 * links to existing gated pages only; nothing here performs a
 * write by GET, and each target page's own gates remain the
 * enforcement.
 */
class DashboardSearch
{
    /** Per-group cap for data results (the page's long-standing take(10)). */
    const PER_GROUP = 10;

    /** May this user run the data search at all? (The page's gate.) */
    public static function canSearchData(User $user): bool
    {
        return in_array($user->user_type, Qs::getTeamSAT(), true)
            || $user->user_type === 'principal';
    }

    /**
     * The shared data query. Returns Eloquent collections keyed
     * students / staff / classes / payments — the exact shape the
     * HTML results page renders, and the source palettePayload()
     * maps to JSON rows.
     */
    public static function results(User $user, string $term): array
    {
        $data = [
            'students' => collect(),
            'staff' => collect(),
            'classes' => collect(),
            'payments' => collect(),
        ];

        if (!self::canSearchData($user) || strlen($term) < 2) {
            return $data;
        }

        $like = '%' . $term . '%';

        if ($user->user_type === 'super_admin') {
            // Platform-only model (owner ruling 2026-10-22,
            // Workstream DD): the Super Admin's search covers
            // platform accounts (staff types) only — never student
            // or parent records, classes or payments.
            $data['staff'] = User::whereIn('user_type', ['teacher', 'admin', 'super_admin', 'accountant', 'librarian', 'nurse'])
                ->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
                })
                ->take(self::PER_GROUP)
                ->get();

            return $data;
        }

        $data['students'] = User::where('user_type', 'student')
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            })
            // Roles audit round 18: a teacher sees only their
            // assigned students (TeacherScope rule) — the search
            // must not hand them every class's roster. Admin and
            // principal search school-wide by design.
            ->when($user->user_type === 'teacher', function ($q) use ($user) {
                $q->whereHas('student_record', function ($sq) use ($user) {
                    $sq->whereIn('my_class_id', TeacherScope::classIds($user));
                });
            })
            ->with('student_record.my_class', 'student_record.section')
            ->take(self::PER_GROUP)
            ->get();

        // Roles audit round 18: the platform boundary holds both
        // ways — school roles never see the platform account in
        // search. 'super_admin' stays in the SA's own branch above,
        // which is platform-only by design.
        $data['staff'] = User::whereIn('user_type', ['teacher', 'admin', 'accountant', 'librarian', 'principal', 'nurse'])
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            })
            ->take(self::PER_GROUP)
            ->get();

        $data['classes'] = MyClass::where('name', 'like', $like)
            // P3 hole 1: classes are TeacherScope-scoped like the
            // students above — a teacher no longer receives (or can
            // link into) classes they do not teach.
            ->when($user->user_type === 'teacher', function ($q) use ($user) {
                $q->whereIn('id', TeacherScope::classIds($user));
            })
            ->take(self::PER_GROUP)
            ->get();

        // P3 hole 2: payments are fee-domain data — admin and
        // principal only. Teachers get an empty group, on the
        // palette and on the HTML page alike.
        if ($user->user_type !== 'teacher') {
            $data['payments'] = Payment::where('title', 'like', $like)
                ->orWhere('ref_no', 'like', $like)
                ->take(self::PER_GROUP)
                ->get();
        }

        return $data;
    }

    /**
     * The palette JSON payload:
     * {pages:[], actions:[], students:[], staff:[], classes:[], payments:[]}
     * Pages/Actions are the role's full catalogue (filtering them
     * is the client's subsequence-fuzzy job); the data groups are
     * the shared query's rows, mapped to {label, meta, url} —
     * url is null where the HTML page offers no link (staff,
     * payments), so the palette never invents a destination.
     */
    public static function palettePayload(User $user, string $term): array
    {
        $catalogue = self::catalogue($user);

        $payload = [
            'pages' => $catalogue['pages'],
            'actions' => $catalogue['actions'],
            'students' => [],
            'staff' => [],
            'classes' => [],
            'payments' => [],
        ];

        if (!self::canSearchData($user)) {
            return $payload;
        }

        $results = self::results($user, $term);

        $payload['students'] = $results['students']->map(function ($student) {
            $record = $student->student_record;
            $meta = collect([
                optional(optional($record)->my_class)->name,
                optional(optional($record)->section)->name,
            ])->filter()->implode(' · ');

            return [
                'label' => $student->name,
                'meta' => $meta !== '' ? $meta : $student->email,
                'url' => $record ? route('students.show', Qs::hash($record->id)) : null,
            ];
        })->values()->all();

        $payload['staff'] = $results['staff']->map(function ($member) {
            return [
                'label' => $member->name,
                'meta' => ucwords(str_replace('_', ' ', $member->user_type)) . ' · ' . $member->email,
                'url' => null,
            ];
        })->values()->all();

        $payload['classes'] = $results['classes']->map(function ($class) {
            return [
                'label' => $class->name,
                'meta' => 'Class',
                'url' => route('students.list', $class->id),
            ];
        })->values()->all();

        $payload['payments'] = $results['payments']->map(function ($payment) {
            return [
                'label' => $payment->title,
                'meta' => collect([$payment->ref_no, Money::inr($payment->amount)])->filter()->implode(' · '),
                'url' => null,
            ];
        })->values()->all();

        return $payload;
    }

    /**
     * Pages + Actions catalogue for one user, mirroring the
     * sidebar gates (partials/menu.blade.php and the per-role
     * fragments under pages/<role>/menu.blade.php). Entries are
     * ['label' => …, 'url' => …, 'icon' => …]; an entry whose
     * route is not registered is dropped rather than fatal.
     */
    public static function catalogue(User $user): array
    {
        $pages = [];
        $actions = [];

        $page = function ($label, $routeName, $icon, $params = []) use (&$pages) {
            if (Route::has($routeName)) {
                $pages[] = ['label' => $label, 'url' => route($routeName, $params), 'icon' => $icon];
            }
        };
        $action = function ($label, $routeName, $icon, $params = []) use (&$actions) {
            if (Route::has($routeName)) {
                $actions[] = ['label' => $label, 'url' => route($routeName, $params), 'icon' => $icon];
            }
        };

        $type = $user->user_type;

        if ($type === 'super_admin') {
            // Platform-only: platform navigation, zero school
            // pages and zero write actions (SA is view-only in
            // schools; its own mail pages are navigation).
            $page('Platform Overview', 'dashboard', 'icon-office');
            $page('Users', 'users.index', 'icon-users4');
            $page('Audit Log', 'audit_logs.index', 'icon-history');
            $page('School Feedback', 'feedback.sa_index', 'icon-bubbles4');
            $page('Send Mail', 'platform_mail.create', 'icon-envelop5');
            $page('Sent Mail', 'platform_mail.index', 'icon-mail-read');
            $page('My Account', 'my_account', 'icon-user');

            return ['pages' => $pages, 'actions' => $actions];
        }

        $page('Dashboard', 'dashboard', 'icon-home4');

        switch ($type) {
            case 'admin':
                $page('Student Information', 'students.list', 'icon-users');
                $page('Users', 'users.index', 'icon-users4');
                $page('Classes', 'classes.index', 'icon-windows2');
                $page('Sections', 'sections.index', 'icon-fence');
                $page('Subjects', 'subjects.index', 'icon-pin');
                $page('Manage Payments', 'payments.index', 'icon-coins');
                $page('Student Payments', 'payments.manage', 'icon-coins');
                $page('Collection Report', 'payments.collection', 'icon-stats-bars');
                $page('Timetables', 'tt.index', 'icon-calendar3');
                $page('Exams', 'exams.index', 'icon-books');
                $page('Tabulation Sheet', 'marks.tabulation', 'icon-file-text2');
                $page('School Events', 'events.index', 'icon-calendar3');
                $page('Leave Requests', 'leave.index', 'icon-calendar2');
                $page('Reports & PDF Builder', 'reports.builder', 'icon-file-text2');
                $page('Analytics Dashboard', 'analytics.index', 'icon-stats-bars');
                $page('Bulk Management', 'bulk.index', 'icon-upload');
                $page('Settings', 'settings', 'icon-gear');
                $page('Audit Log', 'audit_logs.index', 'icon-history');

                $action('Admit Student', 'students.create', 'icon-user-plus');
                $action('Bulk Import Students', 'students.import', 'icon-upload');
                $action('Record Payment', 'payments.create', 'icon-coins');
                $action('Take Attendance', 'attendance.periods.index', 'icon-watch');
                $action('Enter Marks', 'marks.index', 'icon-file-text2');
                $action('Generate Timetable', 'tt.generate.form', 'icon-calendar3');
                $action('Publish Circular', 'circulars.create', 'icon-megaphone');
                $action('Create Exam', 'exams.create', 'icon-books');
                break;

            case 'principal':
                // The principal fragment's own destinations.
                $page('Academics & Curriculum', 'classes.index', 'icon-windows2');
                $page('Timetable & Staffing', 'tt.index', 'icon-calendar3');
                $page('Students', 'students.list', 'icon-users4');
                $page('Teachers & Staff', 'users.index', 'icon-user-tie');
                $page('Attendance', 'attendance.periods.index', 'icon-calendar52');
                $page('Exams & Results', 'exams.index', 'icon-book');
                $page('Approvals & Requests', 'leave.index', 'icon-clipboard');
                $page('Fees & Finance', 'payments.manage', 'icon-wallet');
                $page('Communication & Parent Relations', 'parent_messages.index', 'icon-bubbles4');
                $page('WhatsApp Log', 'whatsapp_log.index', 'icon-bubbles4');
                $page('Reports & Analytics', 'reports.builder', 'icon-stats-bars');
                $page('Audit Logs', 'audit_logs.index', 'icon-eye');
                $page('Exam Date Sheet', 'exam_dates.index', 'icon-calendar3');

                // Actions = its designated approval pages only —
                // no write actions it does not already have.
                $action('Date Sheet Approvals', 'exam_dates.approvals', 'icon-calendar3');
                $action('Exam Seating Approvals', 'exam_seating.approvals', 'icon-calendar3');
                break;

            case 'teacher':
                $page('Attendance Report', 'attendance.report', 'icon-stats-bars2');
                $page('Low Attendance (Below 75%)', 'attendance.defaulters', 'icon-alarm');
                $page('My Timetable', 'tt.mine', 'icon-table2');
                $page('My Slot Preferences', 'tt.slot_preferences', 'icon-calendar3');
                $page('Student Information', 'students.list', 'icon-users');
                $page('Exam Date Sheet', 'exam_dates.index', 'icon-calendar3');
                $page('Question Papers', 'question_papers.index', 'icon-books');
                $page('Assignments', 'assignments.index', 'icon-file-text2');
                $page('Study Materials', 'materials.index', 'icon-book');
                $page('Quizzes', 'quizzes.index', 'icon-file-text2');
                $page('Class Overview', 'class_overview.index', 'icon-watch');
                $page('Reports & PDF Builder', 'reports.builder', 'icon-file-text2');
                $page('My Extra Classes', 'substitutions.mine', 'icon-people');
                $page('My Payslips', 'my_payslips.index', 'icon-coin-dollar');

                $action('Take Period Attendance', 'attendance.periods.index', 'icon-watch');
                $action('Enter Marks', 'marks.index', 'icon-file-text2');
                $action('Create Assignment', 'assignments.create', 'icon-file-text2');
                $action('Create Question Paper', 'question_papers.create', 'icon-books');
                $action('Create Quiz', 'quizzes.create', 'icon-file-text2');
                $action('Apply for Leave', 'leave.my', 'icon-calendar2');
                break;

            case 'accountant':
                // The generic menu's Administrative → Payments
                // submenu (teamAccount / teamAccountWrite gates).
                $page('Manage Payments', 'payments.index', 'icon-coins');
                $page('Student Payments', 'payments.manage', 'icon-coins');
                $page('Collection Report', 'payments.collection', 'icon-stats-bars');
                $page('Expenses', 'expenses.index', 'icon-coins');
                $page('Fee Reminders', 'payments.reminders', 'icon-calendar2');
                $page('My Payslips', 'my_payslips.index', 'icon-coin-dollar');

                $action('Record Payment', 'payments.create', 'icon-coins');
                $action('Fee Defaulters', 'payments.defaulters', 'icon-alarm');
                break;

            case 'librarian':
                $page('Books', 'books.index', 'icon-books');
                $page('Book Reservations', 'library_reservations.index', 'icon-books');
                $page('Library Fines', 'library_fines.index', 'icon-coins');
                $page('My Payslips', 'my_payslips.index', 'icon-coin-dollar');

                $action('Add Book', 'books.create', 'icon-books');
                $action('Issue or Return Book', 'book_requests.index', 'icon-book');
                break;

            case 'nurse':
                $page('Health Records', 'health_records.index', 'icon-heart6');

                $action('Add Health Record', 'health_records.create', 'icon-heart6');
                break;

            case 'student':
                // The student fragment's own pages; navigation
                // only — no create actions (audit §3 design).
                $page('Marksheet', 'marks.year_selector', 'icon-book', Qs::hash($user->id));
                $page('Admit Card', 'admit_cards.my', 'icon-profile');
                $page('Exam Date Sheet', 'exam_dates.index', 'icon-calendar3');
                $page('My Attendance', 'attendance.my', 'icon-calendar3');
                $page('My Timetable', 'tt.mine', 'icon-table2');
                $page('My ID Card', 'id_card.mine', 'icon-vcard');
                $page('My Assignments', 'assignments.index', 'icon-file-text2');
                $page('Study Materials', 'materials.index', 'icon-book');
                $page('My Fees', 'student.fees', 'icon-coins');
                $page('My Library', 'student.library', 'icon-book');
                $page('Book Search & Reserve', 'library_reservations.index', 'icon-books');
                $page('My Documents', 'documents.index', 'icon-book');
                $page('My Leave', 'leave.my', 'icon-calendar2');
                break;

            case 'parent':
                // The parent fragment's own pages; navigation only.
                $page('My Children', 'my_children', 'icon-users4');
                $page('Admit Cards', 'admit_cards.my', 'icon-profile');
                $page('Exam Date Sheet', 'exam_dates.index', 'icon-calendar3');
                $page('Attendance', 'attendance.my', 'icon-calendar3');
                $page('Fees', 'my_fees', 'icon-coins');
                $page('My Timetable', 'tt.mine', 'icon-table2');
                $page('ID Cards', 'id_card.mine', 'icon-vcard');
                $page('Assignments', 'assignments.index', 'icon-file-text2');
                $page('Messages', 'parent_messages.index', 'icon-file-text2');
                $page('PTM Meetings', 'ptm.index', 'icon-calendar3');
                $page('Health Records', 'health_records.index', 'icon-users');
                $page('Documents', 'documents.index', 'icon-book');
                break;

            default:
                // Any other signed-in role (e.g. hostel warden):
                // the universal pages only, nothing role-specific
                // is promised.
                break;
        }

        // Universal school pages (every non-SA role's sidebar
        // carries Circulars; My Leave renders for staff roles in
        // the generic menu — students reach it from their own
        // fragment, handled above; My Account closes every menu).
        $page('Circulars', 'circulars.index', 'icon-megaphone');
        if (in_array($type, ['admin', 'principal', 'teacher', 'accountant', 'librarian'], true)) {
            $page('My Leave', 'leave.my', 'icon-calendar2');
        }
        $page('My Account', 'my_account', 'icon-user');

        return ['pages' => $pages, 'actions' => $actions];
    }
}
