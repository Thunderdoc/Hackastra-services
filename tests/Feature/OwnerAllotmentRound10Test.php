<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\StudyMaterial;
use App\Support\Bulk\StaffBulkModule;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agent F round 10 — override-teacher assignment/material
 * LIFECYCLE completeness. Round 6 fixed create/picker/push
 * entry points for override teachers; this round probes the
 * rest of the lifecycle for an override-only teacher:
 *
 *   - edit + update of her OWN assignment (update re-validates
 *     via teacherIdFor, so a raw-teacher regression would 403
 *     her own edit even though create passed);
 *   - delete of her OWN material, and refusal for a foreign
 *     teacher's delete of her material;
 *   - push-to-Internal RE-push after a grade change: the second
 *     push must overwrite (exactly one mark row + one component
 *     row), never duplicate, and stay scoped to graded students.
 *
 * Anchors: Ritu Sharma (teacher20@teacher.com, id 44) holds ONLY
 * overrides — (65,79) in Class 12 (my_class_id 13, section 65)
 * and (71,79). Subject 79 (Class 12 English) is directly taught
 * by Ananya Sharma (id 4), who is the FOREIGN teacher here:
 * she teaches the subject but does not own Ritu's rows. Exam 1
 * (global Term 1, unpublished) + subject 79 has Internal
 * Assessment component id 38, max 30. Section 65 holds 40
 * active students.
 */
class OwnerAllotmentRound10Test extends TestCase
{
    use DatabaseTransactions;

    protected function ritu()
    {
        return User::where('email', 'teacher20@teacher.com')->firstOrFail();
    }

    protected function ananya()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function makeAssignment(array $over = []): Assignment
    {
        return Assignment::create(array_merge([
            'teacher_id' => $this->ritu()->id,
            'my_class_id' => 13,
            'section_id' => 65,
            'subject_id' => 79,
            'title' => 'R10 lifecycle probe',
            'description' => 'probe',
            'max_marks' => 100,
            'due_at' => '2030-01-01 10:00:00',
            'allow_late' => false,
        ], $over));
    }

    protected function section65StudentId(): int
    {
        return (int) StudentRecord::where('my_class_id', 13)
            ->where('section_id', 65)->where('grad', 0)
            ->orderBy('id')->value('user_id');
    }

