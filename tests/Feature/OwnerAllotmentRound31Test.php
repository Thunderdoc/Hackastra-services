<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 31 — OVERRIDE + SUSPENDED/TERMINATED TEACHER.
 *
 * CODE READING FIRST (the gap):
 *   - SectionController::updateTeachers (matrix save) validates each
 *     posted teacher ONLY on user_type === 'teacher' (line ~220).
 *     It never checks liveness (status null/active). The matrix
 *     edit FORM likewise offers every teacher row
 *     (getUserByType('teacher'), no status filter) — so naming a
 *     suspended teacher needs no forgery, just the plain UI.
 *   - Every OTHER surface already treats a non-live teacher as
 *     dead: sections-index readiness counts a resolution ONLY when
 *     it points at a live teacher (round 3); the matrix edit page
 *     flags the effective teacher "No live teacher assigned";
 *     Staffing::eligibleTeacherIds excludes suspended/terminated.
 *   - teacherIdFor itself is DELIBERATELY untouched (it feeds
 *     validator/generation) — so a stored dead override still
 *     resolves, and under F-R24-1 (effective-teacher marks gate)
 *     it FREEZES the live direct teacher's marks entry for that
 *     section-subject until an admin manually clears the override.
 *
 * EXPECTATION (the fix, F-R31-1): the matrix save refuses
 * non-live teachers exactly like forged/non-teacher values —
 * skipped, named in the flash ("Ignored invalid entries for: …"),
 * no row stored. The save stays forgiving (one bad row must not
 * block the rest), matching the round-5 audit pattern.
 * Pre-existing dead rows (teacher suspended AFTER the override
 * was stored) are NOT cleared by this fix — suspension is
 * temporary, and auto-clearing would destroy data. Tests 5–6
 * document that residual shape: resolution + readiness flag +
 * marks freeze all still hold for a planted dead row, and the
 * admin clears it with a blank matrix save.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 * Each fixture class carries exactly ONE subject, so a posted
 * `teachers` array is a full-form save (unposted subjects are
 * deleted by the save — with one subject there is no cross-talk).
 */
