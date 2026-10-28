<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ExamSubjectComponent;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\MarkScheme;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 26 — PUSH-TO-INTERNAL after override removal.
 *
 * CODE READING FIRST (AssignmentController::pushInternal, lines
 * ~706-734): the push gates on BOTH legs —
 *   (1) ownership: teacher_id != Auth::id() aborts 403;
 *   (2) current teaching: teacherIdFor(section, subject) must
 *       equal the caller AND canTeachClass must hold, else a
 *       redirect with 'You do not teach this subject for this
 *       class.' and NO write.
 * internalPreview, by contrast, is ownership-only (line ~670):
 * after removal the owner can still OPEN the preview but every
 * push is refused — the round-18 shape (edit opens, save 403s).
 * And the new effective teacher cannot push either: leg (1)
 * 403s her (she does not own O's row), and admin cannot push at
 * all (teacher-only entry). So pre-fix the bridge is FROZEN FOR
 * EVERYONE — the same class of freeze fixed in rounds 18
 * (assignment update), 20 (lesson-plan destroy) and 21 (bank
 * question update). The quiz bridge does NOT freeze: Quiz
 * pushInternal is owner-only (round 20, clean), with no
 * teacherIdFor leg — the assignment bridge is the odd one out.
 *
 * FIX F-R26-1 (AssignmentController::pushInternal): the
 * effective-teacher + scope legs are exempt for the assignment's
 * OWNER, mirroring the round-18 non-retarget exemption. A push
 * acts on an EXISTING row and gains no new teaching surface:
 * the target is always the assignment's own class/section/
 * subject, and the population is always the owner's own
 * assignments (siblingAssignments keys on source->teacher_id,
 * never on the caller). The subject-exists + subject/class
 * integrity check STAYS, so a subject moved to another class
 * still refuses exactly as before. The published-exam and
 * Internal-component guards are untouched.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions):
 * fresh class + section, fresh subject directly taught by D with
 * an override for O, one fresh student in the section, and a
 * fresh 'Internal' component (max 30) configured for exam 1 —
 * so no seeded component row or seeded student can fake the
 * result. Exam 1 must be unpublished (asserted); the local
 * Term 1 is unpublished.
 */
class OwnerAllotmentRound26Test extends TestCase
{
    use DatabaseTransactions;

    private const TEACHES_MSG = 'You do not teach this subject for this class.';

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R26',
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

    /** Fresh class + section (led by D) + subject (direct teacher D) + O's override + one student. */
    protected function fixture(string $suffix): array
    {
        $o = $this->makeTeacher('PROBE-R26-O' . $suffix, 'probe-r26-o' . strtolower($suffix) . '@example.com');
        $d = $this->makeTeacher('PROBE-R26-D' . $suffix, 'probe-r26-d' . strtolower($suffix) . '@example.com');
        // class_type_id is REQUIRED for the marks pipeline:
        // MarkComputation::recomputeSubject reads
        // findTypeByClass($classId)->id for the grade lookup, and a
        // typeless class 500s there. Real classes always carry a
        // type (the create form posts it); 4 = Primary, as Class 1.
        $class = MyClass::create(['name' => 'ProbeR26Class ' . $suffix, 'class_type_id' => 4]);
        $section = Section::create([
            'name' => 'R26 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $d->id,
        ]);
        $subject = Subject::create([
            'name' => 'Probe Push Subject R26' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r26-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $o->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ExamSubjectComponent::create([
            'exam_id' => 1,
            'subject_id' => $subject->id,
            'name' => 'Internal',
            'short_code' => 'INT',
            'max_marks' => 30,
            'sort' => 1,
        ]);
        $student = User::create([
            'name' => 'Probe Student R26' . $suffix,
            'username' => 'PROBE-R26-S' . $suffix,
            'email' => 'probe-r26-s' . strtolower($suffix) . '@example.com',
            'password' => 'secret',
            'code' => 'PROBE-R26-S' . $suffix,
            'user_type' => 'student',
        ]);
        StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $student->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'adm_no' => 'R26-' . $suffix,
            'grad' => 0,
        ]);

        $this->assertFalse((bool) DB::table('exams')->where('id', 1)->value('results_published'),
            'Exam 1 must be unpublished or every push refuses on the published guard.');
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($section->id, $subject),
            'Fixture must start with O as the effective teacher via the override.');

