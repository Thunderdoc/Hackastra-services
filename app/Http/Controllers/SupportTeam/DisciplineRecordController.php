<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\DisciplineRecord;
use App\Models\StudentRecord;
use App\Support\Audit;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Discipline Records (2026-10-28): a behaviour / incident log, one
 * row per incident for one student (student, date, fixed type,
 * description, action taken, recorded-by).
 *
 * Guard model — these routes carry NO team middleware (same pattern
 * as LessonPlanController), so this controller gates everything:
 *
 *   WRITE (create/store): admin + teacher only.
 *   READ  (index/show):   admin sees all; teacher sees students in
 *     classes they teach; parent sees only their own children
 *     (StudentRecord.my_parent_id); student sees only self.
 *   Everyone else (super_admin, accountant, librarian, …) gets 403.
 *   "Admin" here is user_type admin, matching Qs::userIsAdmin().
 *
 * Teacher scope — "classes they teach" is resolved in two layers:
 *   1. TeacherScope::canTeachStudent — the class-level sources:
 *      subjects.teacher_id (teaches a subject in the class) and
 *      sections.teacher_id (class-teacher of a section of the
 *      class, which assigns the whole class).
 *   2. Section override — section_subject_teachers names a teacher
 *      for one subject inside one section; that teacher teaches
 *      that section's students even when they hold no class-level
 *      assignment, so they may record (and read) incidents for
 *      students in that section. This is the "effective teacher"
 *      check: the section's effective teacher, not just the
 *      subject's class teacher.
 * Read and write use the same helper (mayAccessStudent), so a
 * teacher can never read a record they could not have written,
 * and vice versa.
 */
class DisciplineRecordController extends Controller
{
    /**
     * May this user see/write a record about this student?
     * Admin: yes, always. Teacher: own-scope students only (see
     * class docblock). Parent: own children only. Student: self
     * only. Anyone else: no.
     */
    protected function mayAccessStudent($user, $studentUserId): bool
    {
        if (!$user || !$studentUserId) {
            return false;
        }
        if (Qs::userIsAdmin()) {
            return true;
        }
        // Round-2 roles fix: the principal's index lists every incident
        // school-wide with a linked description, so show must open them
        // too — otherwise every row link on the principal's board 403s.
        if (Qs::userIsPrincipal()) {
            return true;
        }
        if (Qs::userIsTeacher()) {
            return $this->teacherMayAccessStudent($user, (int) $studentUserId);
        }
        if (Qs::userIsParent()) {
            return (int) StudentRecord::where('user_id', (int) $studentUserId)
                ->value('my_parent_id') === (int) $user->id;
        }
        if (Qs::userIsStudent()) {
            return (int) $studentUserId === (int) $user->id;
        }

        return false;
    }

    /**
     * Teacher scope for one student: class-level assignment
     * (TeacherScope) OR effective teacher of the student's section
     * via a section_subject_teachers override row.
     */
    protected function teacherMayAccessStudent($user, int $studentUserId): bool
    {
        if (TeacherScope::canTeachStudent($user, $studentUserId)) {
            return true;
        }

        $sectionId = StudentRecord::where('user_id', $studentUserId)->value('section_id');
        if (!$sectionId) {
            return false;
        }

        return DB::table('section_subject_teachers')
            ->where('section_id', (int) $sectionId)
            ->where('teacher_id', (int) $user->id)
            ->exists();
    }

