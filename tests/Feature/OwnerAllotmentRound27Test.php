<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 27 — assignment GRADING + SUBMISSION after override
 * removal (the round-18/20/21/26 ownership-persistence family).
 *
 * CODE READING FIRST (AssignmentController):
 *   - grade() (~line 540) gates on OWNERSHIP ONLY:
 *     `!Qs::userIsTeacher() || $assignment->teacher_id != Auth::id()`
 *     aborts 403. There is NO teacherIdFor leg and NO scope leg —
 *     unlike pushInternal pre-fix (round 26), which gated on both
 *     ownership AND current teaching and therefore FROZE for
 *     everyone once the override was gone.
 *   - submit() (~line 472) gates on student role + studentInScope()
 *     (~line 847): assignment class == student's class AND
 *     (assignment section null OR == student's section). The
 *     assignment's TEACHER identity never enters — neither
 *     ownership nor teacherIdFor.
 *   - show/canView for a student is likewise studentInScope-only.
 *
 * EXPECTATION, therefore the opposite of round 26's freeze: grading
 * survives removal for the OWNER (ownership persists), the new
 * effective teacher still cannot grade (not the owner), and
 * submission survives removal for in-section students (the
 * teacher's identity is irrelevant to the gate). This round
 * test-locks that contrast: had either path carried a
 * teacherIdFor leg, it would have frozen exactly like the push
 * bridge did. No fix is expected; if any path does freeze, that
 * is a REAL finding in the round-26 class and must be fixed in
 * the same owner-exemption spirit.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions):
 * fresh class (type 4 = Primary, required by the marks pipeline
 * only for push tests — kept for fixture parity with round 26) +
 * section led by D + subject directly taught by D with an
 * override for O + two fresh students in the section. The
 * override is removed through the REAL matrix save
 * (sections.teachers.update), exactly as in round 26.
 */
class OwnerAllotmentRound27Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R27',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function makeStudent(string $code, string $email, MyClass $class, Section $section): User
    {
        $student = User::create([
            'name' => 'Probe Student R27',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'student',
        ]);
        StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $student->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'adm_no' => 'R27-' . $code,
            'grad' => 0,
        ]);

        return $student;
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Fresh class + section (led by D) + subject (direct teacher D) + O's override + two students. */
    protected function fixture(string $suffix): array
    {
        $o = $this->makeTeacher('PROBE-R27-O' . $suffix, 'probe-r27-o' . strtolower($suffix) . '@example.com');
        $d = $this->makeTeacher('PROBE-R27-D' . $suffix, 'probe-r27-d' . strtolower($suffix) . '@example.com');
        $class = MyClass::create(['name' => 'ProbeR27Class ' . $suffix, 'class_type_id' => 4]);
        $section = Section::create([
            'name' => 'R27 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $d->id,
        ]);
        $subject = Subject::create([
            'name' => 'Probe Grade Subject R27' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r27-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $o->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $s1 = $this->makeStudent('PROBE-R27-S1' . $suffix, 'probe-r27-s1' . strtolower($suffix) . '@example.com', $class, $section);
        $s2 = $this->makeStudent('PROBE-R27-S2' . $suffix, 'probe-r27-s2' . strtolower($suffix) . '@example.com', $class, $section);

        $this->assertSame($o->id, SectionTeacher::teacherIdFor($section->id, $subject),
            'Fixture must start with O as the effective teacher via the override.');

        return [$o, $d, $class, $section, $subject, $s1, $s2];
    }

    protected function makeAssignment(User $o, MyClass $class, Section $section, Subject $subject): Assignment
    {
        return Assignment::create([
            'teacher_id' => $o->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'title' => 'R27 grade/submit probe',
            'description' => 'probe',
            'max_marks' => 100,
            'due_at' => '2030-01-01 10:00:00',
            'allow_late' => false,
        ]);
    }

    protected function submitAs(User $student, Assignment $a, string $text)
    {
        return $this->actingAs($student)
            ->from(route('assignments.show', $a->id))
            ->post(route('assignments.submit', $a->id), ['text_answer' => $text]);
    }

    protected function gradeAs(User $teacher, AssignmentSubmission $sub, int $marks)
    {
        return $this->actingAs($teacher)
            ->from(route('assignments.show', $sub->assignment_id))
            ->post(route('assignments.grade', $sub->id), ['marks_awarded' => $marks]);
    }

    protected function submissionCount(Assignment $a, User $student): int
    {
        return (int) AssignmentSubmission::where('assignment_id', $a->id)
            ->where('student_id', $student->id)->count();
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

    public function test_grading_and_submission_work_with_override_intact()
    {
        [$o, $d, $class, $section, $subject, $s1, $s2] = $this->fixture('A');
        $a = $this->makeAssignment($o, $class, $section, $subject);

        // Control: submission + grading work while O still teaches.
        $this->submitAs($s1, $a, 'control answer')
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Assignment submitted successfully.');
        $this->assertSame(1, $this->submissionCount($a, $s1));

        $sub = AssignmentSubmission::where('assignment_id', $a->id)->where('student_id', $s1->id)->firstOrFail();
        $this->gradeAs($o, $sub, 80)->assertRedirect();
        $this->assertSame(80, (int) $sub->fresh()->marks_awarded);
        $this->assertSame($o->id, (int) $sub->fresh()->graded_by);

        // The direct teacher never owned the row, so she cannot
        // grade it even while the override is intact.
        $this->gradeAs($d, $sub, 90)->assertForbidden();
        $this->assertSame(80, (int) $sub->fresh()->marks_awarded);
    }

    public function test_owner_grading_survives_removal_but_new_teacher_still_cannot_grade()
    {
        [$o, $d, $class, $section, $subject, $s1, $s2] = $this->fixture('B');
        $a = $this->makeAssignment($o, $class, $section, $subject);

        $this->submitAs($s1, $a, 'pre-removal answer')->assertRedirect();
        $sub = AssignmentSubmission::where('assignment_id', $a->id)->where('student_id', $s1->id)->firstOrFail();
        $this->assertNull($sub->marks_awarded, 'Submission must start ungraded.');

        $this->removeOverrideViaMatrix($section, $subject);
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($section->id, $subject));

        // Grading is ownership-only: the owner still grades after
        // losing the subject. A teacherIdFor leg here would have
        // frozen grading for everyone, as push did pre-fix.
        $this->gradeAs($o, $sub, 75)->assertRedirect();
        $this->assertSame(75, (int) $sub->fresh()->marks_awarded);
        $this->assertSame($o->id, (int) $sub->fresh()->graded_by);

        // The new effective teacher is not the owner: still 403,
        // and the owner's grade stands.
        $this->gradeAs($d, $sub, 95)->assertForbidden();
        $this->assertSame(75, (int) $sub->fresh()->marks_awarded);
    }

    public function test_submission_survives_removal_for_in_section_students()
    {
        [$o, $d, $class, $section, $subject, $s1, $s2] = $this->fixture('C');
        $a = $this->makeAssignment($o, $class, $section, $subject);

        $this->submitAs($s1, $a, 'first answer')->assertRedirect();
        $this->assertSame(1, $this->submissionCount($a, $s1));

        $this->removeOverrideViaMatrix($section, $subject);
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($section->id, $subject));

        // Submit gates on class + section only, never on who
        // teaches: a second in-section student submits fresh
        // after removal (new row), and the first student's
        // ungraded resubmission updates in place (still 1 row).
        $this->submitAs($s2, $a, 'second student answer')
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Assignment submitted successfully.');
        $this->assertSame(1, $this->submissionCount($a, $s2));
        $this->assertSame(2, (int) AssignmentSubmission::where('assignment_id', $a->id)->count());

        $this->submitAs($s1, $a, 'updated answer')->assertRedirect();
        $this->assertSame(1, $this->submissionCount($a, $s1));
        $this->assertSame('updated answer',
            AssignmentSubmission::where('assignment_id', $a->id)->where('student_id', $s1->id)->value('text_answer'));
    }

    public function test_graded_lock_still_fires_after_removal()
    {
        [$o, $d, $class, $section, $subject, $s1, $s2] = $this->fixture('D');
        $a = $this->makeAssignment($o, $class, $section, $subject);

        $this->submitAs($s1, $a, 'graded answer')->assertRedirect();
        $sub = AssignmentSubmission::where('assignment_id', $a->id)->where('student_id', $s1->id)->firstOrFail();
        $this->gradeAs($o, $sub, 70)->assertRedirect();

        $this->removeOverrideViaMatrix($section, $subject);

        // The graded lock is submission-state, not teacher-state:
        // resubmission after removal is still refused and the
        // grade stands.
        $this->submitAs($s1, $a, 'changed answer')
            ->assertRedirect()
            ->assertSessionHas('flash_danger', 'This submission has already been graded and can no longer be changed.');
        $this->assertSame(1, $this->submissionCount($a, $s1));
        $this->assertSame(70, (int) $sub->fresh()->marks_awarded);
        $this->assertSame('graded answer',
            AssignmentSubmission::where('assignment_id', $a->id)->where('student_id', $s1->id)->value('text_answer'));
    }
}
