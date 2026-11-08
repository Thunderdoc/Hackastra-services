<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\CourseNotice;
use App\Models\MyClass;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Section;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\Support\Streams;
use App\Support\StudentAttendance;
use App\Support\SubstitutionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LMS courses (owner-approved build, 2026-11-08): Learning is
 * organised as COURSES — one per subject (× section). The student
 * opens a course card and finds everything for that subject in
 * one fixed sequence: notices, study materials, assignments,
 * quizzes — pinned items first, then the teacher's sequence. The
 * teacher manages the same page the student reads: post/pin
 * notices, pin/unpin any item, drag to re-sequence.
 *
 * Scope is the house rule, enforced here on every action: a
 * student sees only their own section's course; a teacher manages
 * only units where they are the EFFECTIVE teacher
 * (SectionTeacher::teacherIdFor) or the item's own author; a
 * substitute who covers the class+subject TODAY reads the course
 * read-only (SubstitutionAccess) and can change nothing.
 */
class CourseController extends Controller
{
    protected const KINDS = [
        'material' => StudyMaterial::class,
        'assignment' => Assignment::class,
        'quiz' => Quiz::class,
        'notice' => CourseNotice::class,
    ];

    public function index(Request $req)
    {
        $user = Auth::user();

        if (Qs::userIsStudent()) {
            $sr = Qs::getSRByUserID($user->id);
            if (!$sr) {
                return $this->deny();
            }
            $subjects = Streams::subjectsForSection(
                Subject::where('my_class_id', $sr->my_class_id)->orderBy('name')->get(),
                $sr->section
            );
            $attempts = QuizAttempt::where('student_record_id', $sr->id)->get()->keyBy('quiz_id');
            $cards = $subjects->map(function ($subject) use ($sr, $user, $attempts) {
                return $this->studentCard($subject, $sr, (int) $user->id, $attempts);
            })->values();

            return view('pages.support_team.courses.index', [
                'mode' => 'student',
                'cards' => $cards,
                'sr' => $sr,
            ]);
        }

        if (Qs::userIsTeacher()) {
            $units = $this->teacherUnits($user);
            // Substitute covers for TODAY join as read-only units.
            $owned = $units->map(function ($u) {
                return $u['subject']->id . ':' . ($u['section']->id ?? 0);
            })->all();
            foreach ($this->substituteUnits($user) as $unit) {
                $key = $unit['subject']->id . ':' . ($unit['section']->id ?? 0);
                if (!in_array($key, $owned, true)) {
                    $units->push($unit);
                }
            }
            $units = $units->map(function ($unit) {
                $unit['counts'] = $this->unitCounts($unit['subject'], $unit['section']->id ?? null);
                return $unit;
            });

            return view('pages.support_team.courses.index', [
                'mode' => 'teacher',
                'units' => $units,
            ]);
        }

        return $this->deny();
    }

    public function show(Request $req, $subject_id)
    {
        $user = Auth::user();
        $subject = Subject::findOrFail((int) $subject_id);

        if (Qs::userIsStudent()) {
            $sr = Qs::getSRByUserID($user->id);
            if (!$sr || (int) $subject->my_class_id !== (int) $sr->my_class_id) {
                abort(403, __('msg.denied'));
            }
            $universe = Streams::subjectsForSection(
                Subject::where('my_class_id', $sr->my_class_id)->get(),
                $sr->section
            )->pluck('id');
            if (!$universe->contains((int) $subject->id)) {
                abort(403, __('msg.denied'));
            }

            $d = $this->courseData($subject, $sr->section_id ? (int) $sr->section_id : null, $user, $sr);
            $d['mode'] = 'student';
            $d['sr'] = $sr;
            $d['section'] = $sr->section;
            $d['can_manage'] = false;
            $d['read_only'] = false;

            return view('pages.support_team.courses.show', $d);
        }

        if (Qs::userIsTeacher()) {
            $mySections = $this->teacherSectionsFor($user, $subject);
            $requested = $req->query('section') ? (int) $req->query('section') : null;

            if ($mySections->isNotEmpty()) {
                $section = $mySections->firstWhere('id', $requested) ?: $mySections->first();
                $d = $this->courseData($subject, (int) $section->id, $user, null);
                $d['mode'] = 'teacher';
                $d['section'] = $section;
                $d['my_sections'] = $mySections;
                $d['can_manage'] = true;
                $d['read_only'] = false;

                return view('pages.support_team.courses.show', $d);
            }

            // Substitute read-only window: confirmed cover of this
            // class + subject TODAY, nothing more.
            if (SubstitutionAccess::coversSubjectOn((int) $user->id, (int) $subject->my_class_id, (int) $subject->id, now())) {
                $covered = $this->substituteUnits($user)
                    ->where('subject.id', (int) $subject->id)->values();
                $unit = $covered->firstWhere('section.id', $requested) ?: $covered->first();
                if ($unit) {
                    $section = $unit['section'];
                    $d = $this->courseData($subject, $section ? (int) $section->id : null, $user, null);
                    $d['mode'] = 'teacher';
                    $d['section'] = $section;
                    $d['my_sections'] = $covered->pluck('section')->filter();
                    $d['can_manage'] = false;
                    $d['read_only'] = true;

                    return view('pages.support_team.courses.show', $d);
                }
            }

            abort(403, __('msg.denied'));
        }

        return $this->deny();
    }

