<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent F round 17 — direct-subject teacher reassignment scope flip.
 *
 * Completes the trilogy on the round-8 memo removal: overrides
 * (round 8) and section leadership (round 16) already flip
 * immediately; this round proves the FIRST scope source — a
 * subject's own teacher_id — flips just as immediately when an
 * admin reassigns it through the real subjects.update path.
 *
 * Fixture isolation matters: both probe teachers are fresh (no
 * other subjects, no led sections, no overrides), and the probe
 * class is fresh (no seeded subject can name either teacher). So
 * teacher A qualifies ONLY via the one probe subject, and teacher
 * B qualifies for nothing — before the swap, classIds(A) is
 * exactly [class] and classIds(B) is exactly []. After the swap
 * through the real admin update path, the two lists must exchange
 * with NO reset step, NO re-login, and the gated students-list
 * page must flip with them (A 200→403, B 403→200). A third test
 * swaps back to prove the flip is bidirectional.
 *
 * Route mechanics (learned in rounds 4/16, stated so the next
 * author does not re-derive them): subjects.update is a resource
 * route whose controller resolves raw OR hashed ids
 * (Qs::resolveId), so the route param is the RAW subject id; but
 * SubjectUpdate decodes the body teacher_id from its hash
 * (getValidatorInstance), so the body value must be
 * Qs::hash($teacher->id) — a RAW id decodes to '' and is
 * normalized to NULL, which would CLEAR the teacher instead of
 * reassigning. The update is teamSAWrite (admin only).
 *
 * Eligibility gate (the trap in this round, not an app bug):
 * SubjectController refuses a reassignment when the new teacher
 * is not in Staffing::eligibleTeacherIds($subjectName), and that
 * pool includes teachers of existing same-name subjects — so a
 * live-taught subject's pool is [current teacher] and a fresh
 * teacher is refused until their eligibility is recorded (the
 * product flow: Teacher Eligibility page first, then assign).
 * The fixture therefore records eligibility for BOTH teachers up
 * front, so both swap directions pass the real gate. Eligibility
 * rows are NOT a TeacherScope source (classIds reads subjects,
 * led sections, and overrides only), so isolation still holds —
 * asserted explicitly for B before the swap.
 */
class OwnerAllotmentRound17Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R17',
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

    /** Fresh class + fresh subject taught by $teacher; returns [$class, $subject]. */
    protected function subjectFixture(User $teacher, string $suffix): array
    {
        $class = MyClass::create(['name' => 'ProbeSubjectClass R17' . $suffix]);
        $subject = Subject::create([
            'name' => 'Probe Flip Subject R17' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'slug' => 'probe-flip-r17' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $subject];
    }

    /** Record eligibility for both teachers, then reassign via the real admin path. */
    protected function reassignSubject(Subject $subject, MyClass $class, User $newTeacher): void
    {
        TeacherEligibility::firstOrCreate([
            'teacher_id' => $newTeacher->id,
            'subject_name' => $subject->name,
        ]);
        $this->actingAs($this->admin())
            ->put(route('subjects.update', $subject->id), [
                'name' => $subject->name,
                'my_class_id' => $class->id,
                'teacher_id' => Qs::hash($newTeacher->id),
                'slug' => $subject->slug,
                'periods_per_week' => 6,
            ])
            ->assertRedirect();
        $this->assertEquals($newTeacher->id, (int) $subject->fresh()->teacher_id);
    }

    public function test_subject_reassignment_flips_class_ids_immediately()
    {
        $a = $this->makeTeacher('PROBE-R17-A', 'probe-r17-a@example.com');
        $b = $this->makeTeacher('PROBE-R17-B', 'probe-r17-b@example.com');
        [$class, $subject] = $this->subjectFixture($a, 'A');
        TeacherEligibility::firstOrCreate(['teacher_id' => $a->id, 'subject_name' => $subject->name]);

        // Isolation: A qualifies ONLY via the direct subject; B for
        // nothing (eligibility rows are not a scope source).
        $this->assertSame([$class->id], TeacherScope::classIds($a));
        $this->assertSame([], TeacherScope::classIds($b));

        $this->reassignSubject($subject, $class, $b);

        // No reset step, no re-login: the very next reads flip.
        $this->assertSame([], TeacherScope::classIds($a));
        $this->assertSame([$class->id], TeacherScope::classIds($b));
    }

    public function test_subject_reassignment_flips_students_list_gate()
    {
        $a = $this->makeTeacher('PROBE-R17-C', 'probe-r17-c@example.com');
        $b = $this->makeTeacher('PROBE-R17-D', 'probe-r17-d@example.com');
        [$class, $subject] = $this->subjectFixture($a, 'B');
        TeacherEligibility::firstOrCreate(['teacher_id' => $a->id, 'subject_name' => $subject->name]);

        // Gate before the swap: subject teacher admitted, stranger refused.
        $this->actingAs($a)->get(route('students.list', [$class->id]))->assertOk();
        $this->actingAs($b)->get(route('students.list', [$class->id]))->assertForbidden();

        $this->reassignSubject($subject, $class, $b);

        // Gate after the swap, same process, same assertions: flipped.
        $this->actingAs($a)->get(route('students.list', [$class->id]))->assertForbidden();
        $this->actingAs($b)->get(route('students.list', [$class->id]))->assertOk();
    }

    public function test_subject_reassignment_back_restores_scope()
    {
        $a = $this->makeTeacher('PROBE-R17-E', 'probe-r17-e@example.com');
        $b = $this->makeTeacher('PROBE-R17-F', 'probe-r17-f@example.com');
        [$class, $subject] = $this->subjectFixture($a, 'C');
        TeacherEligibility::firstOrCreate(['teacher_id' => $a->id, 'subject_name' => $subject->name]);

        $this->reassignSubject($subject, $class, $b);
        $this->assertSame([], TeacherScope::classIds($a));
        $this->assertSame([$class->id], TeacherScope::classIds($b));

        // Swap back through the same real path: scope returns, not stuck.
        $this->reassignSubject($subject, $class, $a);
        $this->assertSame([$class->id], TeacherScope::classIds($a));
        $this->assertSame([], TeacherScope::classIds($b));
        $this->actingAs($a)->get(route('students.list', [$class->id]))->assertOk();
        $this->actingAs($b)->get(route('students.list', [$class->id]))->assertForbidden();
    }
}
