<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use App\Support\Staffing;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 25 — DESTROY GUARD (single endpoint) + ELIGIBILITY
 * persistence around override removal.
 *
 * BRIEF-PREMISE CORRECTION, stated openly (round-23/24 style):
 * the brief says single-endpoint destroy was "parent-fixed, never
 * test-locked through the endpoint by F". In fact the parent's
 * OwnerTeacherDestroyOverrideTest ALREADY locks override-only,
 * led-only and unattached shapes through the real DELETE
 * users.destroy endpoint — but it asserts only a redirect, never
 * the refusal message, and it never covers the DUAL-attachment
 * shape (leads a section AND holds an override). So this round
 * locks what is genuinely new:
 *
 * - the dual-attached teacher, refused via the real endpoint
 *   with the EXACT attachments message (not msg.del_teacher:
 *   the fixture teacher directly teaches no subject, so the
 *   userTeachesSubject guard cannot fire first — the
 *   attachments guard is the one under test);
 * - the exact attachments message for the override-only and
 *   led-only shapes (tightening the parent test's redirect-only
 *   assertions);
 * - the unattached positive control.
 *
 * ELIGIBILITY (observation, not a fix): the matrix save touches
 * ONLY section_subject_teachers (+ audit) — it never reads or
 * writes teacher_eligibilities (SectionController::updateTeachers
 * read first). So an eligibility row is a qualification, not an
 * assignment, and must SURVIVE an override removal — and the
 * removed teacher must remain re-assignable through the normal
 * eligibility-page flow (subjects.update refuses only teachers
 * OUTSIDE Staffing::eligibleTeacherIds). Test 5 proves both:
 * the row survives, the pool still lists O, and a real PUT
 * subjects.update (raw subject id in the route, hashed teacher
 * id in the body — the round-17 contract) re-assigns O as the
 * subject's direct teacher.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 */
class OwnerAllotmentRound25Test extends TestCase
{
    use DatabaseTransactions;

    private const ATTACH_MSG = 'This teacher still leads sections or holds section subject overrides. Remove those assignments first, then delete.';

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R25',
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

    /** Fresh class + section (led by $leader, null = no leader) + subject (direct $direct, null = teacher-less). */
    protected function fixture(?User $leader, ?User $direct, string $suffix, string $name): array
    {
        $class = MyClass::create(['name' => 'ProbeR25Class ' . $suffix]);
        $section = Section::create([
            'name' => 'R25 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $leader ? $leader->id : null,
        ]);
        $subject = Subject::create([
            'name' => $name,
            'my_class_id' => $class->id,
            'teacher_id' => $direct ? $direct->id : null,
            'slug' => 'probe-r25-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $section, $subject];
    }

    protected function giveOverride(User $override, Section $section, Subject $subject): void
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $override->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function overrideExists(Section $section, Subject $subject, User $teacher): bool
    {
        return DB::table('section_subject_teachers')
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->where('teacher_id', $teacher->id)->exists();
    }

    protected function attemptDestroy(User $teacher)
    {
        return $this->actingAs($this->admin())
            ->delete(route('users.destroy', Qs::hash($teacher->id)));
    }

    public function test_dual_attached_teacher_destroy_refused_with_exact_message()
    {
        $o = $this->makeTeacher('PROBE-R25-O', 'probe-r25-o@example.com');
        $d = $this->makeTeacher('PROBE-R25-D', 'probe-r25-d@example.com');
        // O leads the section AND holds the override; D directly
        // teaches the subject, so O directly teaches nothing —
        // the userTeachesSubject guard cannot fire first.
        [, $section, $subject] = $this->fixture($o, $d, 'A', 'Probe Destroy Subject R25A');
        $this->giveOverride($o, $section, $subject);
        $this->assertTrue($this->overrideExists($section, $subject, $o));

        $this->attemptDestroy($o)
            ->assertRedirect()
            ->assertSessionHas('pop_error', self::ATTACH_MSG);

        $this->assertDatabaseHas('users', ['id' => $o->id]);
        $this->assertSame($o->id, Section::find($section->id)->teacher_id);
        $this->assertTrue($this->overrideExists($section, $subject, $o));
    }

    public function test_override_only_teacher_destroy_refused_with_exact_message()
    {
        $o = $this->makeTeacher('PROBE-R25-O2', 'probe-r25-o2@example.com');
        $d = $this->makeTeacher('PROBE-R25-D2', 'probe-r25-d2@example.com');
        [, $section, $subject] = $this->fixture($d, $d, 'B', 'Probe Destroy Subject R25B');
        $this->giveOverride($o, $section, $subject);

        $this->attemptDestroy($o)
            ->assertRedirect()
            ->assertSessionHas('pop_error', self::ATTACH_MSG);

        $this->assertDatabaseHas('users', ['id' => $o->id]);
        $this->assertTrue($this->overrideExists($section, $subject, $o));
    }

    public function test_section_leading_only_teacher_destroy_refused_with_exact_message()
    {
        $o = $this->makeTeacher('PROBE-R25-O3', 'probe-r25-o3@example.com');
        $d = $this->makeTeacher('PROBE-R25-D3', 'probe-r25-d3@example.com');
        [, $section,] = $this->fixture($o, $d, 'C', 'Probe Destroy Subject R25C');

        $this->attemptDestroy($o)
            ->assertRedirect()
            ->assertSessionHas('pop_error', self::ATTACH_MSG);

        $this->assertDatabaseHas('users', ['id' => $o->id]);
        $this->assertSame($o->id, Section::find($section->id)->teacher_id);
    }

    public function test_unattached_teacher_can_still_be_deleted()
    {
        $o = $this->makeTeacher('PROBE-R25-O4', 'probe-r25-o4@example.com');

        $this->attemptDestroy($o)->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $o->id]);
    }

