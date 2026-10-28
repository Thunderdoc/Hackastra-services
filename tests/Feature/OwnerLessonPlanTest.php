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

    public function test_teacher_creates_plan_and_week_start_normalizes_to_monday()
    {
        // 2026-10-28 is a Wednesday; its week's Monday is 2026-10-26.
        $response = $this->actingAs($this->teacher())->post(route('lesson_plans.store'), [
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'week_start' => '2026-10-28',
            'topic' => 'Test Monday Normalization Topic',
            'notes' => 'probe',
        ]);

        $response->assertRedirect();
        $plan = LessonPlan::where('topic', 'Test Monday Normalization Topic')->firstOrFail();
        $this->assertEquals('2026-10-26', $plan->week_start->format('Y-m-d'));
        $this->assertEquals($this->teacher()->id, $plan->teacher_id);
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

        $response = $this->actingAs($teacher)->get(route('lesson_plans.index'));
        $response->assertOk();
        $response->assertSee('Completion: 50%');
        $response->assertSee('1 of 2 topics covered');

        $covered = LessonPlan::where('topic', $taught)->firstOrFail();
        $this->actingAs($teacher)->get(route('lesson_plans.show', $covered->id))
            ->assertOk()
            ->assertSee('Covered');

        $open = LessonPlan::where('topic', 'A Topic Never Taught Anywhere ZZZ')->firstOrFail();
        $this->actingAs($teacher)->get(route('lesson_plans.show', $open->id))
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
