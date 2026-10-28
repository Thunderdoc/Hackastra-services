<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\HealthRecord;
use App\Models\StudentRecord;
use App\Support\Audit;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health Records — per-student medical info + clinic visit log.
 *
 * Fields (all typed by the writer or left empty; nothing is
 * pre-filled or invented): student, visit date, complaint,
 * treatment, referred flag + referred-to text, recorded-by.
 * The index list shows metadata only (date, student, referred
 * badge, recorded-by) — complaint/treatment text appears only on
 * the scoped show page, never in a list.
 *
 * Routes carry NO team middleware, so this controller gates
 * everything (controller-guarded, like the other Wave 3 routes):
 * - WRITE: admin + teacher only.
 * - READ: admin (all), teacher (own classes only), parent (only
 *   their own children, via StudentRecord.my_parent_id), student
 *   (only self). Everyone else (accountant, librarian,
 *   super_admin, ...) gets 403 on every action.
 *
 * TEACHER SCOPE — "teacher only for students in classes they
 * teach" is resolved as the union of:
 *   1. TeacherScope (canonical): subjects.teacher_id for the
 *      student's class, OR sections.teacher_id (class-teacher of
 *      a section of that class) — TeacherScope::canTeachStudent.
 *   2. Effective-teacher override: section_subject_teachers has a
 *      row naming this teacher for the student's section (any
 *      subject) — the SectionTeacher effective-teacher rule, so a
 *      teacher who teaches a section only via an override still
 *      counts for that section's students, even when they are not
 *      the class-level subject teacher or the section's
 *      class-teacher.
 * A teacher outside both sources gets 403 on store/show and sees
 * no such record in index/create (the student picker is scoped
 * the same way).
 *
 * Writes are logged with Audit::log; the audit description carries
 * only the student id and visit date, never complaint/treatment
 * text, so medical detail does not leak into the audit log.
 */
class HealthRecordController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $query = HealthRecord::with(['student', 'recorder'])
            ->orderBy('visit_date', 'desc')
            ->orderBy('id', 'desc');

        if (Qs::userIsAdmin()) {
            // All records.
        } elseif (Qs::userIsTeacher()) {
            $query->whereIn('student_id', $this->teacherStudentUserIds($user));
        } elseif (Qs::userIsParent()) {
            $query->whereIn('student_id', $this->parentChildUserIds($user));
        } elseif (Qs::userIsStudent()) {
            $query->where('student_id', $user->id);
        } else {
            abort(403);
        }

        return view('pages.support_team.health_records.index', [
            'records' => $query->get(),
            'can_write' => Qs::userIsAdmin() || Qs::userIsTeacher(),
        ]);
    }

    public function create()
    {
        $user = Auth::user();
        if (!Qs::userIsAdmin() && !Qs::userIsTeacher()) {
            abort(403);
        }

        return view('pages.support_team.health_records.create', [
            'students' => $this->writableStudents($user),
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!Qs::userIsAdmin() && !Qs::userIsTeacher()) {
            abort(403);
        }

        $data = $request->validate([
            'student_id' => 'required|exists:student_records,user_id',
            'visit_date' => 'required|date',
            'complaint' => 'required|string',
            'treatment' => 'nullable|string',
            'is_referred' => 'nullable|boolean',
            'referred_to' => 'nullable|string|max:255',
        ]);

        $studentRecord = StudentRecord::where('user_id', (int) $data['student_id'])->firstOrFail();

        if (Qs::userIsTeacher() && !$this->teacherCanAccessStudent($user, $studentRecord)) {
            abort(403);
        }

        $record = HealthRecord::create([
            'student_id' => (int) $data['student_id'],
            'visit_date' => $data['visit_date'],
            'complaint' => trim($data['complaint']),
            'treatment' => isset($data['treatment']) && trim($data['treatment']) !== '' ? trim($data['treatment']) : null,
            'is_referred' => (bool) ($data['is_referred'] ?? false),
            'referred_to' => isset($data['referred_to']) && trim($data['referred_to']) !== '' ? trim($data['referred_to']) : null,
            'recorded_by' => $user->id,
        ]);

        Audit::log('health_record.created', $record, 'Health record for student ' . $record->student_id . ' on ' . $record->visit_date->format('Y-m-d'));

        return redirect()->route('health_records.show', $record->id)
            ->with('flash_success', 'Health record saved.');
    }

    public function show(HealthRecord $health)
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        if (Qs::userIsAdmin()) {
            // All records.
        } elseif (Qs::userIsTeacher()) {
            $studentRecord = StudentRecord::where('user_id', (int) $health->student_id)->first();
            if (!$studentRecord || !$this->teacherCanAccessStudent($user, $studentRecord)) {
                abort(403);
            }
        } elseif (Qs::userIsParent()) {
            $studentRecord = StudentRecord::where('user_id', (int) $health->student_id)->first();
            if (!$studentRecord || (int) $studentRecord->my_parent_id !== (int) $user->id) {
                abort(403);
            }
        } elseif (Qs::userIsStudent()) {
            if ((int) $health->student_id !== (int) $user->id) {
                abort(403);
            }
        } else {
            abort(403);
        }

        $health->load(['student', 'recorder']);
        $studentRecord = StudentRecord::with(['my_class', 'section'])->where('user_id', (int) $health->student_id)->first();

        return view('pages.support_team.health_records.show', [
            'record' => $health,
            'student_record' => $studentRecord,
        ]);
    }

    /** Student user ids a teacher may write/read for (scope §1 ∪ §2). */
    protected function teacherStudentUserIds(User $user): array
    {
        $ids = TeacherScope::studentUserIds($user);

        if ($user->user_type === 'teacher' && Schema::hasTable('section_subject_teachers')) {
            $sectionIds = DB::table('section_subject_teachers')
                ->where('teacher_id', $user->id)
                ->pluck('section_id')
                ->all();
            if ($sectionIds) {
                $overrideIds = StudentRecord::whereIn('section_id', $sectionIds)->pluck('user_id')->all();
                $ids = array_values(array_unique(array_merge($ids, array_map('intval', $overrideIds))));
            }
        }

        return array_map('intval', $ids);
    }

    protected function teacherCanAccessStudent(User $user, StudentRecord $studentRecord): bool
    {
        if (TeacherScope::canTeachStudent($user, $studentRecord->user_id)) {
            return true;
        }

        // Effective-teacher override for the student's section.
        if ($studentRecord->section_id && Schema::hasTable('section_subject_teachers')) {
            return DB::table('section_subject_teachers')
                ->where('section_id', (int) $studentRecord->section_id)
                ->where('teacher_id', $user->id)
                ->exists();
        }

        return false;
    }

    protected function parentChildUserIds(User $user): array
    {
        return Qs::findMyChildren($user->id)->pluck('user_id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    /** Non-graduated students the writer may record for, in admission order. */
    protected function writableStudents(User $user)
    {
        $query = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)
            ->orderBy('id');

        if (Qs::userIsTeacher()) {
            $query->whereIn('user_id', $this->teacherStudentUserIds($user));
        }

        return $query->get();
    }
}
