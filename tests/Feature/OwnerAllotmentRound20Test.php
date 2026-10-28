<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\LessonPlan;
use App\Models\MyClass;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Agent F round 20 — quiz + lesson-plan edit-after-removal.
 *
 * The round-18 freeze question, asked of the two remaining
 * teacher-work surfaces. Two brief-premise corrections, read from
 * code and routes BEFORE writing any test (stated openly, the
 * way E round 20 corrected its own brief):
 *
 * 1. NEITHER surface has an edit/update route. Quizzes: index /
 *    create / store / show / attempt / results / review /
 *    questions.store / questions.update / questions.destroy /
 *    publish / unpublish / push-internal — no quizzes.update.
 *    Lesson plans: index / create / store / show / destroy —
 *    no lesson_plans.edit, no lesson_plans.update. So the
 *    brief's (a) "open the edit page" and (b) "save a
 *    non-retargeting edit" are N/A for both surfaces as literal
 *    steps; the real post-creation mutations are probed instead
 *    (quiz: review + question update + publish + pushInternal;
 *    lesson plan: show + destroy), and the route-absence itself
 *    is locked (Route::has assertions) so a future update route
 *    must reconsider retarget gating instead of slipping in.
 * 2. The quiz triple (class/section/subject) is set at store
 *    and NEVER mutable afterwards — so the brief's (c)
 *    "retargeting edit refused" is true by construction for
 *    quizzes, not by a gate. The test locks the route absence
 *    rather than pretending a gate refused a retarget.
 *
 * Gate shapes (read, not assumed):
 * - Quiz review / question store+update+destroy / publish /
 *   unpublish / pushInternal: teacherOwns ONLY (quiz.teacher_id
 *   === caller). They NEVER re-check teacherIdFor. So after
 *   override removal the owner keeps full quiz lifecycle —
 *   no freeze (unlike assignments pre-round-18).
 * - Quiz store: teacherMayQuiz -> teacherIdFor (round 6).
 *   Create for a removed subject is refused (regression
 *   control).
 * - Lesson plan show: teacher ownership ONLY (teacher_id ===
 *   caller). Read survives removal.
 * - Lesson plan store: teacherIdFor (round 6). Create for a
 *   removed subject is refused (regression control).
 * - Lesson plan destroy (PRE-FIX): teacher-only + ownership +
 *   teacherIdFor. After removal the creator fails teacherIdFor,
 *   the new effective teacher fails ownership, and admin fails
 *   the teacher-only check — NOBODY can delete the plan through
 *   the app. A true orphan freeze, worse than round 18's
 *   assignment case (where admin could at least delete).
 *
 * REAL FINDING F-R20-1, fixed in this round
 * (LessonPlanController is in F scope): destroy is now
 * ownership-or-admin — the EXACT gate shape of assignment
 * destroy and material destroy (both: ownTeacher OR admin, no
 * teacherIdFor check). That resolves the orphan two ways: the
 * creator can delete her own plan after removal (ownership
 * persistence, the round-18 principle), and admin can delete
 * any plan (escape hatch — also closes the case where the
 * creator is later suspended/terminated and can never log in
 * to delete). Non-owners (incl. the new effective teacher and
 * the principal) are still refused.
 *
 * Fixture (fully fresh, rolls back): a fresh class, one fresh
 * section led by D, one fresh subject directly taught by D. A
 * fresh override teacher O holds ONLY the override row for
 * (section, subject) — so O qualifies for the class ONLY via
 * that override — and O CREATES a quiz + a lesson plan for
 * the section/subject while the override stands. Then an admin
 * removes the override through the real matrix save (blank
 * clears, round 5).
 *
 * Route mechanics (from rounds 5/16/17/18/19):
 * sections.teachers.update takes Qs::hash($section->id);
 * quizzes.* and lesson_plans.* take RAW ids ({quiz}, {lesson}
 * are not hash-bound {id} params).
 */
