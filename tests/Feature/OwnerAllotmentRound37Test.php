<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 37 — ELIGIBILITY WORKFLOW CHAIN (P15).
 *
 * CODE READING FIRST (the eligibility family, rounds 25 + 32):
 *   - Staffing::eligibleTeacherIds($name) = eligibility rows for
 *     that name UNION direct assignments of same-name subjects,
 *     filtered to LIVE teachers only (status null or 'active').
 *   - SubjectController::update runs livenessRefusal FIRST, then
 *     eligibilityRefusal. eligibilityRefusal fires ONLY when the
 *     pool is non-empty; a vacant subject (teacher_id null, no
 *     eligibility rows, no same-name assignment) has an EMPTY
 *     pool, so the assignment is free (round-32 boundary).
 *   - SectionController::updateTeachers (matrix save) touches
 *     ONLY section_subject_teachers — never teacher_eligibilities
 *     (round-25 boundary): eligibility survives override removal.
 *   - Marks entry is effective-teacher scoped (F-R24-1): the
 *     marks leg below proves the re-assignment moves the real
 *     gate, not just the stored teacher_id.
 *
 * CHAIN SHAPE: one continuous chain on ONE subject (test 1) —
 * non-eligible assign refused (a) -> eligibility row added ->
 * same assign succeeds + scope + marks move (b) -> override
 * given to a third teacher -> override removed via blank matrix
 * save -> eligibility survives -> third teacher re-assigned
 * through the normal subjects.update path with no
 * re-qualification (e). Tests 2–4 are the shapes that would
 * disturb that chain's state if folded in: vacant subject (c),
 * and suspended / terminated teachers who DO hold an eligibility
 * row, where the LIVENESS refusal must win over eligibility (d).
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 * Subject names are unique per test so seeded same-name rows
 * can never contaminate the pool. Route params are RAW ids
 * (resource params are {subject} / {section}, not hash-bound
 * {id}); teacher_id in bodies is Qs-hashed (the FormRequests
 * decode it); matrix-save section ids are Qs-hashed (P9/P19).
 */
