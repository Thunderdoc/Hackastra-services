<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 36 — MATRIX-SAVE WORKFLOW CHAIN (P15).
 *
 * One continuous chain through the REAL routes, asserting the
 * handoff at every step. Nothing here re-litigates earlier rounds:
 *   - F-R31-1: matrix save skips non-live teachers, names them in
 *     the flash, never stores them; one bad row never blocks the
 *     rest (forgiving pattern, P7).
 *   - Round 5: a no-op re-save writes NO audit row; a real change
 *     writes exactly one sections.teachers_updated row.
 *   - Round 12: a matrix save touches ONLY its own section
 *     (cross-section isolation).
 *   - P9: matrix-save teacher values are HASHED ids; the section
 *     id in the route is hashed too.
 *   - P10: TeacherScope, dashboard scope count and My Account
 *     banner must agree at every state.
 *   - P3: teacherIdFor is deliberately untouched — a blank save
 *     is the clearing hatch that restores the direct teacher.
 *
 * Fixture: one class K with two sections (S, S2) and two subjects
 * (X, Y, both direct-taught by live teacher D). Every matrix save
 * posts the FULL form (unposted subjects are deleted by the save),
 * so each save names both X and Y.
 */
class OwnerAllotmentRound36Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email, ?string $status = null): User
    {
        $attrs = [
            'name' => 'Probe Teacher R36',
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

    protected function matrixSave(Section $section, array $teachers)
    {
        return $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => $teachers]);
    }

    protected function overrideRows(int $sectionId): array
    {
        return DB::table('section_subject_teachers')
            ->where('section_id', $sectionId)
            ->orderBy('subject_id')
            ->get(['section_id', 'subject_id', 'teacher_id'])
            ->map(fn ($r) => [(int) $r->section_id, (int) $r->subject_id, (int) $r->teacher_id])
            ->all();
    }

    protected function auditCount(int $sectionId): int
    {
        return DB::table('audit_logs')
            ->where('action', 'sections.teachers_updated')
            ->where('subject_id', $sectionId)
            ->count();
    }

    protected function dashboardScope(User $teacher): int
    {
        $resp = $this->actingAs($teacher)->get(route('dashboard'))->assertOk();

        return (int) $resp->viewData('scope_class_count');
    }

    protected function bannerValues(User $teacher): array
    {
        $resp = $this->actingAs($teacher)->get(route('my_account'))->assertOk();
        $out = [];
        foreach ($resp->viewData('banner_stats') ?? [] as $stat) {
            $out[$stat['label']] = (int) $stat['value'];
        }

        return $out;
    }

    public function test_matrix_save_chain(): void
    {
        $d = $this->makeTeacher('PROBE-R36-D', 'probe-r36-d@example.com');
        $o = $this->makeTeacher('PROBE-R36-O', 'probe-r36-o@example.com');
        $l2 = $this->makeTeacher('PROBE-R36-L2', 'probe-r36-l2@example.com');
        $l3 = $this->makeTeacher('PROBE-R36-L3', 'probe-r36-l3@example.com');
        $dead = $this->makeTeacher('PROBE-R36-DEAD', 'probe-r36-dead@example.com', 'suspended');

        $k = MyClass::create(['name' => 'ProbeR36Class', 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R36 S', 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $s2 = Section::create(['name' => 'R36 S2', 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $x = Subject::create(['name' => 'Probe Subject R36X', 'my_class_id' => $k->id, 'teacher_id' => $d->id, 'slug' => 'probe-r36-x', 'periods_per_week' => 6]);
        $y = Subject::create(['name' => 'Probe Subject R36Y', 'my_class_id' => $k->id, 'teacher_id' => $d->id, 'slug' => 'probe-r36-y', 'periods_per_week' => 6]);

        $student = User::create(['name' => 'Probe Student R36', 'username' => 'PROBE-R36-ST', 'email' => 'probe-r36-st@example.com', 'password' => 'secret', 'code' => 'PROBE-R36-ST', 'user_type' => 'student']);
        StudentRecord::create(['session' => Qs::getCurrentSession(), 'user_id' => $student->id, 'my_class_id' => $k->id, 'section_id' => $s->id, 'adm_no' => 'R36-ST', 'grad' => 0]);

        // (a) Index BEFORE: no overrides, nothing unassigned (both
        // subjects resolve to live direct teacher D).
        $index = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => $k->id]))->assertOk();
        $this->assertSame(0, (int) ($index->viewData('overrideCounts')[$s->id] ?? 0), 'S must start with 0 overrides.');
        $this->assertSame([], $index->viewData('unresolvedSubjects')[$s->id] ?? null, 'S must start fully resolved.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $x));
        $this->assertSame([], TeacherScope::classIds($o), 'O must start with no class scope.');
        $this->assertSame(0, $this->auditCount($s->id));

        // (b) Matrix save assigns O to X for S ONLY.
        $resp = $this->matrixSave($s, [$x->id => Qs::hash($o->id), $y->id => '']);
        $resp->assertRedirect(route('sections.index'));
        $resp->assertSessionHas('flash_success', 'Subject teachers saved for R36 S.');
        $this->assertSame([[$s->id, $x->id, $o->id]], $this->overrideRows($s->id));
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($s->id, $x), 'teacherIdFor must flip to O immediately.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $y), 'Y stays direct-taught by D.');
        $this->assertContains($k->id, TeacherScope::classIds($o), 'O scope must gain K same-session.');
        $this->assertSame(1, $this->dashboardScope($o), 'Dashboard scope must agree with TeacherScope (P10).');
        $banner = $this->bannerValues($o);
        $this->assertSame(1, $banner['Classes'] ?? null, 'Banner classes must agree (P10).');
        $this->assertSame(1, $banner['Students'] ?? null, 'Banner students must agree: the one S pupil (P10).');
        $this->assertSame(1, $this->auditCount($s->id), 'One real change must write exactly one audit row.');
        $audit = DB::table('audit_logs')->where('action', 'sections.teachers_updated')->where('subject_id', $s->id)->first();
        $this->assertStringContainsString('Probe Subject R36X', $audit->description);

        // Index AFTER (b): S shows exactly 1 override.
        $index = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => $k->id]))->assertOk();
        $this->assertSame(1, (int) ($index->viewData('overrideCounts')[$s->id] ?? 0));
        $index->assertSee('1 override');

        // (c) Sibling save on S2 must leave S byte-identical
        // (cross-section isolation) and write no S audit row.
        $before = $this->overrideRows($s->id);
        $auditBefore = $this->auditCount($s->id);
        $this->matrixSave($s2, [$x->id => '', $y->id => Qs::hash($l2->id)])->assertRedirect(route('sections.index'));
        $this->assertSame([[$s2->id, $y->id, $l2->id]], $this->overrideRows($s2->id));
        $this->assertSame($before, $this->overrideRows($s->id), 'S rows must be byte-identical after a sibling save.');
        $this->assertSame($auditBefore, $this->auditCount($s->id), 'Sibling save must not audit S.');
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($s->id, $x), 'S resolution untouched by sibling save.');

        // (d) Mixed save on S2: suspended teacher for X is SKIPPED
        // and NAMED in the flash, while the live Y change lands.
        $resp = $this->matrixSave($s2, [$x->id => Qs::hash($dead->id), $y->id => Qs::hash($l3->id)]);
        $resp->assertRedirect(route('sections.index'));
        $resp->assertSessionHas('flash_success');
        $this->assertStringContainsString('Ignored invalid entries for: Probe Subject R36X', session('flash_success'), 'Dead row must be named in the flash (P7).');
        $this->assertSame([[$s2->id, $y->id, $l3->id]], $this->overrideRows($s2->id), 'Dead X row never stored; live Y change still landed.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s2->id, $x), 'Skipped X still resolves to direct teacher D.');

        // (e) No-op re-save of S writes NO audit row; a real change
        // writes exactly one more.
        $auditBefore = $this->auditCount($s->id);
        $this->matrixSave($s, [$x->id => Qs::hash($o->id), $y->id => ''])->assertRedirect(route('sections.index'));
        $this->assertSame($auditBefore, $this->auditCount($s->id), 'No-op re-save must not audit.');
        $this->matrixSave($s, [$x->id => Qs::hash($o->id), $y->id => Qs::hash($l2->id)])->assertRedirect(route('sections.index'));
        $this->assertSame($auditBefore + 1, $this->auditCount($s->id), 'One real change must write exactly one audit row.');

        // (f) Blank save clears S: fallback to direct teacher D,
        // O loses K scope same-session.
        $this->matrixSave($s, [$x->id => '', $y->id => ''])->assertRedirect(route('sections.index'));
        $this->assertSame([], $this->overrideRows($s->id), 'Blank save must clear every S override.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $x), 'Clearing hatch restores direct teacher (P3).');
        $this->assertSame([], TeacherScope::classIds($o), 'O scope must drop K same-session after clearing.');
        $this->assertSame(0, $this->dashboardScope($o), 'Dashboard scope must agree after clearing (P10).');
        $banner = $this->bannerValues($o);
        $this->assertSame(0, $banner['Classes'] ?? null, 'Banner classes must agree after clearing (P10).');
    }

    /**
     * P9 lock + BRIEF-PREMISE CORRECTION: the round brief said a raw
     * teacher id "decodes to '' and CLEARS the row". That is NOT what
     * the code does. Qs::decodeHash on a raw numeric string returns
     * '' (Hashids finds no encoding), so updateTeachers treats it
     * exactly like a forged value: SKIPPED, named in the flash, and
     * the existing row SURVIVES untouched — it is neither stored
     * nor cleared. (Blank '' is the only clearing input.) Proven
     * with a throwaway probe first (raw D id posted over a stored
     * O row: row stayed O, flash named the subject), then locked
     * here. The forgiving skip is the F-R31-1 shape, not a hole:
     * a raw id can never smuggle in a wrong teacher, and it can
     * never wipe a live override by accident.
     */
    public function test_raw_teacher_id_is_skipped_and_existing_row_survives(): void
    {
        $d = $this->makeTeacher('PROBE-R36-RD', 'probe-r36-rd@example.com');
        $o = $this->makeTeacher('PROBE-R36-RO', 'probe-r36-ro@example.com');
        $k = MyClass::create(['name' => 'ProbeR36RawClass', 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R36 Raw S', 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $x = Subject::create(['name' => 'Probe Subject R36Raw', 'my_class_id' => $k->id, 'teacher_id' => $d->id, 'slug' => 'probe-r36-raw', 'periods_per_week' => 6]);

        $this->matrixSave($s, [$x->id => Qs::hash($o->id)])->assertRedirect(route('sections.index'));
        $this->assertSame([[$s->id, $x->id, $o->id]], $this->overrideRows($s->id));

        // Raw numeric id of a DIFFERENT live teacher: must not store.
        $resp = $this->matrixSave($s, [$x->id => (string) $d->id]);
        $resp->assertRedirect(route('sections.index'));
        $this->assertStringContainsString('Ignored invalid entries for: Probe Subject R36Raw', session('flash_success'));
        $this->assertSame([[$s->id, $x->id, $o->id]], $this->overrideRows($s->id), 'Raw id must neither store nor clear the existing row.');
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($s->id, $x));
    }
}
