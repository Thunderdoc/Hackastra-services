<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Parent, 2026-10-28 — closes agent F's F8 gap: deleting a teacher
 * must not orphan section leadership or per-section subject overrides.
 * The destroy guard already refused subject teachers; it now also
 * refuses section-leading and override-holding teachers.
 */
class OwnerTeacherDestroyOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private function makeTeacher($code, $email)
    {
        return User::create([
            'name' => 'Probe Teacher',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_override_teacher_cannot_be_deleted()
    {
        $teacher = $this->makeTeacher('PROBE-DEL-1', 'probe-del-1@example.com');
        // Pick a (section, subject) pair with no seeded override row.
        $pair = null;
        foreach ([55, 56, 57] as $sectionId) {
            foreach ([67, 68, 69, 70, 71, 72] as $subjectId) {
                if (!DB::table('section_subject_teachers')->where('section_id', $sectionId)->where('subject_id', $subjectId)->exists()) {
                    $pair = [$sectionId, $subjectId];
                    break 2;
                }
            }
        }
        $this->assertNotNull($pair, 'No free override pair found in seed.');
        DB::table('section_subject_teachers')->insert([
            'section_id' => $pair[0], 'subject_id' => $pair[1], 'teacher_id' => $teacher->id,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', Qs::hash($teacher->id)))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertDatabaseHas('section_subject_teachers', [
            'section_id' => $pair[0], 'subject_id' => $pair[1], 'teacher_id' => $teacher->id,
        ]);
    }

    public function test_section_leading_teacher_cannot_be_deleted()
    {
        $teacher = $this->makeTeacher('PROBE-DEL-2', 'probe-del-2@example.com');
        $section = Section::find(55);
        $originalTeacher = $section->teacher_id;
        $section->update(['teacher_id' => $teacher->id]);

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', Qs::hash($teacher->id)))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertEquals($teacher->id, Section::find(55)->teacher_id);
        $section->update(['teacher_id' => $originalTeacher]);
    }

    public function test_unattached_teacher_can_still_be_deleted()
    {
        $teacher = $this->makeTeacher('PROBE-DEL-3', 'probe-del-3@example.com');

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', Qs::hash($teacher->id)))
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $teacher->id]);
    }
}