class OwnerAllotmentRound37Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email, string $name, ?string $status = null): User
    {
        $attrs = [
            'name' => $name,
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
     * Class (type 4 Primary — fresh classes without a type 500
     * the marks manage view, the round-26 trap) + section led by
     * $leader + subject with direct teacher $direct (null =
     * vacant) + one roster student + one legacy mark row, so
     * marks.manage renders a grid instead of taking the
     * noStudentRecord redirect (round-35 trap).
     */
    protected function fixture(?User $leader, ?User $direct, string $suffix, string $name): array
    {
        $k = MyClass::create(['name' => 'ProbeR37Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create([
            'name' => 'R37 S ' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $leader ? $leader->id : null,
        ]);
        $subject = Subject::create([
            'name' => $name,
            'my_class_id' => $k->id,
            'teacher_id' => $direct ? $direct->id : null,
            'slug' => 'probe-r37-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        $student = User::create([
            'name' => 'Probe Student R37 ' . $suffix,
            'username' => 'PROBE-R37-STU-' . $suffix,
            'email' => 'probe-r37-stu-' . strtolower($suffix) . '@example.com',
            'password' => 'secret',
            'code' => 'PROBE-R37-STU-' . $suffix,
            'user_type' => 'student',
        ]);
        \App\Models\StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $student->id,
            'my_class_id' => $k->id,
            'section_id' => $s->id,
            'adm_no' => 'R37-CHAIN-' . $suffix,
            'grad' => 0,
        ]);
        $mark = \App\Models\Mark::create([
            'exam_id' => 1,
            'my_class_id' => $k->id,
            'section_id' => $s->id,
            'subject_id' => $subject->id,
            'student_id' => $student->id,
            'year' => Qs::getCurrentSession(),
        ]);

        return [$k, $s, $subject, $mark];
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

    protected function matrixSave(Section $section, array $teachers)
    {
        return $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => $teachers]);
    }

    protected function overrideExists(Section $section, Subject $subject, User $teacher): bool
    {
        return DB::table('section_subject_teachers')
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->where('teacher_id', $teacher->id)->exists();
    }

    public function test_eligibility_chain_refusal_then_assign_then_override_removal_then_reassign(): void
    {
        $leader = $this->makeTeacher('PROBE-R37-L', 'probe-r37-l@example.com', 'Probe Teacher R37 Leader');
        $d = $this->makeTeacher('PROBE-R37-D', 'probe-r37-d@example.com', 'Probe Teacher R37 Direct');
        $n = $this->makeTeacher('PROBE-R37-N', 'probe-r37-n@example.com', 'Probe Teacher R37 New');
        $o = $this->makeTeacher('PROBE-R37-O', 'probe-r37-o@example.com', 'Probe Teacher R37 Override');
        [$k, $s, $subject, $mark] = $this->fixture($leader, $d, 'CHAIN', 'Probe Eligibility Subject R37CHAIN');
        $combo = [1, $k->id, $s->id, $subject->id];

        // (a) Pool is exactly the direct teacher; N is not in it.
        $this->assertSame([$d->id], Staffing::eligibleTeacherIds($subject->name));
        $this->assertNotContains($n->id, Staffing::eligibleTeacherIds($subject->name));

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($n->id)]);
        $response->assertSessionHas('flash_danger');
        $flash = (string) session('flash_danger');
        $this->assertStringContainsString('is not eligible to teach', $flash);
        $this->assertStringContainsString($subject->name, $flash);
        $this->assertStringContainsString($n->name, $flash);
        $this->assertStringNotContainsString('not an active teacher', $flash, 'Refusal must be the eligibility leg, not liveness.');
        $this->assertSame($d->id, Subject::find($subject->id)->teacher_id, 'Refused assign must leave the stored teacher untouched.');

        // (b) Eligibility row added -> the SAME assign succeeds.
        TeacherEligibility::create(['teacher_id' => $n->id, 'subject_name' => $subject->name]);
        $this->assertContains($n->id, Staffing::eligibleTeacherIds($subject->name));

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($n->id)]);
        $response->assertRedirect(route('subjects.index'));
        $this->assertSame($n->id, Subject::find($subject->id)->teacher_id);
        $this->assertSame($n->id, SectionTeacher::teacherIdFor($s->id, Subject::find($subject->id)));
        $this->assertContains($k->id, TeacherScope::classIds($n->fresh()), 'Scope must gain the class same-session.');
        $this->actingAs($n)->get(route('marks.manage', $combo))->assertOk();
        $this->actingAs($d)->get(route('marks.manage', $combo))->assertForbidden();
        $this->actingAs($n)->put(route('marks.update', $combo), ['t1_' . $mark->id => '10'])->assertOk();
        $this->assertSame(10, (int) $mark->fresh()->t1, 'Marks save must actually land for the new effective teacher.');

        // (e) O gets an override via the real matrix save...
        TeacherEligibility::create(['teacher_id' => $o->id, 'subject_name' => $subject->name]);
        $this->matrixSave($s, [$subject->id => Qs::hash($o->id)])->assertRedirect(route('sections.index'));
        $this->assertTrue($this->overrideExists($s, $subject, $o));
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($s->id, Subject::find($subject->id)));

        // ...then the override is removed via a BLANK matrix save.
        $this->matrixSave($s, [$subject->id => ''])->assertRedirect(route('sections.index'));
        $this->assertFalse($this->overrideExists($s, $subject, $o));
        $this->assertSame($n->id, SectionTeacher::teacherIdFor($s->id, Subject::find($subject->id)), 'Resolution must fall back to the direct teacher.');

        // Eligibility survives the removal, so O re-assigns with
        // no re-qualification through the normal path.
        $this->assertTrue(TeacherEligibility::where('teacher_id', $o->id)->where('subject_name', $subject->name)->exists(),
            'Eligibility row must survive the override removal.');
        $this->assertContains($o->id, Staffing::eligibleTeacherIds($subject->name));
        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($o->id)]);
        $response->assertRedirect(route('subjects.index'));
        $this->assertSame($o->id, Subject::find($subject->id)->teacher_id);
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($s->id, Subject::find($subject->id)));
        $this->actingAs($o)->get(route('marks.manage', $combo))->assertOk();
        $this->actingAs($n)->get(route('marks.manage', $combo))->assertForbidden();
    }

    public function test_vacant_subject_with_empty_pool_assigns_any_live_teacher(): void
    {
        $leader = $this->makeTeacher('PROBE-R37-LV', 'probe-r37-lv@example.com', 'Probe Teacher R37 VacLeader');
        $v = $this->makeTeacher('PROBE-R37-V', 'probe-r37-v@example.com', 'Probe Teacher R37 Vacant');
        [$k, $s, $subject] = $this->fixture($leader, null, 'VAC', 'Probe Eligibility Subject R37VAC');

        $this->assertSame([], Staffing::eligibleTeacherIds($subject->name), 'Vacant subject must have an empty pool.');
        $this->assertNull(Subject::find($subject->id)->teacher_id);

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($v->id)]);
        $response->assertRedirect(route('subjects.index'));
        $this->assertSame($v->id, Subject::find($subject->id)->teacher_id);
        $this->assertSame($v->id, SectionTeacher::teacherIdFor($s->id, Subject::find($subject->id)));
        $this->assertContains($k->id, TeacherScope::classIds($v->fresh()));
    }

    public function test_suspended_teacher_with_eligibility_is_refused_by_liveness_first(): void
    {
        $leader = $this->makeTeacher('PROBE-R37-LS', 'probe-r37-ls@example.com', 'Probe Teacher R37 SuspLeader');
        $d = $this->makeTeacher('PROBE-R37-DS', 'probe-r37-ds@example.com', 'Probe Teacher R37 SuspDirect');
        $dead = $this->makeTeacher('PROBE-R37-SUSP', 'probe-r37-susp@example.com', 'Probe Teacher R37 Susp', 'suspended');
        [$k, $s, $subject] = $this->fixture($leader, $d, 'SUSP', 'Probe Eligibility Subject R37SUSP');
        TeacherEligibility::create(['teacher_id' => $dead->id, 'subject_name' => $subject->name]);

        // The pool filters live-only, so the suspended holder is
        // NOT in it — but the refusal must still be the liveness
        // leg (checked first), not the eligibility leg.
        $this->assertNotContains($dead->id, Staffing::eligibleTeacherIds($subject->name));
        $this->assertContains($d->id, Staffing::eligibleTeacherIds($subject->name));

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($dead->id)]);
        $response->assertSessionHas('flash_danger');
        $flash = (string) session('flash_danger');
        $this->assertStringContainsString('not an active teacher', $flash);
        $this->assertStringContainsString($dead->name, $flash);
        $this->assertStringNotContainsString('not eligible', $flash, 'Liveness is checked before eligibility — the flash must say so.');
        $this->assertSame($d->id, Subject::find($subject->id)->teacher_id, 'Refused assign must leave the stored teacher untouched.');
    }

    public function test_terminated_teacher_with_eligibility_is_refused_by_liveness_first(): void
    {
        $leader = $this->makeTeacher('PROBE-R37-LT', 'probe-r37-lt@example.com', 'Probe Teacher R37 TermLeader');
        $d = $this->makeTeacher('PROBE-R37-DT', 'probe-r37-dt@example.com', 'Probe Teacher R37 TermDirect');
        $dead = $this->makeTeacher('PROBE-R37-TERM', 'probe-r37-term@example.com', 'Probe Teacher R37 Term', 'terminated');
        [$k, $s, $subject] = $this->fixture($leader, $d, 'TERM', 'Probe Eligibility Subject R37TERM');
        TeacherEligibility::create(['teacher_id' => $dead->id, 'subject_name' => $subject->name]);

        $this->assertNotContains($dead->id, Staffing::eligibleTeacherIds($subject->name));

        $response = $this->subjectUpdate($subject, ['teacher_id' => Qs::hash($dead->id)]);
        $response->assertSessionHas('flash_danger');
        $flash = (string) session('flash_danger');
        $this->assertStringContainsString('not an active teacher', $flash);
        $this->assertStringNotContainsString('not eligible', $flash);
        $this->assertSame($d->id, Subject::find($subject->id)->teacher_id);
    }
}
