<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 32 — DIRECT-TEACHER + SECTION-LEADER LIVENESS.
 *
 * CODE READING FIRST (the sibling of round 31's F-R31-1 hole):
 *   - subjects.update (SubjectController::update + SubjectUpdate
 *     FormRequest): teacher_id must exist with user_type='teacher'
 *     — NO status check. The controller's only guard is
 *     eligibilityRefusal, which fires ONLY when the eligibility
 *     pool for the subject name is non-empty. A vacant subject
 *     (teacher_id null, no eligibility rows, no same-name
 *     assignment) has an EMPTY pool, so the assignment is free —
 *     a suspended/terminated teacher is STORED as direct teacher
 *     through the plain Subjects UI. subjects.store shares the
 *     same guard shape.
 *   - sections.update (SectionController::update + SectionUpdate
 *     FormRequest): same story — exists + user_type='teacher'
 *     only, and updateSection() applies no liveness check at all.
 *     A suspended/terminated teacher is STORED as section leader
 *     through the plain Sections UI. sections.store shares it.
 *   - The rest of the app already treats such rows as dead:
 *     sections-index readiness counts a resolution ONLY for a
 *     live teacher; the index blade flags a non-live leader
 *     "(not an active teacher)"; Staffing::eligibleTeacherIds
 *     excludes suspended/terminated. But teacherIdFor is
 *     DELIBERATELY untouched, so a stored dead DIRECT teacher
 *     still resolves — and under F-R24-1 it FREEZES marks entry
 *     for that section-subject (no live teacher can ever match
 *     teacherIdFor), while TeacherScope::classIds still grants a
 *     stored dead leader her led class in code (she cannot log
 *     in — EnsureActiveAccount — but the scope row stands).
 *
 * EXPECTATION (the fix, F-R32-1): both assignment paths refuse
 * non-live teachers (status set and not 'active'; null legacy
 * counts live — the same standard as Staffing/LeaveCover and
 * F-R31-1), with back() + flash_danger naming the teacher, and
 * the stored value UNCHANGED. subjects.store and sections.store
 * get the same guard (same controller, same family).
 * Pre-existing dead rows (teacher suspended AFTER assignment)
 * are NOT auto-cleared — suspension is temporary; the admin
 * clears them by re-assigning a live teacher. Tests 6–8 document
 * that residual shape, exactly like round 31 tests 5–6.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 * Route params are RAW ids (resource params are {subject} /
 * {section}, not hash-bound {id}); teacher_id in the body is
 * Qs-hashed (the FormRequests decode it).
 */
class OwnerAllotmentRound32Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email, ?string $status = null): User
    {
        $attrs = [
            'name' => 'Probe Teacher R32',
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
     * Returns [class, section, subject, liveLeader]. The subject
     * starts VACANT (teacher_id null) so its eligibility pool is
     * empty — the hole shape. The section is led by a live teacher.
     */
    protected function fixture(string $suffix): array
    {
        $leader = $this->makeTeacher('PROBE-R32-L' . $suffix, 'probe-r32-l' . strtolower($suffix) . '@example.com');
        $k = MyClass::create(['name' => 'ProbeR32Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R32 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $leader->id]);
        $subject = Subject::create([
            'name' => 'Probe Subject R32' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => null,
            'slug' => 'probe-r32-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$k, $s, $subject, $leader];
    }

    protected function subjectUpdate(Subject $subject, array $overrides = [])
    {
        $payload = array_merge([
            'name' => $subject->name,
            'my_class_id' => $subject->my_class_id,
            'periods_per_week' => 6,
        ], $overrides);

        return $this->actingAs($this->admin())
            ->put(route('subjects.update', $subject->id), $payload);
    }

    protected function sectionUpdate(Section $section, array $overrides = [])
    {
        $payload = array_merge(['name' => $section->name], $overrides);

        return $this->actingAs($this->admin())
            ->put(route('sections.update', $section->id), $payload);
    }

    public function test_suspended_direct_teacher_is_refused_on_vacant_subject(): void
    {
        [$k, $s, $subject, $leader] = $this->fixture('SUSP');
        $dead = $this->makeTeacher('PROBE-R32-SUSP', 'probe-r32-susp@example.com', 'suspended');
        $this->assertSame([], Staffing::eligibleTeacherIds($subject->name), 'Pool must be empty for the hole shape.');

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($dead->id)]);
        $response->assertSessionHas('flash_danger');
        $this->assertStringContainsString('not an active teacher', (string) session('flash_danger'));
        $this->assertNull(Subject::find($subject->id)->teacher_id, 'Dead direct teacher must not be stored.');
    }

    public function test_terminated_direct_teacher_is_refused_on_vacant_subject(): void
    {
        [$k, $s, $subject, $leader] = $this->fixture('TERM');
        $dead = $this->makeTeacher('PROBE-R32-TERM', 'probe-r32-term@example.com', 'terminated');

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($dead->id)]);
        $response->assertSessionHas('flash_danger');
        $this->assertNull(Subject::find($subject->id)->teacher_id, 'Dead direct teacher must not be stored.');
    }

    public function test_renamed_subject_with_suspended_teacher_is_refused(): void
    {
        [$k, $s, $subject, $leader] = $this->fixture('RENAME');
        $dead = $this->makeTeacher('PROBE-R32-RENS', 'probe-r32-rens@example.com', 'suspended');

        // Rename to a brand-new name AND assign the dead teacher in
        // one save: the pool for the new name is empty, so the old
        // code stored her. The fix refuses before any write.
        $response = $this->subjectUpdate($subject, [
            'name' => 'Probe Subject R32 Fresh',
            'teacher_id' => Qs::hash($dead->id),
        ]);
        $response->assertSessionHas('flash_danger');
        $after = Subject::find($subject->id);
        $this->assertNull($after->teacher_id);
        $this->assertSame($subject->name, $after->name, 'Refusal must leave the whole row untouched, not just the teacher.');
    }

    public function test_live_direct_teacher_stores_normally(): void
    {
        [$k, $s, $subject, $leader] = $this->fixture('LIVE');
        $live = $this->makeTeacher('PROBE-R32-LIVE', 'probe-r32-live@example.com');

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($live->id)]);
        $response->assertRedirect(route('subjects.index'));
        $this->assertSame($live->id, Subject::find($subject->id)->teacher_id);
    }

    public function test_subject_with_live_direct_teacher_still_refuses_suspended(): void
    {
        // Nuance, not a second hole: when the subject already has a
        // live direct teacher, the eligibility pool is non-empty
        // and the OLD code already refused a suspended teacher —
        // via eligibility, not liveness. Post-fix the refusal must
        // still hold (now via the liveness leg); only the message
        // may change, so this test asserts outcome, not wording.
        [$k, $s, $subject, $leader] = $this->fixture('MASK');
        $subject->update(['teacher_id' => $leader->id]);
        $dead = $this->makeTeacher('PROBE-R32-MASK', 'probe-r32-mask@example.com', 'suspended');
        $this->assertNotEmpty(Staffing::eligibleTeacherIds($subject->name), 'Pool must be non-empty for the masking shape.');

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($dead->id)]);
        $response->assertSessionHas('flash_danger');
        $this->assertSame($leader->id, Subject::find($subject->id)->teacher_id);
    }

    public function test_suspended_direct_teacher_create_is_refused(): void
    {
        // subjects.store shares the update guard family.
        $dead = $this->makeTeacher('PROBE-R32-CRT', 'probe-r32-crt@example.com', 'suspended');
        $k = MyClass::create(['name' => 'ProbeR32Class CRT', 'class_type_id' => 4]);

        $response = $this->actingAs($this->admin())->post(route('subjects.store'), [
            'name' => 'Probe Subject R32 CRT',
            'my_class_id' => $k->id,
            'teacher_id' => Qs::hash($dead->id),
            'periods_per_week' => 6,
        ]);
        $response->assertSessionHas('flash_danger');
        $this->assertFalse(Subject::where('name', 'Probe Subject R32 CRT')->exists(), 'Dead-teacher subject must not be created.');
    }

    public function test_planted_dead_direct_teacher_resolves_and_flags_unresolved(): void
    {
        // Residual (NOT fixed): a teacher suspended AFTER a live
        // assignment still resolves via teacherIdFor, and the
        // sections index flags the subject unresolved — the same
        // residual shape as round 31 tests 5–6.
        [$k, $s, $subject, $leader] = $this->fixture('PLANT');
        $dead = $this->makeTeacher('PROBE-R32-PLANT', 'probe-r32-plant@example.com');
        $subject->update(['teacher_id' => $dead->id]);
        $dead->update(['status' => 'suspended']);

        $this->assertSame($dead->id, SectionTeacher::teacherIdFor($s->id, Subject::find($subject->id)));

        $response = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => $k->id]));
        $response->assertOk();
        $unresolved = $response->viewData('unresolvedSubjects');
        $this->assertContains($subject->name, $unresolved[$s->id] ?? [], 'Dead resolution must flag the subject unresolved.');
    }

    public function test_planted_dead_direct_teacher_freezes_marks_entry(): void
    {
        // Damage statement: with a dead direct teacher, NO live
        // teacher matches teacherIdFor, so marks entry 403s even
        // for the section's own live leader — exactly F-R24-1.
        [$k, $s, $subject, $leader] = $this->fixture('FREEZE');
        $dead = $this->makeTeacher('PROBE-R32-FREEZE', 'probe-r32-freeze@example.com');
        $subject->update(['teacher_id' => $dead->id]);
        $dead->update(['status' => 'suspended']);

        $this->actingAs($leader)->get(route('marks.manage', [1, $k->id, $s->id, $subject->id]))->assertForbidden();
    }

    public function test_suspended_section_leader_is_refused(): void
    {
        [$k, $s, $subject, $leader] = $this->fixture('SLEAD');
        $dead = $this->makeTeacher('PROBE-R32-SLEAD', 'probe-r32-slead@example.com', 'suspended');

        $response = $this->sectionUpdate($s, ['teacher_id' => Qs::hash($dead->id)]);
        $response->assertSessionHas('flash_danger');
        $this->assertStringContainsString('not an active teacher', (string) session('flash_danger'));
        $this->assertSame($leader->id, Section::find($s->id)->teacher_id, 'Dead leader must not be stored.');
    }

    public function test_terminated_section_leader_is_refused(): void
    {
        [$k, $s, $subject, $leader] = $this->fixture('TLEAD');
        $dead = $this->makeTeacher('PROBE-R32-TLEAD', 'probe-r32-tlead@example.com', 'terminated');

        $response = $this->sectionUpdate($s, ['teacher_id' => Qs::hash($dead->id)]);
        $response->assertSessionHas('flash_danger');
        $this->assertSame($leader->id, Section::find($s->id)->teacher_id);
    }

    public function test_live_section_leader_stores_normally(): void
    {
        [$k, $s, $subject, $leader] = $this->fixture('LLEAD');
        $live = $this->makeTeacher('PROBE-R32-LLEAD', 'probe-r32-llead@example.com');

        $response = $this->sectionUpdate($s, ['teacher_id' => Qs::hash($live->id)]);
        $response->assertRedirect(route('sections.index'));
        $this->assertSame($live->id, Section::find($s->id)->teacher_id);
    }

    public function test_suspended_section_leader_create_is_refused(): void
    {
        // sections.store shares the update guard family.
        $dead = $this->makeTeacher('PROBE-R32-CLEAD', 'probe-r32-clead@example.com', 'suspended');
        $k = MyClass::create(['name' => 'ProbeR32Class CLEAD', 'class_type_id' => 4]);

        $response = $this->actingAs($this->admin())->post(route('sections.store'), [
            'name' => 'R32 CLEAD',
            'my_class_id' => $k->id,
            'teacher_id' => Qs::hash($dead->id),
        ]);
        $response->assertSessionHas('flash_danger');
        $this->assertFalse(Section::where('name', 'R32 CLEAD')->exists(), 'Dead-leader section must not be created.');
    }

    public function test_planted_dead_leader_is_flagged_and_still_scoped(): void
    {
        // Residual: a planted dead leader still shows on the
        // sections index WITH the "(not an active teacher)" flag,
        // and TeacherScope::classIds still grants her the led
        // class in code (she cannot log in — EnsureActiveAccount
        // — but the scope row stands until re-assigned).
        [$k, $s, $subject, $leader] = $this->fixture('PLEAD');
        $dead = $this->makeTeacher('PROBE-R32-PLEAD', 'probe-r32-plead@example.com');
        $s->update(['teacher_id' => $dead->id]);
        $dead->update(['status' => 'suspended']);

        $response = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => $k->id]));
        $response->assertOk();
        $response->assertSee($dead->name);
        $response->assertSee('(not an active teacher)');
        $this->assertContains($k->id, TeacherScope::classIds($dead->fresh()));
    }

    public function test_matrix_f31_1_still_refuses_suspended_override(): void
    {
        // Re-confirm the round-31 guard still holds alongside the
        // new guards.
        [$k, $s, $subject, $leader] = $this->fixture('MATRIX');
        $dead = $this->makeTeacher('PROBE-R32-MATRIX', 'probe-r32-matrix@example.com', 'suspended');

        $response = $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($s->id)), ['teachers' => [$subject->id => Qs::hash($dead->id)]]);
        $response->assertSessionHas('flash_success');
        $this->assertFalse(DB::table('section_subject_teachers')
            ->where('section_id', $s->id)->where('subject_id', $subject->id)->exists());
    }
}
