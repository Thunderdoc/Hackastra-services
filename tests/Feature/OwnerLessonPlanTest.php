<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\LessonPlan;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Lesson planner / syllabus tracker (Wave 1 feature 2).
 * Completion % is computed live from REAL seeded attendance
 * sessions — never from invented taught data.
 */
class OwnerLessonPlanTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function otherTeacher()
    {
        return User::where('user_type', 'teacher')
            ->where('email', '!=', 'teacher@hackstra.app')
            ->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_lesson_plan_create_refused_for_everyone_after_full_removal()
    {
        // Overnight menus 2026-10-29, item 17: lesson plans are
        // FULLY removed for teachers, and create/store refuse
        // everyone (only teachers could write) — the old Monday
        // normalization create path is retired, not handed to
        // another role. Both the teacher and the admin POST are
        // refused and write nothing.
        $payload = [
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'week_start' => '2026-10-28',
            'topic' => 'Test Monday Normalization Topic',
            'notes' => 'probe',
        ];

        $this->actingAs($this->teacher())->post(route('lesson_plans.store'), $payload)
            ->assertForbidden();
        $this->actingAs($this->admin())->post(route('lesson_plans.store'), $payload)
            ->assertForbidden();
        $this->assertDatabaseMissing('lesson_plans', ['topic' => 'Test Monday Normalization Topic']);
    }

    public function test_completion_percent_from_real_seeded_attendance_data()
    {
        // Real seeded session for Prep (class 1) English (subject 1):
        // read the actual taught topic from the database, never invented.
        $taught = AttendanceSession::where('my_class_id', 1)
            ->where('subject_id', 1)
            ->whereNotNull('topic')
            ->value('topic');
        $this->assertNotEmpty($taught, 'Seeded attendance must carry a topic for class 1 subject 1');

        $teacher = $this->teacher();
        LessonPlan::create([
            'teacher_id' => $teacher->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'week_start' => '2026-10-26',
            'topic' => $taught,
        ]);
        LessonPlan::create([
            'teacher_id' => $teacher->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'week_start' => '2026-10-26',
            'topic' => 'A Topic Never Taught Anywhere ZZZ',
        ]);

        // Overnight menus 2026-10-29, item 17: teacher reads are
        // gone — the completion math is now read through the
        // admin index/show (unchanged computation, new reader).
        $this->actingAs($teacher)->get(route('lesson_plans.index'))->assertForbidden();
        $this->actingAs($teacher)->get(route('lesson_plans.show', LessonPlan::where('topic', $taught)->firstOrFail()->id))
            ->assertForbidden();

        $response = $this->actingAs($this->admin())->get(route('lesson_plans.index'));
        $response->assertOk();
        $response->assertSee('Completion: 50%');
        $response->assertSee('1 of 2 topics covered');

        $covered = LessonPlan::where('topic', $taught)->firstOrFail();
        $this->actingAs($this->admin())->get(route('lesson_plans.show', $covered->id))
            ->assertOk()
            ->assertSee('Covered');

        $open = LessonPlan::where('topic', 'A Topic Never Taught Anywhere ZZZ')->firstOrFail();
        $this->actingAs($this->admin())->get(route('lesson_plans.show', $open->id))
            ->assertOk()
            ->assertSee('Not covered yet');
    }

    public function test_foreign_teacher_cannot_delete_another_teachers_plan()
    {
        $plan = LessonPlan::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'week_start' => '2026-10-26',
            'topic' => 'Foreign Delete Probe Topic',
        ]);

        $this->actingAs($this->otherTeacher())
            ->delete(route('lesson_plans.destroy', $plan->id))
            ->assertForbidden();

        $this->assertDatabaseHas('lesson_plans', ['id' => $plan->id]);
    }

    public function test_admin_can_view_plans()
    {
        $plan = LessonPlan::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'week_start' => '2026-10-26',
            'topic' => 'Admin View Probe Topic',
        ]);

        $this->actingAs($this->admin())->get(route('lesson_plans.index'))
            ->assertOk()
            ->assertSee('Admin View Probe Topic');

        $this->actingAs($this->admin())->get(route('lesson_plans.show', $plan->id))
            ->assertOk()
            ->assertSee('Admin View Probe Topic');
    }
}
