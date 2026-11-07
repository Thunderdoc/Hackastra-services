<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Teacher Workload Analysis (owner ask 2026-10-30, from his
 * phone screenshot: "YHA WORKLOAD ANALYSIS NHI DIKH RHA" on the
 * teacher's Academics menu). Until now the only workload surface
 * was the Admin's Staffing Dashboard; a teacher had none. The
 * page shows the teacher's OWN load from the same week grid the
 * leave queue uses (Ananya on the master seed: 36 periods/week,
 * Mon/Tue/Wed 8, Thu 7, Fri 5; 9 sections led), against the
 * Settings caps (8/day, 40/week). Own data only — every other
 * role is refused.
 */
class OwnerTeacherWorkloadTest extends TestCase
{
    // Random-order repair (2026-10-31): the empty-state test
    // factory-creates a teacher. Without the trait that write
    // COMMITTED, adding a 91st teacher to the shared seed — the
    // same invigilation-pool pollution as
    // OwnerOneAdminPerSchoolTest (breaks R18/R24 pool arithmetic
    // when random order runs this file first). Roll it back.
    use DatabaseTransactions;

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_workload_page_shows_the_teachers_real_load()
    {
        $html = $this->actingAs($this->teacher())->get(route('tt.workload'))
            ->assertOk()
            ->assertSee('Workload Analysis')
            ->assertSee('Periods / Week')
            ->assertSee('Where your periods go')
            ->getContent();

        // Her real week: 36 periods, busiest days at the cap.
        // Item 16 redesign (owner-approved): same figures, tile
        // markup — since the visual-coherence round (2026-11-06)
        // the tiles are shared dashboard-stat-cards, so the total
        // rides the stat <strong> and the busiest day names the
        // day and its count in the tile value.
        $this->assertStringContainsString('<strong>36 <', $html);
        $this->assertStringContainsString('<strong>Monday <small>· 8</small></strong>', $html);
        $this->assertStringContainsString('8 of 8', $html);
        $this->assertStringContainsString('Full day', $html);
        // She leads 9 sections and teaches English.
        $this->assertStringContainsString('Sections You Lead', $html);
        $this->assertStringContainsString('English', $html);
    }

    public function test_workload_is_linked_inside_teacher_academics()
    {
        $html = $this->actingAs($this->teacher())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Workload Analysis', $html);
        $quiz = strpos($html, route('quizzes.index'));
        $work = strpos($html, route('tt.workload'));
        $whats = strpos($html, '#whatsapp-groups');
        $this->assertNotFalse($quiz);
        $this->assertNotFalse($work);
        $this->assertNotFalse($whats);
        $this->assertTrue($quiz < $work && $work < $whats, 'Workload Analysis sits between Quizzes and WhatsApp Groups in Academics');
    }

    public function test_other_roles_are_refused()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $this->actingAs($admin)->get(route('tt.workload'))->assertForbidden();

        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $this->actingAs($student)->get(route('tt.workload'))->assertForbidden();
    }

    public function test_teacher_with_no_allotment_gets_an_honest_empty_state()
    {
        $new = User::factory()->create(['user_type' => 'teacher']);
        $this->actingAs($new)->get(route('tt.workload'))
            ->assertOk()
            ->assertSee('No periods are allotted to you yet');
    }
}
