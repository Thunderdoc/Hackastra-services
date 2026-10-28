<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Support\TeacherScope;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 18 — override REMOVAL: scope loss vs ownership
 * persistence. Rounds 8 + 10 imply a subtle distinction but never
 * proved it together: removing an override re-scopes a teacher
 * WITHOUT deleting their work.
 *
 * Fixture (fully fresh, rolls back): a fresh class, one fresh
 * section, one fresh subject directly taught by teacher D. A
 * fresh override teacher O holds ONLY the override row for
 * (section, subject) — so O qualifies for the class ONLY via
 * that override — and O CREATES an assignment for the
 * section/subject while the override stands.
 *
 * Then an admin removes the override through the real matrix
 * save (blank clears, round 5: PUT sections.teachers.update
 * with teachers[subject] = ''). The four claims:
 *
 * REAL FINDING F-R18-1, fixed in this round (AssignmentController
 * is in F scope): claim (c) FAILED on first run — edit opened
 * (200, ownership-only) but every update save 403d, because
 * validateAssignment re-validates via teacherIdFor, which now
 * resolves to the direct teacher. That froze the teacher's own
 * assignments: the new subject teacher cannot edit them either
 * (ownership gate) and admin can only delete. The fix exempts
 * ONLY a non-retargeting update (submitted class/section/subject
 * triple identical to the existing row — no new teaching
 * surface gained) from the teacherIdFor check; retargets and
 * creates still check. Guard-proof below in the round report.
 *
 *   (a) O's TeacherScope classIds drops the class immediately
 *       (exact [] — no reset, no re-login);
 *   (b) the students-list gate for the class now refuses O
 *       (403, as the gate intends);
 *   (c) BUT O can STILL edit + update the assignment she
 *       created — edit/update gate on teacher_id ONLY
 *       (AssignmentController::edit/update check
 *       $assignment->teacher_id == Auth::id(), never scope),
 *       so removal does not retroactively orphan her work;
 *   (d) O can NO longer CREATE a new assignment for that
 *       override subject — store re-validates via
 *       SectionTeacher::teacherIdFor, which now falls back to
 *       the direct teacher D (403).
 *
 * A fourth test is the positive control for (d): after the
 * removal, D himself CAN create for the section/subject —
 * proving creation is blocked for O because resolution MOVED
 * to D, not because creation broke entirely.
 *
 * Route mechanics (from rounds 5/16/17): sections.teachers.update
 * takes Qs::hash($section->id); assignments.update takes the RAW
 * assignment id ({assignment} is not a hash-bound {id} param).
 */