        return [$o, $d, $class, $section, $subject, $student];
    }

    protected function makeAssignment(User $o, MyClass $class, Section $section, Subject $subject): Assignment
    {
        return Assignment::create([
            'teacher_id' => $o->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'title' => 'R26 push probe',
            'description' => 'probe',
            'max_marks' => 100,
            'due_at' => '2030-01-01 10:00:00',
            'allow_late' => false,
        ]);
    }

    protected function gradeAs(User $teacher, AssignmentSubmission $sub, int $marks)
    {
        return $this->actingAs($teacher)
            ->from(route('assignments.show', $this->assignmentId($sub)))
            ->post(route('assignments.grade', $sub->id), ['marks_awarded' => $marks]);
    }

    protected function assignmentId(AssignmentSubmission $sub): int
    {
        return (int) $sub->assignment_id;
    }

    protected function pushAs(User $teacher, Assignment $a)
    {
        return $this->actingAs($teacher)
            ->from(route('assignments.show', $a->id))
            ->post(route('assignments.push_internal', $a->id), ['exam_id' => 1]);
    }

    protected function internalComponentId(Subject $subject): int
    {
        foreach (MarkScheme::components(1, $subject->id) as $c) {
            if ($c['name'] === 'Internal') {
                return (int) $c['id'];
            }
        }
        $this->fail('No Internal component resolved for the fresh subject.');
    }

    protected function componentScore(User $student, Subject $subject): ?int
    {
        $markId = DB::table('marks')
            ->where('exam_id', 1)->where('student_id', $student->id)
            ->where('subject_id', $subject->id)->value('id');
        if (!$markId) {
            return null;
        }
        $score = DB::table('mark_components')
            ->where('mark_id', $markId)->where('component_id', $this->internalComponentId($subject))->value('score');

        return $score === null ? null : (int) $score;
    }

    protected function markRowCount(User $student, Subject $subject): int
    {
        return (int) DB::table('marks')
            ->where('exam_id', 1)->where('student_id', $student->id)
            ->where('subject_id', $subject->id)->count();
    }

    protected function removeOverrideViaMatrix(Section $section, Subject $subject): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertFalse(DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->where('subject_id', $subject->id)->exists(),
            'The matrix save must actually remove the override.');
    }

    public function test_push_works_with_override_intact_and_new_teacher_cannot_push()
    {
        [$o, $d, $class, $section, $subject, $student] = $this->fixture('A');
        $a = $this->makeAssignment($o, $class, $section, $subject);
        $sub = AssignmentSubmission::create([
            'assignment_id' => $a->id,
            'student_id' => $student->id,
            'text_answer' => 'probe answer',
            'submitted_at' => now(),
            'is_late' => false,
        ]);

        $this->gradeAs($o, $sub, 80)->assertRedirect();
        $this->pushAs($o, $a)
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Internal marks pushed for 1 student(s).');

        // 80% of the Internal max 30 = 24, exactly one mark row.
        $this->assertSame(24, $this->componentScore($student, $subject));
        $this->assertSame(1, $this->markRowCount($student, $subject));

        // The new (direct) teacher never had a path: ownership
        // 403s her even while the override is intact.
        $this->pushAs($d, $a)->assertForbidden();
        $this->assertSame(24, $this->componentScore($student, $subject));
    }

    public function test_owner_push_survives_override_removal_but_new_teacher_still_cannot_push()
    {
        [$o, $d, $class, $section, $subject, $student] = $this->fixture('B');
        $a = $this->makeAssignment($o, $class, $section, $subject);
        $sub = AssignmentSubmission::create([
            'assignment_id' => $a->id,
            'student_id' => $student->id,
            'text_answer' => 'probe answer',
            'submitted_at' => now(),
            'is_late' => false,
        ]);

        // Baseline push while O still teaches: 80% of 30 = 24.
        $this->gradeAs($o, $sub, 80)->assertRedirect();
        $this->pushAs($o, $a)->assertRedirect();
        $this->assertSame(24, $this->componentScore($student, $subject));

        // Remove O's override through the real matrix save:
        // effective teacher flips to D, O loses class scope.
        $this->removeOverrideViaMatrix($section, $subject);
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($section->id, $subject));

        // The owner can still open the preview (ownership-only)
        // — and, post-fix, can still push her own assignment.
        $this->actingAs($o)
            ->get(route('assignments.internal_preview', $a->id))
            ->assertOk();

        // Re-grade to 50: if the push were still refused, the
        // score would stay 24; a working push writes 15.
        $this->gradeAs($o, $sub, 50)->assertRedirect();
        $this->pushAs($o, $a)
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Internal marks pushed for 1 student(s).');
        $this->assertSame(15, $this->componentScore($student, $subject));
        $this->assertSame(1, $this->markRowCount($student, $subject),
            'Re-push must overwrite, never duplicate.');

        // The exemption is owner-scoped, not a removed gate:
        // the new effective teacher still cannot push O's row.
        $this->pushAs($d, $a)->assertForbidden();
        $this->assertSame(15, $this->componentScore($student, $subject));
    }

    public function test_owner_push_without_any_prior_push_after_removal()
    {
        [$o, $d, $class, $section, $subject, $student] = $this->fixture('C');
        $a = $this->makeAssignment($o, $class, $section, $subject);
        $sub = AssignmentSubmission::create([
            'assignment_id' => $a->id,
            'student_id' => $student->id,
            'text_answer' => 'probe answer',
            'submitted_at' => now(),
            'is_late' => false,
        ]);

        // Grade while O teaches, then remove BEFORE any push:
        // pre-fix this first push was refused with the teaches
        // message and zero component rows existed.
        $this->gradeAs($o, $sub, 80)->assertRedirect();
        $this->assertNull($this->componentScore($student, $subject));
        $this->removeOverrideViaMatrix($section, $subject);

        $this->pushAs($o, $a)
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Internal marks pushed for 1 student(s).');
        $this->assertSame(24, $this->componentScore($student, $subject));
        $this->assertSame(1, $this->markRowCount($student, $subject));

        // Guard: the teaches refusal must not reappear for the
        // owner on a second push either.
        $this->pushAs($o, $a)->assertRedirect();
        $this->assertSame(24, $this->componentScore($student, $subject));
    }
}