    public function test_override_teacher_can_edit_and_update_own_assignment()
    {
        $a = $this->makeAssignment();

        $this->actingAs($this->ritu())
            ->get(route('assignments.edit', $a->id))
            ->assertOk();

        $this->actingAs($this->ritu())
            ->put(route('assignments.update', $a->id), [
                'title' => 'R10 lifecycle probe (edited)',
                'description' => 'probe',
                'my_class_id' => 13,
                'section_id' => 65,
                'subject_id' => 79,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertRedirect(route('assignments.show', $a->id));

        $this->assertSame('R10 lifecycle probe (edited)', $a->fresh()->title);
    }

    public function test_direct_teacher_cannot_edit_or_delete_override_teachers_assignment()
    {
        $a = $this->makeAssignment();

        // Ananya directly teaches subject 79, but she does not OWN
        // Ritu's row: row ownership (teacher_id), not subject
        // teacher, gates edit/delete.
        $this->actingAs($this->ananya())
            ->put(route('assignments.update', $a->id), [
                'title' => 'hijack',
                'my_class_id' => 13,
                'section_id' => 65,
                'subject_id' => 79,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
            ])
            ->assertForbidden();

        $this->actingAs($this->ananya())
            ->delete(route('assignments.destroy', $a->id))
            ->assertForbidden();

        $this->assertNotNull($a->fresh());
        $this->assertSame('R10 lifecycle probe', $a->fresh()->title);
    }

    public function test_override_teacher_can_delete_own_material_and_foreign_cannot()
    {
        $own = StudyMaterial::create([
            'teacher_id' => $this->ritu()->id,
            'my_class_id' => 13,
            'section_id' => 65,
            'subject_id' => 79,
            'title' => 'R10 own material',
            'link_url' => 'https://example.com/notes.pdf',
        ]);
        $this->actingAs($this->ritu())
            ->delete(route('materials.destroy', $own->id))
            ->assertRedirect(route('materials.index'));
        $this->assertDatabaseMissing('study_materials', ['id' => $own->id]);

        $foreign = StudyMaterial::create([
            'teacher_id' => $this->ritu()->id,
            'my_class_id' => 13,
            'section_id' => 65,
            'subject_id' => 79,
            'title' => 'R10 foreign-target material',
            'link_url' => 'https://example.com/notes.pdf',
        ]);
        $this->actingAs($this->ananya())
            ->delete(route('materials.destroy', $foreign->id))
            ->assertForbidden();
        $this->assertDatabaseHas('study_materials', ['id' => $foreign->id]);
    }

    public function test_override_teacher_repush_after_regrade_overwrites_without_duplicating()
    {
        $a = $this->makeAssignment();
        $studentId = $this->section65StudentId();
        $sub = AssignmentSubmission::create([
            'assignment_id' => $a->id,
            'student_id' => $studentId,
            'text_answer' => 'probe answer',
            'submitted_at' => now(),
            'is_late' => false,
        ]);

        // The seed already holds component-38 rows for all 40
        // section-65 students: the scoping proof is that the OTHER
        // 39 rows are byte-identical after both pushes, while only
        // our student's row moves (seeded value -> 24 -> 15).
        $othersBefore = DB::table('mark_components as mc')
            ->join('marks as m', 'm.id', '=', 'mc.mark_id')
            ->where('mc.component_id', 38)
            ->where('m.exam_id', 1)->where('m.subject_id', 79)
            ->where('m.section_id', 65)->where('m.student_id', '!=', $studentId)
            ->pluck('mc.score', 'mc.mark_id')->all();

        // Grade 80/100 as Ritu, push, expect 80% of 30 = 24.
        $this->actingAs($this->ritu())
            ->from(route('assignments.show', $a->id))
            ->post(route('assignments.grade', $sub->id), ['marks_awarded' => 80])
            ->assertRedirect();
        $this->actingAs($this->ritu())
            ->from(route('assignments.show', $a->id))
            ->post(route('assignments.push_internal', $a->id), ['exam_id' => 1])
            ->assertRedirect();

        $markId = DB::table('marks')
            ->where('exam_id', 1)->where('student_id', $studentId)
            ->where('subject_id', 79)->value('id');
        $this->assertNotNull($markId, 'Push must create the student mark row');
        $this->assertSame(1, DB::table('marks')
            ->where('exam_id', 1)->where('student_id', $studentId)
            ->where('subject_id', 79)->count());
        $this->assertSame(24, (int) DB::table('mark_components')
            ->where('mark_id', $markId)->where('component_id', 38)->value('score'));

        // Re-grade to 50/100, re-push: still one mark row and one
        // component row, now 50% of 30 = 15. No other section-65
        // student gains a component row (ungraded => skipped).
        $this->actingAs($this->ritu())
            ->from(route('assignments.show', $a->id))
            ->post(route('assignments.grade', $sub->id), ['marks_awarded' => 50])
            ->assertRedirect();
        $this->actingAs($this->ritu())
            ->from(route('assignments.show', $a->id))
            ->post(route('assignments.push_internal', $a->id), ['exam_id' => 1])
            ->assertRedirect();

        $this->assertSame(1, DB::table('marks')
            ->where('exam_id', 1)->where('student_id', $studentId)
            ->where('subject_id', 79)->count());
        $this->assertSame(1, DB::table('mark_components')
            ->where('mark_id', $markId)->where('component_id', 38)->count());
        $this->assertSame(15, (int) DB::table('mark_components')
            ->where('mark_id', $markId)->where('component_id', 38)->value('score'));
        $othersAfter = DB::table('mark_components as mc')
            ->join('marks as m', 'm.id', '=', 'mc.mark_id')
            ->where('mc.component_id', 38)
            ->where('m.exam_id', 1)->where('m.subject_id', 79)
            ->where('m.section_id', 65)->where('m.student_id', '!=', $studentId)
            ->pluck('mc.score', 'mc.mark_id')->all();
        $this->assertSame($othersBefore, $othersAfter);
    }

    /**
     * Round-9 guard, second shape: a teacher holding BOTH a led
     * section AND an override row. The guard order checks sections
     * before overrides, so this must refuse on the section guard —
     * and user, led section, and override row must all survive.
     * (Round 9 proved overrides-alone; this locks the dual case
     * rather than assuming the first guard fires.)
     */
    public function test_bulk_rollback_refuses_teacher_with_led_section_and_override()
    {
        $teacher = User::create([
            'name' => 'Probe Teacher R10',
            'username' => 'TEST/R10/' . strtoupper(Str::random(6)),
            'email' => 'probe-r10-dual@example.com',
            'password' => 'secret',
            'code' => 'TEST/R10/' . strtoupper(Str::random(6)),
            'user_type' => 'teacher',
        ]);
        $section = Section::create([
            'name' => 'R10 probe',
            'my_class_id' => 12,
            'teacher_id' => $teacher->id,
        ]);
        $subjectId = DB::table('subjects')->where('my_class_id', 12)
            ->whereNotIn('id', DB::table('section_subject_teachers')
                ->where('section_id', 55)->pluck('subject_id'))
            ->orderBy('id')->value('id');
        $this->assertNotNull($subjectId, 'No free override pair in section 55.');
        DB::table('section_subject_teachers')->insert([
            'section_id' => 55,
            'subject_id' => $subjectId,
            'teacher_id' => $teacher->id,
        ]);

        // Flag, not fail(), inside the try: PHPUnit's fail() throws
        // an AssertionFailedError extending RuntimeException, which
        // the catch below would swallow (round-9 trap).
        $refused = false;
        try {
            (new StaffBulkModule(new \App\Repositories\UserRepo))->rollbackAdd($teacher->id);
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/class teacher/', $e->getMessage());
            $refused = true;
        }
        $this->assertTrue($refused, 'rollbackAdd should have refused a dual-attached teacher.');

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'teacher_id' => $teacher->id]);
        $this->assertDatabaseHas('section_subject_teachers', [
            'section_id' => 55, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id,
        ]);
    }
}