    /** Student user ids visible to the caller on the list page. */
    protected function visibleStudentIds($user): array
    {
        if (Qs::userIsAdmin() || Qs::userIsPrincipal()) {
            return StudentRecord::pluck('user_id')->map(function ($id) {
                return (int) $id;
            })->all();
        }
        if (Qs::userIsTeacher()) {
            $ids = TeacherScope::studentUserIds($user);
            // Plus students in sections this teacher serves through
            // a section_subject_teachers override (see class docblock).
            $overrideSections = DB::table('section_subject_teachers')
                ->where('teacher_id', (int) $user->id)
                ->pluck('section_id')
                ->map(function ($id) { return (int) $id; })
                ->all();
            if ($overrideSections) {
                $extra = StudentRecord::whereIn('section_id', $overrideSections)
                    ->pluck('user_id')
                    ->map(function ($id) { return (int) $id; })
                    ->all();
                $ids = array_values(array_unique(array_merge($ids, $extra)));
            }

            return $ids;
        }
        if (Qs::userIsParent()) {
            return StudentRecord::where('my_parent_id', (int) $user->id)
                ->pluck('user_id')
                ->map(function ($id) { return (int) $id; })
                ->all();
        }
        if (Qs::userIsStudent()) {
            return [(int) $user->id];
        }

        return [];
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        // Limited principal role (owner order 2026-10-28): the
        // principal reads every incident school-wide (see
        // visibleStudentIds); writes stay admin/teacher.
        if (!Qs::userIsAdmin() && !Qs::userIsTeacher() && !Qs::userIsParent() && !Qs::userIsStudent() && !Qs::userIsPrincipal()) {
            abort(403);
        }

        $type = $request->query('type');
        $records = DisciplineRecord::with(['student', 'recorder'])
            ->whereIn('student_id', $this->visibleStudentIds($user))
            ->when(in_array($type, array_keys(DisciplineRecord::TYPES), true), function ($q) use ($type) {
                $q->where('type', $type);
            })
            ->orderByDesc('incident_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('pages.support_team.discipline_records.index', [
            'records' => $records,
            'type' => $type,
            'types' => DisciplineRecord::typeLabels(),
            'canWrite' => Qs::userIsAdmin() || Qs::userIsTeacher(),
        ]);
    }

    public function create()
    {
        $user = Auth::user();
        if (!Qs::userIsAdmin() && !Qs::userIsTeacher()) {
            abort(403);
        }

        // The student picker only ever offers students the caller
        // may actually record about — a teacher cannot even pick a
        // foreign-class student, and store() re-checks server-side.
        $students = User::where('user_type', 'student')
            ->whereIn('id', $this->visibleStudentIds($user))
            ->orderBy('name')
            ->get();

        return view('pages.support_team.discipline_records.create', [
            'students' => $students,
            'types' => DisciplineRecord::typeLabels(),
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!Qs::userIsAdmin() && !Qs::userIsTeacher()) {
            abort(403);
        }

        $data = $request->validate([
            'student_id' => 'required|exists:users,id',
            'incident_date' => 'required|date',
            'type' => 'required|in:' . implode(',', array_keys(DisciplineRecord::TYPES)),
            'description' => 'required|string|max:2000',
            'action_taken' => 'nullable|string|max:2000',
        ]);

        // The picked user must actually be a student, and (for a
        // teacher) one in their own scope — never trust the picker.
        $student = User::where('id', $data['student_id'])->where('user_type', 'student')->first();
        if (!$student || !$this->mayAccessStudent($user, (int) $student->id)) {
            abort(403);
        }

        $record = DisciplineRecord::create([
            'student_id' => (int) $student->id,
            'recorded_by' => (int) $user->id,
            'incident_date' => $data['incident_date'],
            'type' => $data['type'],
            'description' => trim($data['description']),
            'action_taken' => $data['action_taken'] ? trim($data['action_taken']) : null,
        ]);

        Audit::log('discipline_record.created', $record, 'Discipline record (' . $record->type . ') for student ' . $student->name . ' on ' . $record->incident_date->format('Y-m-d'));

        return redirect()->route('discipline_records.show', $record->id)
            ->with('flash_success', 'Discipline record saved.');
    }

    public function show(DisciplineRecord $discipline)
    {
        $user = Auth::user();
        if (!$this->mayAccessStudent($user, (int) $discipline->student_id)) {
            abort(403);
        }

        $discipline->load(['student', 'recorder']);

        return view('pages.support_team.discipline_records.show', [
            'record' => $discipline,
        ]);
    }
}
