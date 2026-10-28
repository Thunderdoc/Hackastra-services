<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\MyClass;
use App\Models\QuestionBankQuestion;
use App\Models\Quiz;
use App\Models\LessonPlan;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Teacher class allotment audit (2026-10-28), round 6: the F1 cousin
 * question — do the Wave 1 teacher-owner checks use the EFFECTIVE
 * teacher (SectionTeacher::teacherIdFor, override-aware) or the RAW
 * subjects.teacher_id?
 *
 * Seed anchors: Ritu Sharma (id 44, teacher20@teacher.com) teaches
 * ONLY through overrides — Class 11 (my_class_id 12) sections A/D/G
 * (55/58/61) subject 67 English (class teacher 12, Priya Nair) and
 * Class 12 (my_class_id 13) sections B/H (65/71) subject 79 English
 * (class teacher 4, Ananya Sharma). Section 56 (Class 11-B) is the
 * same class + subject with NO Ritu override — the negative case.
 */
class OwnerAllotmentRound6Test extends TestCase
{
    use DatabaseTransactions;

    protected function ritu()
    {
        return User::where('email', 'teacher20@teacher.com')->firstOrFail();
    }

    protected function priya()
    {
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function makeTeacher($code, $email)
    {
        return User::create([
            'name' => 'Probe Teacher R6',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    public function test_override_teacher_can_create_assignment_for_override_section()
    {
        $response = $this->actingAs($this->ritu())->post(route('assignments.store'), [
            'title' => 'R6 Override Assignment',
            'my_class_id' => 12,
            'section_id' => 55,
            'subject_id' => 67,
            'max_marks' => 100,
            'due_at' => '2026-12-01 10:00:00',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('assignments', [
            'title' => 'R6 Override Assignment',
            'teacher_id' => $this->ritu()->id,
            'my_class_id' => 12,
            'section_id' => 55,
            'subject_id' => 67,
        ]);
    }

    public function test_override_teacher_assignment_for_foreign_section_still_refused()
    {
        $response = $this->actingAs($this->ritu())->post(route('assignments.store'), [
            'title' => 'R6 Foreign Assignment',
            'my_class_id' => 12,
            'section_id' => 56,
            'subject_id' => 67,
            'max_marks' => 100,
            'due_at' => '2026-12-01 10:00:00',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('assignments', ['title' => 'R6 Foreign Assignment']);
    }

    public function test_override_teacher_assignment_create_picker_lists_override_subject()
    {
        $response = $this->actingAs($this->ritu())->get(route('assignments.create'));
        $response->assertOk();
        $subjects = $response->getOriginalContent()->getData()['subjects'];
        $this->assertContains(67, $subjects->pluck('id')->all(), 'Override subject 67 must appear in the create picker.');
    }

    public function test_override_teacher_can_store_material_for_override_section()
    {
        $response = $this->actingAs($this->ritu())->post(route('materials.store'), [
            'title' => 'R6 Override Material',
            'my_class_id' => 12,
            'section_id' => 55,
            'subject_id' => 67,
            'link_url' => 'https://example.com/r6-notes.pdf',
        ]);

        $response->assertRedirect(route('materials.index'));
        $this->assertDatabaseHas('study_materials', [
            'title' => 'R6 Override Material',
            'teacher_id' => $this->ritu()->id,
            'my_class_id' => 12,
            'section_id' => 55,
            'subject_id' => 67,
        ]);
    }

    public function test_override_teacher_material_for_foreign_section_still_refused()
    {
        $response = $this->actingAs($this->ritu())->post(route('materials.store'), [
            'title' => 'R6 Foreign Material',
            'my_class_id' => 12,
            'section_id' => 56,
            'subject_id' => 67,
            'link_url' => 'https://example.com/r6-foreign.pdf',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('study_materials', ['title' => 'R6 Foreign Material']);
    }

    public function test_override_teacher_material_create_picker_lists_override_subject()
    {
        $response = $this->actingAs($this->ritu())->get(route('materials.create'));
        $response->assertOk();
        $subjects = $response->getOriginalContent()->getData()['subjects'];
        $this->assertContains(67, $subjects->pluck('id')->all(), 'Override subject 67 must appear in the material create picker.');
    }

    public function test_override_teacher_push_internal_for_own_override_assignment()
    {
        // An override teacher's own section-scoped assignment must be
        // pushable: the push path must resolve the effective teacher
        // for the assignment's section, not the raw class teacher.
        $assignment = Assignment::create([
            'teacher_id' => $this->ritu()->id,
            'my_class_id' => 12,
            'section_id' => 55,
            'subject_id' => 67,
            'title' => 'R6 Push Assignment',
            'max_marks' => 100,
            'due_at' => '2026-12-01 10:00:00',
        ]);
        // Exam 1 (Term 1, global) is unpublished locally, so the push
        // must get PAST the teaches gate; it may then fail on the
        // scheme (subject 67 for exam 1 has a configured scheme, so
        // the push proceeds to the marks write path — assert it does
        // not bounce with the teaches error).
        $response = $this->actingAs($this->ritu())->post(
            route('assignments.push_internal', $assignment->id),
            ['exam_id' => 1, 'mode' => 'all']
        );
        $response->assertRedirect();
        $this->assertStringNotContainsString(
            'You do not teach this subject',
            (string) $response->getSession()->get('flash_danger', ''),
            'Override teacher must pass the push teaches gate.'
        );
    }

    public function test_override_teacher_quiz_create_already_effective()
    {
        $response = $this->actingAs($this->ritu())->post(route('quizzes.store'), [
            'title' => 'R6 Override Quiz',
            'subject_id' => 67,
            'section_id' => 55,
            'time_limit_minutes' => 30,
            'questions' => [
                [
                    'question' => 'Choose the noun.',
                    'options' => ['Run', 'Apple', 'Quickly', 'Blue'],
                    'correct_index' => 1,
                    'marks' => 2,
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('quizzes', [
            'title' => 'R6 Override Quiz',
            'teacher_id' => $this->ritu()->id,
            'subject_id' => 67,
            'section_id' => 55,
        ]);
    }

    public function test_override_teacher_lesson_plan_create_already_effective()
    {
        $response = $this->actingAs($this->ritu())->post(route('lesson_plans.store'), [
            'my_class_id' => 12,
            'section_id' => 55,
            'subject_id' => 67,
            'week_start' => '2026-10-26',
            'topic' => 'R6 Override Plan Topic',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lesson_plans', [
            'topic' => 'R6 Override Plan Topic',
            'teacher_id' => $this->ritu()->id,
            'subject_id' => 67,
            'section_id' => 55,
        ]);
    }

    public function test_override_teacher_question_bank_already_effective()
    {
        $response = $this->actingAs($this->ritu())->post(route('question_bank.store'), [
            'my_class_id' => 12,
            'subject_id' => 67,
            'section_id' => 55,
            'question' => 'R6 bank question?',
            'options' => ['A', 'B', 'C', 'D'],
            'correct_index' => 0,
            'marks' => 2,
        ]);

        $response->assertRedirect(route('question_bank.index'));
        $this->assertDatabaseHas('question_bank_questions', [
            'question' => 'R6 bank question?',
            'teacher_id' => $this->ritu()->id,
            'subject_id' => 67,
        ]);
    }

    public function test_homework_tracker_follows_assignment_ownership()
    {
        $assignment = Assignment::create([
            'teacher_id' => $this->ritu()->id,
            'my_class_id' => 12,
            'section_id' => 55,
            'subject_id' => 67,
            'title' => 'R6 Tracker Assignment',
            'max_marks' => 100,
            'due_at' => '2026-12-01 10:00:00',
        ]);

        // Owner sees her own assignment's detail.
        $this->actingAs($this->ritu())
            ->get(route('homework_tracker.index', ['assignment_id' => $assignment->id]))
            ->assertOk()
            ->assertSee('R6 Tracker Assignment');

        // A different teacher — even the class teacher of the subject
        // — must not open another teacher's tracker detail.
        $this->actingAs($this->priya())
            ->get(route('homework_tracker.index', ['assignment_id' => $assignment->id]))
            ->assertForbidden();
    }

    public function test_subject_teacher_change_propagates_immediately()
    {
        $t1 = $this->makeTeacher('PROBE-R6-T1', 'probe-r6-t1@example.com');
        $t2 = $this->makeTeacher('PROBE-R6-T2', 'probe-r6-t2@example.com');
        $class = MyClass::create(['name' => 'Probe Class R6']);
        $subject = Subject::create([
            'name' => 'Probe R6 Subject',
            'my_class_id' => $class->id,
            'teacher_id' => $t1->id,
            'slug' => 'probe-r6-subject',
            'periods_per_week' => 6,
        ]);

        $this->assertTrue(TeacherScope::canTeachClass($t1, $class->id));
        $this->assertFalse(TeacherScope::canTeachClass($t2, $class->id));
        $this->actingAs($t1)->get(route('students.list', $class->id))->assertOk();
        // Denied teachers get the controller's 403, not a redirect.
        $this->actingAs($t2)->get(route('students.list', $class->id))->assertForbidden();

        // Reassign the subject teacher. Scope is read fresh on
        // every call (round-8 fix: no static memo), so propagation
        // is immediate by construction — no reset step needed.
        $subject->update(['teacher_id' => $t2->id]);

        $this->assertFalse(TeacherScope::canTeachClass($t1, $class->id));
        $this->assertTrue(TeacherScope::canTeachClass($t2, $class->id));
        $this->actingAs($t1)->get(route('students.list', $class->id))->assertForbidden();
        $this->actingAs($t2)->get(route('students.list', $class->id))->assertOk();
    }
}