    /** Post a notice inside a course (managing teacher only). */
    public function storeNotice(Request $req, $subject_id)
    {
        $user = Auth::user();
        $subject = Subject::findOrFail((int) $subject_id);
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }
        $sectionId = $req->input('section_id') ? (int) $req->input('section_id') : null;
        if (!$this->teacherManages($user, $subject, $sectionId)) {
            abort(403, __('msg.denied'));
        }

        $req->validate([
            'body' => 'required|string|max:2000',
            'section_id' => 'nullable|integer',
            'is_pinned' => 'nullable|boolean',
        ]);

        CourseNotice::create([
            'teacher_id' => $user->id,
            'my_class_id' => $subject->my_class_id,
            'section_id' => $sectionId,
            'subject_id' => $subject->id,
            'body' => trim((string) $req->input('body')),
            'is_pinned' => (bool) $req->boolean('is_pinned'),
            'seq' => 0,
        ]);

        return redirect()->route('courses.show', array_filter([
            'subject' => $subject->id,
            'section' => $sectionId,
        ]))->with('flash_success', 'Notice posted to the course.');
    }

    /** Delete a notice (its author or the unit's teacher). */
    public function destroyNotice($notice_id)
    {
        $user = Auth::user();
        $notice = CourseNotice::findOrFail((int) $notice_id);
        if (!Qs::userIsTeacher() || !$this->canManageItem($user, $notice)) {
            abort(403, __('msg.denied'));
        }
        $subjectId = $notice->subject_id;
        $sectionId = $notice->section_id;
        $notice->delete();

        return redirect()->route('courses.show', array_filter([
            'subject' => $subjectId,
            'section' => $sectionId,
        ]))->with('flash_success', 'Notice removed.');
    }

    /** Pin / unpin any course item (managing teacher only). */
    public function togglePin(Request $req)
    {
        $user = Auth::user();
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }
        $kind = (string) $req->input('kind');
        $model = self::KINDS[$kind] ?? null;
        if (!$model) {
            abort(404);
        }
        $item = $model::findOrFail((int) $req->input('id'));
        if (!$this->canManageItem($user, $item)) {
            abort(403, __('msg.denied'));
        }
        $item->is_pinned = !$item->is_pinned;
        $item->save();

        return back()->with('flash_success', $item->is_pinned ? 'Pinned to the top of the course.' : 'Unpinned.');
    }

    /**
     * Persist a drag re-sequence within one kind of one course
     * unit. Every posted id must belong to the same unit and be
     * manageable by the teacher; the posted order becomes seq.
     */
    public function reorder(Request $req)
    {
        $user = Auth::user();
        if (!Qs::userIsTeacher()) {
            return response()->json(['ok' => false], 403);
        }
        $kind = (string) $req->input('kind');
        $model = self::KINDS[$kind] ?? null;
        $ids = collect($req->input('ids', []))->map(function ($id) {
            return (int) $id;
        })->filter()->values();
        if (!$model || $ids->isEmpty()) {
            return response()->json(['ok' => false], 422);
        }
        $items = $model::whereIn('id', $ids)->get();
        if ($items->count() !== $ids->count()) {
            return response()->json(['ok' => false], 422);
        }
        $unitKeys = $items->map(function ($item) {
            return $item->subject_id . ':' . ($item->section_id ?? 0);
        })->unique();
        if ($unitKeys->count() !== 1) {
            return response()->json(['ok' => false], 422);
        }
        foreach ($items as $item) {
            if (!$this->canManageItem($user, $item)) {
                return response()->json(['ok' => false], 403);
            }
        }
        foreach ($ids as $pos => $id) {
            $model::where('id', $id)->update(['seq' => $pos + 1]);
        }

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------
    // Assembly helpers
    // ---------------------------------------------------------------

    /** The full course dataset for one subject × section. */
    protected function courseData(Subject $subject, ?int $sectionId, $user, $sr = null): array
    {
        $scope = function ($q) use ($subject, $sectionId) {
            $q->where('my_class_id', $subject->my_class_id)
                ->where('subject_id', $subject->id)
                ->where(function ($w) use ($sectionId) {
                    $w->whereNull('section_id');
                    if ($sectionId) {
                        $w->orWhere('section_id', $sectionId);
                    }
                });
            return $q;
        };
        $ordered = function ($q) {
            return $q->orderByDesc('is_pinned')->orderBy('seq')->orderByDesc('created_at');
        };

        $d = [];
        $d['subject'] = $subject;
        $d['my_class'] = MyClass::find($subject->my_class_id);
        $d['teacher_name'] = optional(SectionTeacher::teacherFor($sectionId, $subject))->name;

        $d['notices'] = $ordered(CourseNotice::with('teacher')->tap($scope))->get();

        $d['materials'] = $ordered(StudyMaterial::with('teacher')->tap($scope))->get();

        $assignments = $ordered(Assignment::with('teacher')->tap($scope));
        if ($sr) {
            $assignments->with(['submissions' => function ($q) use ($user) {
                $q->where('student_id', $user->id);
            }]);
            $d['assignments'] = $assignments->get();
            foreach ($d['assignments'] as $a) {
                $a->setRelation('my_submission', $a->submissions->first());
            }
        } else {
            $d['assignments'] = $assignments->withCount('submissions')->get();
        }

        $quizzes = $ordered(Quiz::with('teacher')->withCount('questions')->tap($scope));
        if ($sr) {
            $attempts = QuizAttempt::where('student_record_id', $sr->id)->get()->keyBy('quiz_id');
            $d['attempts'] = $attempts;
            $d['quizzes'] = $quizzes->get()->filter(function ($quiz) use ($attempts) {
                return $this->quizAvailable($quiz) || $attempts->has($quiz->id);
            })->values();
        } else {
            $d['attempts'] = collect();
            $d['quizzes'] = $quizzes->get();
        }

        // Per-course attendance chip (student): this subject's
        // real % from the shared builder.
        $d['attendance_percent'] = null;
        if ($sr) {
            $built = StudentAttendance::build($sr);
            $row = $built['subjects']->firstWhere('subject.id', (int) $subject->id);
            $d['attendance_percent'] = $row ? $row['percent'] : null;
        }

        $d['counts'] = [
            'notices' => $d['notices']->count(),
            'materials' => $d['materials']->count(),
            'assignments' => $d['assignments']->count(),
            'quizzes' => $d['quizzes']->count(),
        ];

        return $d;
    }

    protected function studentCard(Subject $subject, $sr, int $userId, $attempts): array
    {
        $sectionId = $sr->section_id ? (int) $sr->section_id : null;
        $scope = function ($q) use ($subject, $sectionId) {
            $q->where('my_class_id', $subject->my_class_id)
                ->where('subject_id', $subject->id)
                ->where(function ($w) use ($sectionId) {
                    $w->whereNull('section_id');
                    if ($sectionId) {
                        $w->orWhere('section_id', $sectionId);
                    }
                });
            return $q;
        };

        $materials = StudyMaterial::query()->tap($scope)->count();

        $toSubmit = Assignment::query()->tap($scope)->get()
            ->filter(function ($a) use ($userId) {
                $submitted = $a->submissions()->where('student_id', $userId)->exists();
                if ($submitted) {
                    return false;
                }
                return $a->due_at === null || now()->lte($a->due_at) || $a->allow_late;
            })->count();

        $nextQuiz = 'No quiz due';
        $quizzes = Quiz::query()->tap($scope)->where('status', 'published')->get()
            ->reject(function ($q) use ($attempts) {
                return $attempts->has($q->id);
            });
        if ($quizzes->contains(function ($q) {
            return $this->quizAvailable($q);
        })) {
            $nextQuiz = 'Quiz open now';
        } else {
            $future = $quizzes->filter(function ($q) {
                return $q->available_from && now()->lt($q->available_from);
            })->sortBy('available_from')->first();
            if ($future) {
                $nextQuiz = 'Quiz opens ' . $future->available_from->format('d M');
            }
        }

        $pinnedNotices = CourseNotice::query()->tap($scope)->where('is_pinned', true)->count();

        return [
            'subject' => $subject,
            'teacher' => optional(SectionTeacher::teacherFor($sectionId, $subject))->name,
            'materials' => $materials,
            'to_submit' => $toSubmit,
            'next_quiz' => $nextQuiz,
            'pinned_notices' => $pinnedNotices,
            'is_lab' => (bool) $subject->needs_lab,
        ];
    }

    /** Teacher's own course units: [subject, section, substitute]. */
    protected function teacherUnits($user)
    {
        $units = collect();
        $subjectIds = SectionTeacher::unitSubjectIds($user);
        $subjects = Subject::whereIn('id', $subjectIds)->orderBy('name')->get();
        foreach ($subjects as $subject) {
            $sections = $this->teacherSectionsFor($user, $subject);
            if ($sections->isEmpty()) {
                // Class-level teacher of a section-less class (or a
                // subject whose sections are all overridden away):
                // keep the unit only when she is the subject's own
                // teacher — her course is the whole-class course.
                if ((int) $subject->teacher_id === (int) $user->id
                    && Section::where('my_class_id', $subject->my_class_id)->doesntExist()) {
                    $units->push(['subject' => $subject, 'section' => null, 'substitute' => false]);
                }
                continue;
            }
            foreach ($sections as $section) {
                $units->push(['subject' => $subject, 'section' => $section, 'substitute' => false]);
            }
        }

        return $units;
    }

    /** Sections of the subject's class where this teacher is the
     *  EFFECTIVE teacher of the subject. */
    protected function teacherSectionsFor($user, Subject $subject)
    {
        return Section::where('my_class_id', $subject->my_class_id)
            ->orderBy('name')->get()
            ->filter(function ($section) use ($user, $subject) {
                return SectionTeacher::teacherIdFor($section->id, $subject) === (int) $user->id;
            })->values();
    }

    /** Today's confirmed-cover units for a substitute teacher. */
    protected function substituteUnits($user)
    {
        $rows = \App\Models\Substitution::confirmed()
            ->where('substitute_teacher_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->with(['time_table.tt_record.section', 'time_table.subject'])
            ->get();

        $units = collect();
        foreach ($rows as $row) {
            $tt = $row->time_table;
            if (!$tt || !$tt->subject || !$tt->tt_record) {
                continue;
            }
            $key = $tt->subject->id . ':' . ($tt->tt_record->section_id ?? 0);
            if ($units->has($key)) {
                continue;
            }
            $units->put($key, [
                'subject' => $tt->subject,
                'section' => $tt->tt_record->section,
                'substitute' => true,
            ]);
        }

        return $units->values();
    }

    protected function unitCounts(Subject $subject, ?int $sectionId): array
    {
        $scope = function ($q) use ($subject, $sectionId) {
            $q->where('my_class_id', $subject->my_class_id)
                ->where('subject_id', $subject->id)
                ->where(function ($w) use ($sectionId) {
                    $w->whereNull('section_id');
                    if ($sectionId) {
                        $w->orWhere('section_id', $sectionId);
                    }
                });
            return $q;
        };

        return [
            'materials' => StudyMaterial::query()->tap($scope)->count(),
            'assignments' => Assignment::query()->tap($scope)->count(),
            'quizzes' => Quiz::query()->tap($scope)->count(),
            'notices' => CourseNotice::query()->tap($scope)->count(),
        ];
    }

    /** May this teacher manage this unit (subject × section)? */
    protected function teacherManages($user, Subject $subject, ?int $sectionId): bool
    {
        if ($sectionId) {
            return SectionTeacher::teacherIdFor($sectionId, $subject) === (int) $user->id;
        }

        return (int) $subject->teacher_id === (int) $user->id;
    }

    /**
     * May this teacher pin/reorder/delete this item? She manages
     * the item when she authored it, or she is the effective
     * teacher of the item's section+subject, or the subject's own
     * teacher (whole-class items).
     */
    protected function canManageItem($user, $item): bool
    {
        if ((int) $item->teacher_id === (int) $user->id) {
            return true;
        }
        $subject = $item->subject ?: Subject::find($item->subject_id);
        if (!$subject) {
            return false;
        }
        if ($item->section_id
            && SectionTeacher::teacherIdFor($item->section_id, $subject) === (int) $user->id) {
            return true;
        }

        return (int) $subject->teacher_id === (int) $user->id;
    }

    /** Same availability rule as QuizController::isAvailable. */
    protected function quizAvailable(Quiz $quiz): bool
    {
        if ($quiz->status !== 'published') {
            return false;
        }
        $now = now();
        if ($quiz->available_from && $now->lt($quiz->available_from)) {
            return false;
        }
        if ($quiz->available_until && $now->gt($quiz->available_until)) {
            return false;
        }

        return true;
    }

    protected function deny()
    {
        return redirect()->route('dashboard')->with(['pop_error' => __('msg.denied'), 'flash_danger' => __('msg.denied')]);
    }
}