    public function test_eligibility_survives_override_removal_and_reassignment_works()
    {
        $o = $this->makeTeacher('PROBE-R25-O5', 'probe-r25-o5@example.com');
        $d = $this->makeTeacher('PROBE-R25-D5', 'probe-r25-d5@example.com');
        // Teacher-less subject, taught ONLY via O's override.
        [$class, $section, $subject] = $this->fixture($d, null, 'E', 'Probe Eligibility Subject R25E');
        TeacherEligibility::create(['teacher_id' => $o->id, 'subject_name' => $subject->name]);
        $this->giveOverride($o, $section, $subject);

        // BEFORE: the pool lists O, driven by the eligibility row
        // alone (the subject has no direct teacher, so the
        // fromAssignments leg contributes nothing).
        $this->assertTrue(TeacherEligibility::where('teacher_id', $o->id)->where('subject_name', $subject->name)->exists());
        $this->assertContains($o->id, Staffing::eligibleTeacherIds($subject->name));

        // Remove the override through the real matrix save.
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertFalse(DB::table('section_subject_teachers')->where('section_id', $section->id)->where('subject_id', $subject->id)->exists());

        // AFTER: the eligibility row survives (the matrix save
        // never touches teacher_eligibilities) and the pool
        // still lists O — so O can be re-assigned through the
        // normal flow: real PUT subjects.update, raw subject id
        // in the route, hashed teacher id in the body.
        $this->assertTrue(TeacherEligibility::where('teacher_id', $o->id)->where('subject_name', $subject->name)->exists(),
            'Eligibility row must survive the override removal.');
        $this->assertContains($o->id, Staffing::eligibleTeacherIds($subject->name),
            'Pool must still list O after removal, or re-assignment would be refused.');

        $this->actingAs($this->admin())
            ->put(route('subjects.update', $subject->id), [
                'name' => $subject->name,
                'my_class_id' => $class->id,
                'teacher_id' => Qs::hash($o->id),
                'periods_per_week' => 6,
            ])
            ->assertRedirect(route('subjects.index'));
        $this->assertSame($o->id, Subject::find($subject->id)->teacher_id);
    }
}
