<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\LessonPlan;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LessonPlanController extends Controller
{
    /**
     * Lesson planner / syllabus tracker (Wave 1 feature 2).
     *
     * Guard model: these routes carry NO team middleware, so this
     * controller gates everything. Overnight menus 2026-10-29,
     * item 17 (image-33, full removal for teachers): teachers get
     * 403 on every action — no menu link and no server access.
     * Admin and principal keep their read oversight (index/show);
     * admin keeps delete. Create/store refuse everyone now that
     * only teachers could write — no admin-write capability is
     * invented here. Everyone else — students, parents, super
     * admin — gets 403. Code and routes stay, so the module
     * restores by re-adding the teacher link and gates.
     */

    public function index(Request $request)
    {
        $user = Auth::user();
        // Limited principal role (owner order 2026-10-28): the
        // principal reads every plan school-wide; admin reads all.
        // Teachers are out entirely (overnight menus, item 17).
        if (!Qs::userIsAdmin() && !Qs::userIsPrincipal()) {
            abort(403);
        }

        $plans = LessonPlan::with(['my_class', 'section', 'subject', 'teacher'])
            ->orderBy('week_start')
            ->orderBy('my_class_id')
            ->orderBy('subject_id')
            ->get();

        $groups = [];
        foreach ($plans->groupBy(function ($plan) {
            return $plan->my_class_id . '|' . $plan->subject_id . '|' . ($plan->section_id ?: 'all');
        }) as $groupPlans) {
            $first = $groupPlans->first();
            $stats = static::completionFor(
                $first->my_class_id,
                $first->subject_id,
                $first->section_id,
                $groupPlans->pluck('topic')->all()
            );
            $groups[] = [
                'class' => $first->my_class,
                'subject' => $first->subject,
                'section' => $first->section,
                'plans' => $groupPlans,
                'planned' => $stats['planned'],
                'covered' => $stats['covered'],
                'percent' => $stats['percent'],
                'has_sessions' => $stats['has_sessions'],
            ];
        }

        $coveredOf = [];
        foreach ($plans as $plan) {
            $coveredOf[$plan->id] = static::isCovered(
                $plan->my_class_id,
                $plan->subject_id,
                $plan->section_id,
                $plan->topic
            );
        }

        return view('pages.support_team.lesson_plans.index', [
            'groups' => $groups,
            'plans' => $plans,
            'coveredOf' => $coveredOf,
            'isTeacher' => Qs::userIsTeacher(),
        ]);
    }

    public function create()
    {
        // Overnight menus, item 17: only teachers could create,
        // and teachers are out — so creation is closed, not handed
        // to another role.
        abort(403, 'Lesson plans are not available.');
    }

    public function store(Request $request)
    {
        // Overnight menus, item 17: see create() — writes are closed.
        abort(403, 'Lesson plans are not available.');
    }

    public function show(LessonPlan $lesson)
    {
        $user = Auth::user();
        // Round-2 roles fix: the principal's index lists every plan
        // school-wide with a View link, so show must open them too.
        // Teachers are out entirely (overnight menus, item 17).
        if (!Qs::userIsAdmin() && !Qs::userIsPrincipal()) {
            abort(403);
        }

        $lesson->load(['my_class', 'section', 'subject', 'teacher']);
        $match = static::matchingTaughtTopic(
            $lesson->my_class_id,
            $lesson->subject_id,
            $lesson->section_id,
            $lesson->topic
        );
        $stats = static::completionFor(
            $lesson->my_class_id,
            $lesson->subject_id,
            $lesson->section_id,
            LessonPlan::where('my_class_id', $lesson->my_class_id)
                ->where('subject_id', $lesson->subject_id)
                ->when($lesson->section_id, function ($q) use ($lesson) {
                    $q->where('section_id', $lesson->section_id);
                })
                ->when(!$lesson->section_id, function ($q) {
                    $q->whereNull('section_id');
                })
                ->pluck('topic')->all()
        );

        return view('pages.support_team.lesson_plans.show', [
            'plan' => $lesson,
            'covered' => $match !== null,
            'matchingTopic' => $match,
            'stats' => $stats,
            'isTeacher' => Qs::userIsTeacher(),
        ]);
    }

    public function destroy(LessonPlan $lesson)
    {
        // Round-20 allotment fix (F-R20-1): destroy is
        // ownership-or-admin — the exact gate shape of assignment
        // destroy and material destroy. The previous gate ALSO
        // required the caller to be the subject's CURRENT
        // effective teacher, which froze a plan after its
        // creator's override was removed: the creator failed
        // teacherIdFor, the new effective teacher failed
        // ownership, and admin failed the teacher-only check, so
        // NOBODY could delete the plan through the app. Removing
        // an override re-scopes a teacher; it must not orphan
        // her work beyond deletion (round-18 principle). Non-
        // owners (incl. the new effective teacher and the
        // principal) are still refused.
        $user = Auth::user();
        // Overnight menus, item 17: teacher delete is gone with
        // teacher access; admin delete stays.
        if (!Qs::userIsAdmin()) {
            abort(403);
        }

        $lesson->delete();

        return redirect()->route('lesson_plans.index')
            ->with('flash_success', 'Lesson plan deleted.');
    }

    /**
     * Subjects a teacher teaches: class-level teacher, plus any
     * section where they are the override teacher.
     */
    protected function taughtSubjects(int $teacherId)
    {
        return Subject::where('teacher_id', $teacherId)
            ->orWhereExists(function ($q) use ($teacherId) {
                $q->select(DB::raw('1'))
                    ->from('section_subject_teachers')
                    ->whereColumn('section_subject_teachers.subject_id', 'subjects.id')
                    ->where('section_subject_teachers.teacher_id', $teacherId);
            })
            ->orderBy('my_class_id')
            ->orderBy('name')
            ->get();
    }

    protected function formData(int $teacherId): array
    {
        $subjects = $this->taughtSubjects($teacherId);
        $classIds = $subjects->pluck('my_class_id')->unique()->values()->all();

        return [
            'classes' => MyClass::whereIn('id', $classIds)->orderBy('id')->get(),
            'sections' => Section::whereIn('my_class_id', $classIds)->orderBy('my_class_id')->orderBy('name')->get(),
            'subjects' => $subjects,
        ];
    }

    /**
     * COMPLETION RULE (computed live, never stored): planned topics
     * are this scope's lesson_plans rows; taught topics are
     * attendance_sessions.topic values for sessions with the same
     * my_class_id and subject_id, and the same section_id when the
     * plan sets one (a whole-class plan counts sessions from every
     * section of the class). A planned topic counts as COVERED when
     * any taught topic matches it case-insensitively after trimming,
     * OR the taught topic string contains the planned topic string
     * (case-insensitive). Taught data is never invented — it comes
     * only from attendance_sessions.topic. Percent is covered /
     * planned * 100, and 0 when there are no plans.
     */
    public static function completionFor(int $classId, int $subjectId, $sectionId, array $plannedTopics): array
    {
        $taught = static::taughtTopics($classId, $subjectId, $sectionId);
        $planned = array_values(array_filter(array_map('trim', $plannedTopics), function ($t) {
            return $t !== '';
        }));
        $covered = 0;
        foreach ($planned as $topic) {
            if (static::matchesAnyTaught($topic, $taught)) {
                $covered++;
            }
        }

        return [
            'planned' => count($planned),
            'covered' => $covered,
            'percent' => count($planned) ? (int) round($covered / count($planned) * 100) : 0,
            'has_sessions' => count($taught) > 0,
        ];
    }

    public static function isCovered(int $classId, int $subjectId, $sectionId, string $topic): bool
    {
        return static::matchesAnyTaught($topic, static::taughtTopics($classId, $subjectId, $sectionId));
    }

    public static function matchingTaughtTopic(int $classId, int $subjectId, $sectionId, string $topic): ?string
    {
        $planned = trim($topic);
        if ($planned === '') {
            return null;
        }
        foreach (static::taughtTopics($classId, $subjectId, $sectionId) as $taught) {
            $clean = trim((string) $taught);
            if (mb_strtolower($clean) === mb_strtolower($planned)
                || mb_stripos($clean, $planned) !== false) {
                return $taught;
            }
        }

        return null;
    }

    protected static function matchesAnyTaught(string $topic, array $taught): bool
    {
        $planned = trim($topic);
        if ($planned === '') {
            return false;
        }
        foreach ($taught as $t) {
            $clean = trim((string) $t);
            if (mb_strtolower($clean) === mb_strtolower($planned)
                || mb_stripos($clean, $planned) !== false) {
                return true;
            }
        }

        return false;
    }

    protected static function taughtTopics(int $classId, int $subjectId, $sectionId): array
    {
        return AttendanceSession::where('my_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->when($sectionId, function ($q) use ($sectionId) {
                $q->where('section_id', $sectionId);
            })
            ->pluck('topic')
            ->filter(function ($t) {
                return trim((string) $t) !== '';
            })
            ->values()
            ->all();
    }
}
