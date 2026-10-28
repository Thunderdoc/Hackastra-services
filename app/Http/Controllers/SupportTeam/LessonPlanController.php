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
     * controller gates everything. create/store/destroy are teacher
     * only, and only for classes/subjects the teacher teaches
     * (effective teacher via SectionTeacher, so a section override
     * teacher counts for that section). index/show are teacher (own
     * plans only) plus admin (all plans, read-only oversight).
     * Everyone else — students, parents, super admin — gets 403.
     */

    public function index(Request $request)
    {
        $user = Auth::user();
        // Limited principal role (owner order 2026-10-28): the
        // principal reads every plan school-wide (the ->when
        // below scopes teachers only, so principals are
        // unscoped by construction); plan writes stay teacher/.
        if (!Qs::userIsTeacher() && !Qs::userIsAdmin() && !Qs::userIsPrincipal()) {
            abort(403);
        }

        $plans = LessonPlan::with(['my_class', 'section', 'subject', 'teacher'])
            ->when(Qs::userIsTeacher(), function ($q) use ($user) {
                $q->where('teacher_id', $user->id);
            })
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
        if (!Qs::userIsTeacher()) {
            abort(403);
        }

        return view('pages.support_team.lesson_plans.create', $this->formData(Auth::id()));
    }

    public function store(Request $request)
    {
        if (!Qs::userIsTeacher()) {
            abort(403);
        }

        $data = $request->validate([
            'my_class_id' => 'required|exists:my_classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'subject_id' => 'required|exists:subjects,id',
            'week_start' => 'required|date',
            'topic' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $subject = Subject::findOrFail($data['subject_id']);

        // Scope guards: the subject must belong to the chosen class,
        // the section (when given) must belong to the class, and the
        // caller must be the subject's effective teacher for that
        // section (section override wins, else the class teacher).
        if ((int) $subject->my_class_id !== (int) $data['my_class_id']) {
            abort(403);
        }
        if (!empty($data['section_id'])) {
            $section = Section::findOrFail($data['section_id']);
            if ((int) $section->my_class_id !== (int) $data['my_class_id']) {
                abort(403);
            }
        }
        if ((int) SectionTeacher::teacherIdFor($data['section_id'] ?? null, $subject) !== (int) Auth::id()) {
            abort(403);
        }

        // Normalize the week to its Monday, whatever weekday was picked.
        $monday = Carbon::parse($data['week_start'])->startOfDay();
        $monday = $monday->subDays($monday->dayOfWeekIso - 1);

        $plan = LessonPlan::create([
            'teacher_id' => Auth::id(),
            'my_class_id' => $data['my_class_id'],
            'section_id' => $data['section_id'] ?? null,
            'subject_id' => $data['subject_id'],
            'week_start' => $monday->toDateString(),
            'topic' => trim($data['topic']),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('lesson_plans.show', $plan->id)
            ->with('flash_success', 'Lesson plan saved.');
    }

    public function show(LessonPlan $lesson)
    {
        $user = Auth::user();
        if (Qs::userIsTeacher()) {
            if ((int) $lesson->teacher_id !== (int) $user->id) {
                abort(403);
            }
        } elseif (!Qs::userIsAdmin() && !Qs::userIsPrincipal()) {
            // Round-2 roles fix: the principal's index lists every plan
            // school-wide with a View link, so show must open them too.
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
                ->when(Qs::userIsTeacher(), function ($q) use ($user) {
                    $q->where('teacher_id', $user->id);
                })
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
        $ownTeacher = Qs::userIsTeacher() && (int) $lesson->teacher_id === (int) $user->id;
        if (!$ownTeacher && !Qs::userIsAdmin()) {
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