class OwnerAllotmentRound18Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R18',
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

    /** Fresh class + section + subject directly taught by $direct; returns [$class, $section, $subject]. */
    protected function fixture(User $direct, string $suffix): array
    {
        $class = MyClass::create(['name' => 'ProbeRemovalClass R18' . $suffix]);
        $section = Section::create([
            'name' => 'R18 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
        ]);
        $subject = Subject::create([
            'name' => 'Probe Removal Subject R18' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
            'slug' => 'probe-removal-r18' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $section, $subject];
    }

    /** Give $override the override row, and have her create an assignment for it. Returns the assignment. */
    protected function setupOverrideWithAssignment(User $override, MyClass $class, Section $section, Subject $subject): Assignment
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

        // O creates while the override stands (round-6 gate: teacherIdFor == O).
        $assignment = null;
        $this->actingAs($override)
            ->post(route('assignments.store'), [
                'title' => 'R18 removal probe',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertRedirect();
        $assignment = Assignment::where('title', 'R18 removal probe')->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame($override->id, (int) $assignment->teacher_id);

        return $assignment;
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

    public function test_override_removal_drops_scope_and_gate_immediately()
    {
        $d = $this->makeTeacher('PROBE-R18-D', 'probe-r18-d@example.com');
        $o = $this->makeTeacher('PROBE-R18-O', 'probe-r18-o@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'A');
        $this->setupOverrideWithAssignment($o, $class, $section, $subject);

        // Sanity before removal: override-only scope + gate open.
        $this->assertSame([$class->id], TeacherScope::classIds($o));
        $this->actingAs($o)->get(route('students.list', [$class->id]))->assertOk();

        $this->removeOverride($section, $subject);

        // (a) Scope gone immediately, no reset, no re-login.
        $this->assertSame([], TeacherScope::classIds($o));
        // Resolution fell back to the direct teacher.
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($section->id, $subject));
        // (b) The gated page refuses her now.
        $this->actingAs($o)->get(route('students.list', [$class->id]))->assertForbidden();
    }

    public function test_override_removal_keeps_ownership_of_created_assignment()
    {
        $d = $this->makeTeacher('PROBE-R18-D2', 'probe-r18-d2@example.com');
        $o = $this->makeTeacher('PROBE-R18-O2', 'probe-r18-o2@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'B');
        $assignment = $this->setupOverrideWithAssignment($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);
        $this->assertSame([], TeacherScope::classIds($o));

        // (c) Ownership persists: edit + update still work, even
        // though she no longer has scope for the class. The
        // edit/update gate checks teacher_id only, never scope.
        $this->actingAs($o)
            ->get(route('assignments.edit', $assignment->id))
            ->assertOk();
        $this->actingAs($o)
            ->put(route('assignments.update', $assignment->id), [
                'title' => 'R18 removal probe (edited after removal)',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertRedirect(route('assignments.show', $assignment->id));
        $this->assertSame('R18 removal probe (edited after removal)', $assignment->fresh()->title);
    }

    public function test_override_removal_blocks_new_assignment_creation()
    {
        $d = $this->makeTeacher('PROBE-R18-D3', 'probe-r18-d3@example.com');
        $o = $this->makeTeacher('PROBE-R18-O3', 'probe-r18-o3@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'C');
        $this->setupOverrideWithAssignment($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // (d) New creation for the removed override subject is
        // refused: store re-validates via teacherIdFor, now D.
        $before = Assignment::where('title', 'R18 removal probe second')->count();
        $this->actingAs($o)
            ->post(route('assignments.store'), [
                'title' => 'R18 removal probe second',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertForbidden();
        $this->assertSame($before, Assignment::where('title', 'R18 removal probe second')->count());
    }

    public function test_override_removal_still_refuses_retarget_to_untaught_subject()
    {
        $d = $this->makeTeacher('PROBE-R18-D5', 'probe-r18-d5@example.com');
        $o = $this->makeTeacher('PROBE-R18-O5', 'probe-r18-o5@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'E');
        $assignment = $this->setupOverrideWithAssignment($o, $class, $section, $subject);

        // A second subject in the SAME class, taught by D, with no
        // override: same class, so the subject-class check passes
        // and only the teacherIdFor check can refuse.
        $other = Subject::create([
            'name' => 'Probe Removal Other R18E',
            'my_class_id' => $class->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-removal-other-r18e',
            'periods_per_week' => 6,
        ]);

        $this->removeOverride($section, $subject);

        // Retargeting her own row to a subject she does not teach
        // is still refused — the round-18 exemption covers ONLY
        // non-retargeting saves. The row is untouched.
        $this->actingAs($o)
            ->put(route('assignments.update', $assignment->id), [
                'title' => 'R18 hijack retarget',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $other->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertForbidden();
        $this->assertSame('R18 removal probe', $assignment->fresh()->title);
        $this->assertSame($subject->id, (int) $assignment->fresh()->subject_id);
    }

    public function test_direct_teacher_can_create_after_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R18-D4', 'probe-r18-d4@example.com');
        $o = $this->makeTeacher('PROBE-R18-O4', 'probe-r18-o4@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'D');
        $this->setupOverrideWithAssignment($o, $class, $section, $subject);

        $this->removeOverride($section, $subject);

        // Control: creation is not broken entirely — resolution
        // moved to D, and D can create for the section/subject.
        $this->actingAs($d)
            ->post(route('assignments.store'), [
                'title' => 'R18 removal probe direct',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('assignments', [
            'title' => 'R18 removal probe direct',
            'teacher_id' => $d->id,
        ]);
    }
}
