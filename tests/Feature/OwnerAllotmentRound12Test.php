<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 12 (2026-10-28) — matrix-save cross-section
 * isolation.
 *
 * SectionController::updateTeachers scopes every write to the
 * saved section (all delete/insert/update queries carry
 * where('section_id', $section->id), and the subject loop visits
 * only the section's own class subjects). This round proves that
 * scoping empirically, because a cross-section clobber here would
 * silently rewrite another section's staffing:
 *
 *  - a matrix PUT for section X (change + clear-to-blank) must not
 *    touch section Y's override rows, nor any other section's;
 *  - a matrix PUT for section Y must update only Y's rows;
 *  - the round-5 deleted-subject cleanup must delete ONLY the
 *    saved section's orphan rows: a stale LIVE-subject row of the
 *    saved section survives a normal save (round-2 rule: only
 *    clear_stale[] removes it), and other sections' stale rows
 *    survive both a normal save and a clear_stale aimed at X.
 *
 * Anchors (asserted, not assumed): section 1 = Prep A
 * (my_class_id 1, subjects 1–6); section 4 = a Class 1 section
 * (my_class_id 2, subjects 7–12). The probe teacher leads X and
 * holds an override in Y, mirroring the seeded Kavya shape (leads
 * one section, overrides another class's section) with fresh
 * fixtures instead of seed coupling.
 */
class OwnerAllotmentRound12Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function makeTeacher($code, $email)
    {
        return User::create([
            'name' => 'Probe Teacher R12',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    private function putMatrix($sectionId, array $teachers, array $extra = [])
    {
        return $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash($sectionId)),
            array_merge(['teachers' => $teachers], $extra)
        );
    }

    /** All override rows as comparable strings, optionally excluding one section. */
    private function overrideSnapshot($excludeSectionId = null)
    {
        $q = DB::table('section_subject_teachers')
            ->orderBy('section_id')->orderBy('subject_id')->orderBy('teacher_id');
        if ($excludeSectionId !== null) {
            $q->where('section_id', '!=', $excludeSectionId);
        }
        return $q->get()->map(function ($r) {
            return $r->section_id . ':' . $r->subject_id . ':' . $r->teacher_id;
        })->all();
    }

    private function overrideRow($sectionId, $subjectId)
    {
        return DB::table('section_subject_teachers')
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)->first();
    }

    private function insertOverride($sectionId, $subjectId, $teacherId)
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $sectionId, 'subject_id' => $subjectId,
            'teacher_id' => $teacherId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function anchors()
    {
        $x = Section::find(1);
        $y = Section::find(4);
        $this->assertSame(1, (int) $x->my_class_id);
        $this->assertSame(2, (int) $y->my_class_id);
        $this->assertSame(1, (int) Subject::find(1)->my_class_id);
        $this->assertSame(2, (int) Subject::find(7)->my_class_id);
        return [$x, $y];
    }

    public function test_matrix_save_for_x_leaves_y_and_other_sections_untouched()
    {
        [$x, $y] = $this->anchors();
        $teacher = $this->makeTeacher('PROBE-R12-X', 'probe-r12-x@example.com');
        // Teacher leads X and holds an override in Y.
        $x->update(['teacher_id' => $teacher->id]);
        $this->insertOverride($y->id, 7, $teacher->id);

        $before = $this->overrideSnapshot($x->id);
        $response = $this->putMatrix($x->id, [1 => Qs::hash($teacher->id)]);
        $response->assertRedirect(route('sections.index'));

        // X's own change applied…
        $this->assertSame($teacher->id, (int) $this->overrideRow($x->id, 1)->teacher_id);
        // …and every other section's rows are byte-identical.
        $this->assertSame($before, $this->overrideSnapshot($x->id));
        $this->assertSame($teacher->id, (int) $this->overrideRow($y->id, 7)->teacher_id);
    }

    public function test_matrix_save_for_y_updates_only_y()
    {
        [$x, $y] = $this->anchors();
        $teacher = $this->makeTeacher('PROBE-R12-Y', 'probe-r12-y@example.com');
        $other = $this->makeTeacher('PROBE-R12-Y2', 'probe-r12-y2@example.com');
        $x->update(['teacher_id' => $teacher->id]);
        $this->insertOverride($x->id, 1, $teacher->id);
        $this->insertOverride($y->id, 7, $teacher->id);

        $before = $this->overrideSnapshot($y->id);
        $response = $this->putMatrix($y->id, [7 => Qs::hash($other->id)]);
        $response->assertRedirect(route('sections.index'));

        $this->assertSame($other->id, (int) $this->overrideRow($y->id, 7)->teacher_id);
        $this->assertSame($before, $this->overrideSnapshot($y->id));
        $this->assertSame($teacher->id, (int) $this->overrideRow($x->id, 1)->teacher_id);
    }

    public function test_matrix_save_cleans_only_same_section_deleted_subject_orphan()
    {
        [$x, $y] = $this->anchors();
        $teacher = $this->makeTeacher('PROBE-R12-O', 'probe-r12-o@example.com');
        // X: one orphan (subject row gone) + one stale LIVE row
        // (Class 1 subject on a Prep section). Y: a live row + a
        // stale LIVE row of its own.
        $this->insertOverride($x->id, 999999, $teacher->id);
        $this->insertOverride($x->id, 7, $teacher->id);
        $this->insertOverride($y->id, 7, $teacher->id);
        $this->insertOverride($y->id, 1, $teacher->id);

        $response = $this->putMatrix($x->id, [1 => Qs::hash($teacher->id)]);
        $response->assertRedirect(route('sections.index'));

        // Round-5 cleanup fires, but ONLY for X's dead subject…
        $this->assertNull($this->overrideRow($x->id, 999999));
        // …the stale live row of X survives a normal save
        // (only clear_stale[] removes it — round-2 rule)…
        $this->assertNotNull($this->overrideRow($x->id, 7));
        // …and Y is entirely untouched.
        $this->assertNotNull($this->overrideRow($y->id, 7));
        $this->assertNotNull($this->overrideRow($y->id, 1));
    }

    public function test_matrix_clear_stale_touches_only_own_section()
    {
        [$x, $y] = $this->anchors();
        $teacher = $this->makeTeacher('PROBE-R12-S', 'probe-r12-s@example.com');
        $this->insertOverride($x->id, 7, $teacher->id);
        $this->insertOverride($y->id, 1, $teacher->id);
        $this->insertOverride($y->id, 7, $teacher->id);

        $response = $this->putMatrix($x->id, [1 => Qs::hash($teacher->id)], ['clear_stale' => [7]]);
        $response->assertRedirect(route('sections.index'));

        // X's stale row cleared by its own clear_stale…
        $this->assertNull($this->overrideRow($x->id, 7));
        // …while Y's stale row AND live row both survive it.
        $this->assertNotNull($this->overrideRow($y->id, 1));
        $this->assertNotNull($this->overrideRow($y->id, 7));
    }
}