class OwnerAllotmentRound31Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email, ?string $status = null): User
    {
        $attrs = [
            'name' => 'Probe Teacher R31',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ];
        if ($status !== null) {
            $attrs['status'] = $status;
        }

        return User::create($attrs);
    }

    /**
     * Returns [class, section, subject, directTeacher].
     * The subject's direct teacher is always a LIVE teacher.
     */
    protected function fixture(string $suffix): array
    {
        $d = $this->makeTeacher('PROBE-R31-D' . $suffix, 'probe-r31-d' . strtolower($suffix) . '@example.com');
        $k = MyClass::create(['name' => 'ProbeR31Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R31 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $subject = Subject::create([
            'name' => 'Probe Subject R31' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r31-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$k, $s, $subject, $d];
    }

    protected function matrixSave(Section $section, array $teachers)
    {
        return $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => $teachers]);
    }

    protected function overrideRow(int $sectionId, int $subjectId)
    {
        return DB::table('section_subject_teachers')
            ->where('section_id', $sectionId)->where('subject_id', $subjectId)->first();
    }

    public function test_suspended_teacher_override_is_refused_by_matrix_save(): void
    {
        [$k, $s, $subject, $d] = $this->fixture('SUSP');
        $dead = $this->makeTeacher('PROBE-R31-SUSP', 'probe-r31-susp@example.com', 'suspended');
        $this->assertSame('suspended', $dead->getAttribute('status'));
        $this->assertNull($this->overrideRow($s->id, $subject->id), 'Fixture must start with no override.');

        $response = $this->matrixSave($s, [$subject->id => Qs::hash($dead->id)]);
        $response->assertRedirect(route('sections.index'));
        $response->assertSessionHas('flash_success');
        $flash = session('flash_success');
        $this->assertStringContainsString('Ignored invalid entries for: ' . $subject->name, $flash,
            'The skipped suspended teacher must be named in the flash, like forged values.');
        $this->assertNull($this->overrideRow($s->id, $subject->id),
            'A suspended teacher must never gain an override row through the matrix save.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $subject),
            'Resolution must stay with the live direct teacher.');
    }

    public function test_terminated_teacher_override_is_refused_by_matrix_save(): void
    {
        [$k, $s, $subject, $d] = $this->fixture('TERM');
        $dead = $this->makeTeacher('PROBE-R31-TERM', 'probe-r31-term@example.com', 'terminated');
        $this->assertSame('terminated', $dead->getAttribute('status'));

        $response = $this->matrixSave($s, [$subject->id => Qs::hash($dead->id)]);
        $response->assertRedirect(route('sections.index'));
        $this->assertStringContainsString('Ignored invalid entries for: ' . $subject->name, session('flash_success'));
        $this->assertNull($this->overrideRow($s->id, $subject->id),
            'A terminated teacher must never gain an override row through the matrix save.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $subject));
    }

    public function test_live_teacher_override_stores_normally(): void
    {
        [$k, $s, $subject, $d] = $this->fixture('LIVE');
        $live = $this->makeTeacher('PROBE-R31-LIVE', 'probe-r31-live@example.com', 'active');

        $response = $this->matrixSave($s, [$subject->id => Qs::hash($live->id)]);
        $response->assertRedirect(route('sections.index'));
        $this->assertStringNotContainsString('Ignored invalid entries', session('flash_success'),
            'A live teacher must not be reported as skipped.');
        $row = $this->overrideRow($s->id, $subject->id);
        $this->assertNotNull($row, 'A live teacher override must store.');
        $this->assertSame($live->id, (int) $row->teacher_id);
        $this->assertSame($live->id, SectionTeacher::teacherIdFor($s->id, $subject));
    }

    public function test_non_teacher_value_still_skipped(): void
    {
        [$k, $s, $subject, $d] = $this->fixture('NONT');
        $admin = $this->admin();

        $response = $this->matrixSave($s, [$subject->id => Qs::hash($admin->id)]);
        $response->assertRedirect(route('sections.index'));
        $this->assertStringContainsString('Ignored invalid entries for: ' . $subject->name, session('flash_success'));
        $this->assertNull($this->overrideRow($s->id, $subject->id),
            'The pre-existing forged/non-teacher refusal must survive the liveness change.');
    }

    public function test_preexisting_dead_override_still_resolves_but_flags_unresolved(): void
    {
        // Residual shape, NOT fixed by the save-path guard: a teacher
        // suspended AFTER her override was stored keeps resolving
        // through teacherIdFor (deliberately untouched), while the
        // readiness surfaces already treat her as dead. This test
        // locks that tension so it is visible, not silent.
        [$k, $s, $subject, $d] = $this->fixture('DEAD');
        $dead = $this->makeTeacher('PROBE-R31-DEAD', 'probe-r31-dead@example.com', 'active');
        $this->matrixSave($s, [$subject->id => Qs::hash($dead->id)]);
        $this->assertNotNull($this->overrideRow($s->id, $subject->id), 'Live override must store first.');
        // Readiness asks "does ANY live teacher resolve this subject
        // here" (override live OR direct live) — with a live direct
        // teacher the subject stays resolved despite the dead
        // override, so the flag needs a subject with NO direct
        // teacher: then the dead override is the only resolution
        // and it must flag unresolved.
        $subject->update(['teacher_id' => null]);
        $dead->update(['status' => 'suspended']);
        $this->assertSame('suspended', $dead->fresh()->getAttribute('status'));

        $this->assertSame($dead->id, SectionTeacher::teacherIdFor($s->id, $subject),
            'teacherIdFor resolves the dead override (deliberately untouched).');

        $index = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => $k->id]));
        $index->assertOk();
        $unresolved = $index->viewData('unresolvedSubjects');
        $this->assertContains($subject->name, $unresolved[$s->id] ?? [],
            'Readiness must flag the subject unresolved once its override teacher is suspended.');

        $edit = $this->actingAs($this->admin())->get(route('sections.edit', Qs::hash($s->id)));
        $edit->assertOk();
        $edit->assertSee('No live teacher assigned',
            'The matrix edit page must flag the dead effective teacher.');
    }

    public function test_preexisting_dead_override_freezes_live_direct_teacher_marks_entry(): void
    {
        // The teeth of the residual: under F-R24-1 the marks gate
        // requires teacherIdFor == caller, so the live direct
        // teacher is refused for a section-subject whose override
        // points at a suspended teacher — until an admin clears
        // the override with a blank matrix save.
        [$k, $s, $subject, $d] = $this->fixture('FRZ');
        $dead = $this->makeTeacher('PROBE-R31-FRZ', 'probe-r31-frz@example.com', 'active');
        $this->matrixSave($s, [$subject->id => Qs::hash($dead->id)]);
        $dead->update(['status' => 'suspended']);

        $this->actingAs($d)->get(route('marks.manage', [1, $k->id, $s->id, $subject->id]))->assertForbidden();
        $this->actingAs($d)->put(route('marks.update', [1, $k->id, $s->id, $subject->id]), [])->assertForbidden();

        // And the admin escape hatch works: a blank save clears it.
        $this->matrixSave($s, [$subject->id => '']);
        $this->assertNull($this->overrideRow($s->id, $subject->id), 'Blank matrix save must clear the dead override.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $subject),
            'Resolution returns to the live direct teacher after clearing.');
    }
}
