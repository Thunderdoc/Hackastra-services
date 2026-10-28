<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 5 (2026-10-28) — section edit matrix save-path edge
 * cases + audit trail for allotment changes.
 *
 * Rounds 1–4 proved what the matrix SHOWS (readiness, live-teacher
 * flags, stale blocks, ghost remediation). This round proves what
 * the matrix SAVE does under hostile or mid-edit-changed input:
 *
 *  - a subject id deleted mid-edit (stale key in the POST) must not
 *    500 or create a bogus override row;
 *  - a subject id from ANOTHER class in the POST must not plant an
 *    override on this section (the matrix version of the
 *    subject-class tamper guard);
 *  - an override row whose subject was deleted must not survive
 *    silently: it can never apply again, is invisible in the stale
 *    block (which inner-joins subjects), yet still inflates the
 *    index Overrides count — a save must clean it;
 *  - the same teacher teaching the same subject in two sections of
 *    one class is ALLOWED by design (the unique key is
 *    (section, subject), not (subject, teacher)) — the matrix is
 *    per-section by design, and sharing across sections is natural;
 *  - blank (or absent) selection clears an override cleanly and
 *    resolution falls back to the subject's class teacher;
 *  - a crafted array value in teachers[] must not 500 (Hashids
 *    decode is non-string-safe) — it lands in the skipped list;
 *  - a matrix save that actually changes allotments must leave an
 *    audit row (Planning apply logs; the matrix save did not).
 */
