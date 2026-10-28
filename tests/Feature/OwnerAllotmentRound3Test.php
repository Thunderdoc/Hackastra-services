<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Support\Allocation;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 3 (2026-10-28) — demotion + ghost-teacher resolution.
 *
 * Round 2 proved the sections-index readiness counts only LIVE
 * teacher rows for DELETED teachers (row gone). This round proves
 * the harder cases: a teacher user DEMOTED to another user_type
 * (row still exists) and a teacher row deleted outright leaving
 * ghost ids behind (reachable via direct DB work, since the UI
 * destroy guard refuses attached teachers). A demoted/suspended/
 * ghost teacher must NEVER read as staffed anywhere: sections
 * index, edit matrix, Staffing committed, or Allocation issues.
 * teacherIdFor itself is deliberately UNCHANGED (its raw id feeds
 * the clash validator and generation, whose blocking behavior is
 * an owner call) — only the staffing-truth surfaces filter it.
 */
class OwnerAllotmentRound3Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function makeTeacher($code, $email)
    {
        return User::create([
            'name' => 'Probe Teacher R3',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    public function test_demoted_subject_teacher_flagged_unassigned_on_sections_index()
    {
        // Prep English (subject 1) is taught by Ananya (id 4).
        User::findOrFail(4)->update(['user_type' => 'accountant']);

        $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('English')
            ->assertDontSee('All staffed');
    }

    public function test_suspended_subject_teacher_flagged_unassigned_on_sections_index()
    {
        User::findOrFail(4)->update(['status' => 'suspended']);

        $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('English')
            ->assertDontSee('All staffed');
    }

    public function test_demoted_teacher_flagged_in_edit_matrix()
    {
        User::findOrFail(4)->update(['user_type' => 'librarian']);

        $this->actingAs($this->admin())
            ->get(route('sections.edit', 1))
            ->assertOk()
            ->assertSee('No live teacher assigned');
    }

    public function test_ghost_override_flagged_in_edit_matrix()
    {
        $probe = $this->makeTeacher('PROBE-R3-OV', 'probe-r3-ov@example.com');
        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => 2, 'teacher_id' => $probe->id,
        ]);
        // Ghost: the row is deleted outright, the override stays.
        DB::table('users')->where('id', $probe->id)->delete();

        $this->actingAs($this->admin())
            ->get(route('sections.edit', 1))
            ->assertOk()
            ->assertSee('No live teacher assigned');
    }

    public function test_ghost_subject_teacher_flagged_in_edit_matrix()
    {
        $probe = $this->makeTeacher('PROBE-R3-GH', 'probe-r3-gh@example.com');
        Subject::create([
            'name' => 'Probe Ghost R3', 'my_class_id' => 1,
            'teacher_id' => $probe->id, 'slug' => 'probe-ghost-r3',
            'periods_per_week' => 6,
        ]);
        DB::table('users')->where('id', $probe->id)->delete();

        $this->actingAs($this->admin())
            ->get(route('sections.edit', 1))
            ->assertOk()
            ->assertSee('No live teacher assigned')
            ->assertSee('Vacant');
    }

    public function test_deleted_section_leader_renders_sections_index()
    {
        $probe = $this->makeTeacher('PROBE-R3-LD', 'probe-r3-ld@example.com');
        Section::find(1)->update(['teacher_id' => $probe->id]);
        DB::table('users')->where('id', $probe->id)->delete();

        $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('Vacant');
    }

    public function test_demoted_section_leader_flagged_on_sections_index()
    {
        $probe = $this->makeTeacher('PROBE-R3-DL', 'probe-r3-dl@example.com');
        Section::find(1)->update(['teacher_id' => $probe->id]);
        $probe->update(['user_type' => 'accountant']);

        $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('not an active teacher');
    }

    public function test_staffing_committed_excludes_demoted_teacher()
    {
        $probe = $this->makeTeacher('PROBE-R3-ST', 'probe-r3-st@example.com');
        Subject::create([
            'name' => 'ProbeStaffR3', 'my_class_id' => 1,
            'teacher_id' => $probe->id, 'slug' => 'probe-staff-r3',
            'periods_per_week' => 6,
        ]);

        $before = Staffing::report();
        $this->assertSame(6, $before['subjects']['ProbeStaffR3']['committed']);

        $probe->update(['user_type' => 'accountant']);
        $after = Staffing::report();
        $this->assertSame(0, $after['subjects']['ProbeStaffR3']['committed']);
        $this->assertSame(6, $after['subjects']['ProbeStaffR3']['unassigned']);
    }

    public function test_staffing_committed_excludes_suspended_teacher()
    {
        $probe = $this->makeTeacher('PROBE-R3-SS', 'probe-r3-ss@example.com');
        Subject::create([
            'name' => 'ProbeSuspR3', 'my_class_id' => 1,
            'teacher_id' => $probe->id, 'slug' => 'probe-susp-r3',
            'periods_per_week' => 6,
        ]);

        $probe->update(['status' => 'suspended']);
        $after = Staffing::report();
        $this->assertSame(0, $after['subjects']['ProbeSuspR3']['committed']);
    }

    public function test_allocation_flags_demoted_teacher_subject_unassigned()
    {
        $probe = $this->makeTeacher('PROBE-R3-AL', 'probe-r3-al@example.com');
        $subject = Subject::create([
            'name' => 'ProbeAllocR3', 'my_class_id' => 1,
            'teacher_id' => $probe->id, 'slug' => 'probe-alloc-r3',
            'periods_per_week' => 6,
        ]);

        $before = collect(Allocation::recommendations())
            ->firstWhere('subject.id', $subject->id);
        $this->assertNull($before, 'Live teacher under cap needs no allocation row.');

        $probe->update(['user_type' => 'accountant']);
        $after = collect(Allocation::recommendations())
            ->firstWhere('subject.id', $subject->id);
        $this->assertNotNull($after, 'Demoted teacher subject must surface for re-allocation.');
        $this->assertSame('unassigned', $after['issue']);
    }

    public function test_teacher_for_ghost_returns_null_without_crash()
    {
        // Deliberately UNCHANGED behavior: teacherIdFor still
        // returns the raw id (validator/generation input); only
        // teacherFor resolves to null, and no surface crashes.
        $probe = $this->makeTeacher('PROBE-R3-TF', 'probe-r3-tf@example.com');
        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => 1, 'teacher_id' => $probe->id,
        ]);
        DB::table('users')->where('id', $probe->id)->delete();

        $this->assertSame($probe->id, SectionTeacher::teacherIdFor(1, Subject::find(1)));
        $this->assertNull(SectionTeacher::teacherFor(1, Subject::find(1)));
    }

    public function test_planning_surfaces_refresh_after_override_added()
    {
        $probe = $this->makeTeacher('PROBE-R3-RF', 'probe-r3-rf@example.com');

        $before = Staffing::report();
        $rowBefore = collect($before['teachers'])->firstWhere('teacher.id', $probe->id);
        $this->assertNotContains('Mathematics', $rowBefore['subjects']);

        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => 2, 'teacher_id' => $probe->id,
        ]);

        $after = Staffing::report();
        $rowAfter = collect($after['teachers'])->firstWhere('teacher.id', $probe->id);
        $this->assertContains('Mathematics', $rowAfter['subjects']);

        // The edit matrix reflects the same override immediately.
        $this->actingAs($this->admin())
            ->get(route('sections.edit', 1))
            ->assertOk()
            ->assertSee('selected value="' . Qs::hash($probe->id) . '"', false);
    }
}
