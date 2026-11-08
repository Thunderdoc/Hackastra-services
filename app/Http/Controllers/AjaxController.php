<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\Repositories\LocationRepo;
use App\Repositories\MyClassRepo;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AjaxController extends Controller
{
    protected $loc, $my_class;

    public function __construct(LocationRepo $loc, MyClassRepo $my_class)
    {
        $this->loc = $loc;
        $this->my_class = $my_class;
    }

    public function get_lga($state_id)
    {
//        $state_id = Qs::decodeHash($state_id);
//        return ['id' => Qs::hash($q->id), 'name' => $q->name];

        $lgas = $this->loc->getLGAs($state_id);
        return $data = $lgas->map(function($q){
            return ['id' => $q->id, 'name' => $q->name];
        })->all();
    }

    public function get_class_sections(Request $req, $class_id)
    {
        // Owner grant 2026-10-25: a teacher's section cascade only
        // ever lists their assigned classes' sections — every data
        // page re-validates, but the list itself must not leak.
        if (Qs::userIsTeacher() && !\App\Support\TeacherScope::canTeachClass(Auth::user(), $class_id)) {
            return [];
        }
        $sections = $this->my_class->getClassSections($class_id);
        // Opt-in effective mode (scoped-options audit, 2026-11-01):
        // only the LMS create forms ask for it — a teacher's
        // sections narrow to the ones where she is the EFFECTIVE
        // teacher of some subject, matching what those stores
        // accept. Every other caller keeps the class-level list.
        if ($req->query('effective') && Qs::userIsTeacher()) {
            $unit = SectionTeacher::unitSectionIdsByClass(Auth::user());
            $sections = $sections->whereIn('id', $unit[(int) $class_id] ?? [])->values();
        }
        // Opt-in tabulation mode (scoped-options audit, 2026-11-01): mirror
        // the tabulation sheet's own gate (MarkController::teacherSectionIds)
        // - a subject teacher of the class gets every section; a pure class
        // teacher only the sections she leads. Only the tabulation selector
        // asks for it; every other caller keeps today's list.
        if ($req->query('scope') === 'tabulation' && Qs::userIsTeacher()) {
            $u = Auth::user();
            $teachesSubject = \App\Models\Subject::where('my_class_id', $class_id)->where('teacher_id', $u->id)->exists()
                || DB::table('section_subject_teachers')
                    ->join('sections', 'sections.id', '=', 'section_subject_teachers.section_id')
                    ->where('section_subject_teachers.teacher_id', $u->id)
                    ->where('sections.my_class_id', (int) $class_id)->exists();
            if (!$teachesSubject) {
                $sections = $sections->where('teacher_id', $u->id)->values();
            }
        }
        return $sections = $sections->map(function($q){
            return ['id' => $q->id, 'name' => $q->name];
        })->all();
    }

    /**
     * Student-picker cascade (owner, 2026-11-01): the students of
     * ONE section — the last step of Class > Section > Student on
     * every picker form, replacing flat dropdowns of all 3,041
     * students. Active (grad=0) students only, in name order.
     * Teacher callers are scoped to the students they may actually
     * act on (the writable-students rule: TeacherScope plus the
     * section-subject override union, exactly as the Health /
     * Discipline writers scope their pickers); school roles are
     * unscoped within the section; students and parents get [].
     *
     * Type-to-search mode (owner, 2026-11-08 — the nurse must be
     * able to TYPE a few letters, not scroll a full roster): when
     * the caller passes ?q=, the SAME scoping applies first, then
     * the section's students are filtered server-side by name or
     * admission number (minimum 2 characters, at most 20 results).
     * Callers without ?q= keep the full section list exactly as
     * before — this is one feed, not a parallel endpoint.
     */
    public function get_section_students(Request $request, $section_id)
    {
        $user = Auth::user();
        if (!$user || in_array($user->user_type, ['student', 'parent'], true)) {
            return [];
        }

        $records = StudentRecord::with('user')
            ->where('section_id', (int) $section_id)
            ->where('grad', 0)
            ->get();

        if (Qs::userIsTeacher()) {
            $allowed = $this->cascadeTeacherStudentUserIds($user);
            $records = $records->filter(function ($sr) use ($allowed) {
                return in_array((int) $sr->user_id, $allowed, true);
            })->values();
        }

        $records = $records->sortBy(function ($sr) { return optional($sr->user)->name; })->values();

        if ($request->has('q')) {
            $q = trim((string) $request->query('q', ''));
            if (mb_strlen($q) < 2) {
                return [];
            }
            $records = $records->filter(function ($sr) use ($q) {
                $name = (string) optional($sr->user)->name;
                return mb_stripos($name, $q) !== false
                    || mb_stripos((string) $sr->adm_no, $q) !== false;
            })->take(20)->values();
        }

        return $records
            ->map(function ($sr) {
                return [
                    'id' => $sr->id,
                    'user_id' => (int) $sr->user_id,
                    'name' => optional($sr->user)->name,
                    'adm_no' => $sr->adm_no,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Admission-number lookup for the picker cascade (the Issue
     * Certificate "Find" box): one student, with the class and
     * section the cascade must jump to. Mirrors
     * CertificateController::create's own lookup — no grad filter,
     * because a transfer certificate is often FOR a student who
     * has just left. [] when there is no such student (or a
     * teacher caller may not act on them).
     */
    public function get_student_by_adm_no(Request $request)
    {
        $user = Auth::user();
        if (!$user || in_array($user->user_type, ['student', 'parent'], true)) {
            return [];
        }

        $admNo = strtoupper(trim((string) $request->query('adm_no', '')));
        if ($admNo === '') {
            return [];
        }

        $sr = StudentRecord::with('user')->where('adm_no', $admNo)->first();
        if (!$sr) {
            return [];
        }

        if (Qs::userIsTeacher()
            && !in_array((int) $sr->user_id, $this->cascadeTeacherStudentUserIds($user), true)) {
            return [];
        }

        return [
            'id' => $sr->id,
            'user_id' => (int) $sr->user_id,
            'name' => optional($sr->user)->name,
            'adm_no' => $sr->adm_no,
            'class_id' => (int) $sr->my_class_id,
            'section_id' => (int) $sr->section_id,
        ];
    }

    /**
     * Student user ids a teacher may act on in the pickers — the
     * writable-students rule from HealthRecordController: the
     * canonical TeacherScope set plus every student of a section
     * where the teacher holds a section-subject override row.
     */
    protected function cascadeTeacherStudentUserIds($user): array
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

    public function get_class_subjects(Request $req, $class_id)
    {
        $sections = $this->my_class->getClassSections($class_id);
        $subjects = $this->my_class->findSubjectByClass($class_id);

        if(Qs::userIsTeacher()){
            $subjects = $this->my_class->findSubjectByTeacher(Auth::user()->id)->where('my_class_id', $class_id);
        }

        // Opt-in effective mode (scoped-options audit, 2026-11-01):
        // only the Study Materials form asks for it — sections
        // narrow to her effective-unit sections and subjects to
        // her effective subjects (overrides included, which the
        // class-level teacher filter above misses). Every other
        // caller keeps the behaviour it has today.
        if ($req->query('effective') && Qs::userIsTeacher()) {
            $unit = SectionTeacher::unitSectionIdsByClass(Auth::user());
            $sections = $sections->whereIn('id', $unit[(int) $class_id] ?? [])->values();
            $subjects = $this->my_class->findSubjectByClass($class_id)
                ->whereIn('id', SectionTeacher::unitSubjectIds(Auth::user()))->values();
        }

        $d['sections'] = $sections->map(function($q){
            return ['id' => $q->id, 'name' => $q->name];
        })->all();
        $d['subjects'] = $subjects->map(function($q){
            return ['id' => $q->id, 'name' => $q->name];
        })->all();

        return $d;
    }

}