class OwnerAllotmentRound5Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function makeTeacher($code, $email)
    {
        return User::create([
            'name' => 'Probe Teacher R5',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    private function putMatrix($sectionId, array $teachers)
    {
        return $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash($sectionId)),
            ['teachers' => $teachers]
        );
    }

    private function overrideRow($sectionId, $subjectId)
    {
        return DB::table('section_subject_teachers')
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)->first();
    }

    public function test_matrix_post_with_deleted_subject_id_ignored()
    {
        // Prep = class 1, subjects 1–6, sections 1–3 (Prep A/B/C).
        $teacher = $this->makeTeacher('PROBE-R5-DEL', 'probe-r5-del@example.com');
        $probe = Subject::create([
            'name' => 'Probe Deleted R5', 'my_class_id' => 1,
            'teacher_id' => 4, 'slug' => 'probe-deleted-r5',
            'periods_per_week' => 6,
        ]);
        // Deleted mid-edit: the form was rendered, then the subject went away.
        DB::table('subjects')->where('id', $probe->id)->delete();

        $response = $this->putMatrix(1, [
            $probe->id => Qs::hash($teacher->id),
            1 => Qs::hash($teacher->id),
        ]);

        $response->assertRedirect(route('sections.index'));
        // The valid subject's override applied; the deleted id created nothing.
        $this->assertNotNull($this->overrideRow(1, 1));
        $this->assertSame($teacher->id, (int) $this->overrideRow(1, 1)->teacher_id);
        $this->assertNull($this->overrideRow(1, $probe->id));
    }

    public function test_matrix_post_with_foreign_class_subject_id_ignored()
    {
        $teacher = $this->makeTeacher('PROBE-R5-FGN', 'probe-r5-fgn@example.com');
        // Subject 7 belongs to Class 1 (my_class_id 2), not Prep.
        $this->assertSame(2, (int) Subject::find(7)->my_class_id);

        $response = $this->putMatrix(1, [7 => Qs::hash($teacher->id)]);

        $response->assertRedirect(route('sections.index'));
        $this->assertNull($this->overrideRow(1, 7));
    }

    public function test_matrix_save_cleans_override_of_deleted_subject()
    {
        $teacher = $this->makeTeacher('PROBE-R5-ORP', 'probe-r5-orp@example.com');
        $probe = Subject::create([
            'name' => 'Probe Orphan R5', 'my_class_id' => 1,
            'teacher_id' => 4, 'slug' => 'probe-orphan-r5',
            'periods_per_week' => 6,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => $probe->id,
            'teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Subject deleted after the override existed (direct-DB path —
        // the UI subject destroy already cleans overrides; this is the
        // race the matrix save must also close).
        DB::table('subjects')->where('id', $probe->id)->delete();
        $this->assertNotNull($this->overrideRow(1, $probe->id));

        // Echo the surviving subjects' current state so the save is a
        // no-op for everything except the orphan cleanup.
        $payload = [];
        foreach (Subject::where('my_class_id', 1)->get() as $sub) {
            $ov = $this->overrideRow(1, $sub->id);
            $payload[$sub->id] = $ov ? Qs::hash($ov->teacher_id) : '';
        }
        $response = $this->putMatrix(1, $payload);

        $response->assertRedirect(route('sections.index'));
        $this->assertNull($this->overrideRow(1, $probe->id));
    }

    public function test_same_teacher_same_subject_two_sections_allowed()
    {
        $teacher = $this->makeTeacher('PROBE-R5-SHR', 'probe-r5-shr@example.com');
        $subject = Subject::find(1); // Prep English.

        $this->putMatrix(1, [1 => Qs::hash($teacher->id)])
            ->assertRedirect(route('sections.index'));
        $this->putMatrix(2, [1 => Qs::hash($teacher->id)])
            ->assertRedirect(route('sections.index'));

        $this->assertNotNull($this->overrideRow(1, 1));
        $this->assertNotNull($this->overrideRow(2, 1));
        $this->assertSame($teacher->id, SectionTeacher::teacherIdFor(1, $subject));
        $this->assertSame($teacher->id, SectionTeacher::teacherIdFor(2, $subject));
    }

    public function test_empty_selection_clears_override()
    {
        $teacher = $this->makeTeacher('PROBE-R5-CLR', 'probe-r5-clr@example.com');
        $subject = Subject::find(1);
        $this->assertSame(4, (int) $subject->teacher_id); // Ananya, class teacher.

        $this->putMatrix(1, [1 => Qs::hash($teacher->id)])
            ->assertRedirect(route('sections.index'));
        $this->assertSame($teacher->id, SectionTeacher::teacherIdFor(1, $subject));

        $this->putMatrix(1, [1 => ''])
            ->assertRedirect(route('sections.index'));
        $this->assertNull($this->overrideRow(1, 1));
        // Resolution falls back to the subject's class teacher.
        $this->assertSame(4, SectionTeacher::teacherIdFor(1, $subject));
    }

    public function test_absent_key_clears_override()
    {
        $teacher = $this->makeTeacher('PROBE-R5-ABS', 'probe-r5-abs@example.com');
        $this->putMatrix(1, [1 => Qs::hash($teacher->id)])
            ->assertRedirect(route('sections.index'));
        $this->assertNotNull($this->overrideRow(1, 1));

        // A partial POST that omits the subject behaves like a blank:
        // the form always submits every key, so omission = no override.
        $this->putMatrix(1, [2 => ''])
            ->assertRedirect(route('sections.index'));
        $this->assertNull($this->overrideRow(1, 1));
    }

    public function test_stale_override_survives_normal_save()
    {
        // Guard for the round-2 design: the orphan cleanup must delete
        // ONLY rows whose subject no longer exists. A stale row (live
        // subject of ANOTHER class) must survive a normal save — it is
        // removed only via the edit page's clear_stale checkbox.
        $teacher = $this->makeTeacher('PROBE-R5-STL', 'probe-r5-stl@example.com');
        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => 7, // Class 1 subject, Prep section
            'teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = [];
        foreach (Subject::where('my_class_id', 1)->get() as $sub) {
            $ov = $this->overrideRow(1, $sub->id);
            $payload[$sub->id] = $ov ? Qs::hash($ov->teacher_id) : '';
        }
        $this->putMatrix(1, $payload)
            ->assertRedirect(route('sections.index'));

        $this->assertNotNull($this->overrideRow(1, 7));
    }

    public function test_array_value_treated_as_invalid_not_500()
    {
        DB::table('section_subject_teachers')
            ->where('section_id', 1)->where('subject_id', 1)->delete();

        $response = $this->putMatrix(1, [1 => ['x']]);

        $response->assertRedirect(route('sections.index'));
        $this->assertStringContainsString(
            'Ignored invalid entries for:',
            $response->getSession()->get('flash_success')
        );
        $this->assertNull($this->overrideRow(1, 1));
    }

    public function test_matrix_save_writes_audit_row()
    {
        $teacher = $this->makeTeacher('PROBE-R5-AUD', 'probe-r5-aud@example.com');
        // Match on action + actor + subject, not on raw counts: sibling
        // agents' tests write audit rows to the shared DB concurrently.
        $matching = function () {
            return DB::table('audit_logs')
                ->where('action', 'sections.teachers_updated')
                ->where('user_id', $this->admin()->id)
                ->where('subject_id', 1)->orderBy('id')->get();
        };

        $this->putMatrix(1, [1 => Qs::hash($teacher->id)])
            ->assertRedirect(route('sections.index'));

        $rows = $matching();
        $this->assertCount(1, $rows);
        $this->assertSame('sections.teachers_updated', $rows[0]->action);
        $this->assertSame($this->admin()->id, (int) $rows[0]->user_id);
        $this->assertSame(1, (int) $rows[0]->subject_id);
        $this->assertStringContainsString('English Language', $rows[0]->description);
        $this->assertStringContainsString('Probe Teacher R5', $rows[0]->description);

        // A no-op re-save (identical values) must not spam the audit log.
        // Echo the section's full current state so nothing else changes.
        $payload = [];
        foreach (Subject::where('my_class_id', 1)->get() as $sub) {
            $ov = $this->overrideRow(1, $sub->id);
            $payload[$sub->id] = $ov ? Qs::hash($ov->teacher_id) : '';
        }
        $this->putMatrix(1, $payload)
            ->assertRedirect(route('sections.index'));
        $this->assertCount(1, $matching());
    }
}