class OwnerAllotmentRound20Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R20',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Fresh class + section (led by $direct) + subject directly taught by $direct. */
    protected function fixture(User $direct, string $suffix): array
    {
        $class = MyClass::create(['name' => 'ProbeRemovalClass R20' . $suffix]);
        $section = Section::create([
            'name' => 'R20 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
        ]);
        $subject = Subject::create([
            'name' => 'Probe Removal Subject R20' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
            'slug' => 'probe-removal-r20' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $section, $subject];
    }

    /** Give $override the override row, and have her create a quiz + a lesson plan for it. */
    protected function setupOverrideWithWork(User $override, MyClass $class, Section $section, Subject $subject): array
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $override->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame([$class->id], TeacherScope::classIds($override));
        $this->assertSame($override->id, SectionTeacher::teacherIdFor($section->id, $subject));

        // O creates the quiz while the override stands (teacherMayQuiz == O).
        $this->actingAs($override)
            ->post(route('quizzes.store'), [
                'title' => 'R20 removal probe quiz',
                'subject_id' => $subject->id,
                'section_id' => $section->id,
                'questions' => [[
                    'question' => 'R20 original question?',
                    'options' => ['Alpha', 'Beta'],
                    'correct_index' => 0,
                    'marks' => 1,
                ]],
            ])
            ->assertRedirect();
        $quiz = Quiz::where('title', 'R20 removal probe quiz')->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame($override->id, (int) $quiz->teacher_id);
        $question = QuizQuestion::where('quiz_id', $quiz->id)->firstOrFail();

        // O creates the lesson plan while the override stands (teacherIdFor == O).
        $this->actingAs($override)
            ->post(route('lesson_plans.store'), [
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'week_start' => '2026-11-02',
                'topic' => 'R20 removal probe topic',
                'notes' => 'probe',
            ])
            ->assertRedirect();
        $plan = LessonPlan::where('topic', 'R20 removal probe topic')->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame($override->id, (int) $plan->teacher_id);

        return [$quiz, $question, $plan];
    }

    /** Admin removes the override via the real matrix save (blank clears). */
    protected function removeOverride(Section $section, Subject $subject): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), [
                'teachers' => [$subject->id => ''],
            ])
            ->assertRedirect(route('sections.index'));
        $this->assertDatabaseMissing('section_subject_teachers', [
            'section_id' => $section->id,
            'subject_id' => $subject->id,
        ]);
    }

    public function test_no_quiz_or_lesson_plan_update_route_exists()
    {
        // Locks the premise: neither surface has an edit/update
        // route, so neither triple can be retargeted after
        // creation. If a future update route appears, its
        // retarget gating must be designed then, not assumed.
        $this->assertFalse(Route::has('quizzes.update'));
        $this->assertFalse(Route::has('quizzes.edit'));
        $this->assertFalse(Route::has('lesson_plans.update'));
        $this->assertFalse(Route::has('lesson_plans.edit'));
    }

    public function test_quiz_lifecycle_survives_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D', 'probe-r20-d@example.com');
        $o = $this->makeTeacher('PROBE-R20-O', 'probe-r20-o@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'A');
        [$quiz, $question, $plan] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);
        $this->assertSame([], TeacherScope::classIds($o));
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($section->id, $subject));

        // (a) Review page opens: ownership-only gate, no scope check.
        $this->actingAs($o)->get(route('quizzes.review', $quiz->id))->assertOk();

        // (b) Question edit saves: updateQuestion checks
        // teacherOwns only, never teacherIdFor — no round-18
        // freeze on this surface.
        $this->actingAs($o)
            ->put(route('quizzes.questions.update', [$quiz->id, $question->id]), [
                'question' => 'R20 edited question?',
                'options' => ['Alpha', 'Beta', 'Gamma'],
                'correct_index' => 2,
                'marks' => 2,
            ])
            ->assertRedirect();
        $this->assertSame('R20 edited question?', $question->fresh()->question);
        $this->assertSame(2, (int) $question->fresh()->correct_index);

        // Publish still works: ownership-only gate, and the quiz
        // still holds its (edited) question.
        $this->actingAs($o)
            ->post(route('quizzes.publish', $quiz->id), [])
            ->assertRedirect();
        $this->assertSame('published', $quiz->fresh()->status);
    }

    public function test_quiz_create_refused_after_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D2', 'probe-r20-d2@example.com');
        $o = $this->makeTeacher('PROBE-R20-O2', 'probe-r20-o2@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'B');
        $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // (d) New creation for the removed subject is refused:
        // store re-validates via teacherMayQuiz -> teacherIdFor,
        // which now resolves to D.
        $before = Quiz::where('title', 'R20 removal probe quiz second')->count();
        $this->actingAs($o)
            ->post(route('quizzes.store'), [
                'title' => 'R20 removal probe quiz second',
                'subject_id' => $subject->id,
                'section_id' => $section->id,
                'questions' => [[
                    'question' => 'Second?',
                    'options' => ['One', 'Two'],
                    'correct_index' => 1,
                    'marks' => 1,
                ]],
            ])
            ->assertForbidden();
        $this->assertSame($before, Quiz::where('title', 'R20 removal probe quiz second')->count());
    }

    public function test_quiz_push_internal_gate_is_ownership_only_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D3', 'probe-r20-d3@example.com');
        $o = $this->makeTeacher('PROBE-R20-O3', 'probe-r20-o3@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'C');
        [$quiz] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // pushInternal gates on teacherOwns only: after removal
        // the call must NOT 403 — it reaches the component check
        // and is refused there (fresh subject has no configured
        // Internal component for any exam) with a readable
        // flash_danger, not a gate refusal. Which danger message
        // depends on seed state (legacy scheme vs published
        // results), so only the shape is asserted, never the
        // exact wording.
        $exam = Exam::where('my_class_id', 0)->firstOrFail();
        $this->actingAs($o)
            ->post(route('quizzes.push_internal', $quiz->id), ['exam_id' => $exam->id])
            ->assertRedirect()
            ->assertSessionHas('flash_danger');
    }

    public function test_lesson_plan_show_survives_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D4', 'probe-r20-d4@example.com');
        $o = $this->makeTeacher('PROBE-R20-O4', 'probe-r20-o4@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'D');
        [, , $plan] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // Read survives: show gates on teacher_id only.
        $this->actingAs($o)->get(route('lesson_plans.show', $plan->id))->assertOk();
        // And the plan is still listed on the owner's index.
        $this->actingAs($o)->get(route('lesson_plans.index'))->assertOk()
            ->assertSee('R20 removal probe topic');
    }

    public function test_lesson_plan_destroy_by_creator_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D5', 'probe-r20-d5@example.com');
        $o = $this->makeTeacher('PROBE-R20-O5', 'probe-r20-o5@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'E');
        [, , $plan] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // F-R20-1 (pre-fix this 403d): the creator deletes her
        // own plan after removal — ownership persists, the
        // round-18 principle, now for destroy too.
        $this->actingAs($o)
            ->delete(route('lesson_plans.destroy', $plan->id))
            ->assertRedirect(route('lesson_plans.index'));
        $this->assertDatabaseMissing('lesson_plans', ['id' => $plan->id]);
    }

    public function test_lesson_plan_destroy_by_admin_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D6', 'probe-r20-d6@example.com');
        $o = $this->makeTeacher('PROBE-R20-O6', 'probe-r20-o6@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'F');
        [, , $plan] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // F-R20-1 escape hatch: admin deletes any plan — the
        // exact shape of assignment/material destroy. Pre-fix
        // admin 403d on the teacher-only check.
        $this->actingAs($this->admin())
            ->delete(route('lesson_plans.destroy', $plan->id))
            ->assertRedirect(route('lesson_plans.index'));
        $this->assertDatabaseMissing('lesson_plans', ['id' => $plan->id]);
    }

    public function test_lesson_plan_destroy_still_refuses_non_owners_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D7', 'probe-r20-d7@example.com');
        $o = $this->makeTeacher('PROBE-R20-O7', 'probe-r20-o7@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'G');
        [, , $plan] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // The fix opens deletion to owners + admin ONLY. The new
        // effective teacher (not the owner) is still refused,
        // and the row survives.
        $this->actingAs($d)
            ->delete(route('lesson_plans.destroy', $plan->id))
            ->assertForbidden();
        $this->assertDatabaseHas('lesson_plans', ['id' => $plan->id]);

        // The principal (read-only on this surface) is refused too.
        $principal = User::where('email', 'principal@hackstra.app')->firstOrFail();
        $this->actingAs($principal)
            ->delete(route('lesson_plans.destroy', $plan->id))
            ->assertForbidden();
        $this->assertDatabaseHas('lesson_plans', ['id' => $plan->id]);
    }

    public function test_lesson_plan_create_refused_after_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R20-D8', 'probe-r20-d8@example.com');
        $o = $this->makeTeacher('PROBE-R20-O8', 'probe-r20-o8@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'H');
        $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // (d) New creation for the removed subject is refused:
        // store re-validates via teacherIdFor, now D.
        $before = LessonPlan::where('topic', 'R20 removal probe topic second')->count();
        $this->actingAs($o)
            ->post(route('lesson_plans.store'), [
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'week_start' => '2026-11-09',
                'topic' => 'R20 removal probe topic second',
                'notes' => 'probe',
            ])
            ->assertForbidden();
        $this->assertSame($before, LessonPlan::where('topic', 'R20 removal probe topic second')->count());
    }

    public function test_lesson_plan_destroy_by_creator_while_teaching_still_works()
    {
        $d = $this->makeTeacher('PROBE-R20-D9', 'probe-r20-d9@example.com');
        $o = $this->makeTeacher('PROBE-R20-O9', 'probe-r20-o9@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'I');
        [, , $plan] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        // Control: the normal path (override still standing)
        // passes both pre- and post-fix — the fix changes only
        // the after-removal case.
        $this->actingAs($o)
            ->delete(route('lesson_plans.destroy', $plan->id))
            ->assertRedirect(route('lesson_plans.index'));
        $this->assertDatabaseMissing('lesson_plans', ['id' => $plan->id]);
    }
}
