<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent E round 15 — duties INDEX surface (row-level replacement
 * was proven in rounds 8–9 at the bell + DB level; the rendered
 * index / my-duties pages were never probed).
 *
 * The admin duties index lists one row per sitting with a pool
 * <select> in every row, so replaced holders' names DO still
 * appear on the page — as dropdown options. The lock therefore
 * counts occurrences, not mere presence: after an A→B→C chain
 * the current holder's name appears exactly twice (display cell
 * + selected option) while each replaced holder appears exactly
 * once (option only, never as the displayed holder), and the DB
 * holds exactly one duty row for the sitting.
 *
 * Fixture: exam 1, Prep (class 1), subject 1, 2026-11-05 09:00.
 * A = teacher@ (Ananya), B = teacher1@, C = teacher2@ (Priya).
 */
class OwnerExamDutiesIndexR15Test extends TestCase
{
    use DatabaseTransactions;

    protected function chain(): array
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $a = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $b = User::where('email', 'teacher1@teacher.com')->firstOrFail();
        $c = User::where('email', 'teacher2@teacher.com')->firstOrFail();
        $examId = \App\Models\Exam::orderBy('id')->firstOrFail()->id;

        $sitting = ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-11-05',
            'start_time' => '09:00',
        ]);

        [$okA] = Invigilation::assign($sitting, (int) $a->id, (int) $admin->id);
        $this->assertTrue($okA);
        [$okB] = Invigilation::assign($sitting, (int) $b->id, (int) $admin->id);
        $this->assertTrue($okB);
        [$okC] = Invigilation::assign($sitting, (int) $c->id, (int) $admin->id);
        $this->assertTrue($okC);

        return [$admin, $a, $b, $c, $examId, $sitting];
    }

    public function test_admin_index_shows_only_current_holder_after_chain()
    {
        [$admin, $a, $b, $c, $examId] = $this->chain();

        // Exactly one duty row survives the chain, pointing at C.
        $this->assertSame(1, ExamDuty::where('teacher_id', $c->id)->count());
        $this->assertSame(0, ExamDuty::where('teacher_id', $a->id)->count());
        $this->assertSame(0, ExamDuty::where('teacher_id', $b->id)->count());

        $html = $this->actingAs($admin)
            ->get(route('exam_duties.index', ['exam_id' => $examId]))
            ->assertOk()->getContent();

        // C: display cell + selected option = 2. A/B: option only = 1.
        $this->assertSame(2, substr_count($html, $c->name));
        $this->assertSame(1, substr_count($html, $a->name));
        $this->assertSame(1, substr_count($html, $b->name));
        // The selected option in the sitting's row is C.
        $this->assertMatchesRegularExpression(
            '/<option[^>]*selected[^>]*>\s*' . preg_quote($c->name, '/') . '\s*<\/option>/',
            $html
        );
    }

    public function test_teacher_my_duties_shows_only_own_duties()
    {
        [$admin, $a, $b, $c] = $this->chain();

        foreach ([$a, $b] as $former) {
            $this->actingAs($former)->get(route('exam_duties.my'))
                ->assertOk()
                ->assertSee('No invigilation duties assigned yet.');
        }

        $this->actingAs($c)->get(route('exam_duties.my'))
            ->assertOk()
            ->assertSee('2026-11-05')
            ->assertSee('English Language')
            ->assertDontSee('No invigilation duties assigned yet.');
    }

    public function test_principal_duties_index_closed_and_my_duties_redirects()
    {
        $principal = User::where('email', 'principal@hackstra.app')->firstOrFail();
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();

        // Duties index is teamSA (controller middleware): principal
        // bounces to login, exactly like exam-dates manage.
        $this->actingAs($principal)->get(route('exam_duties.index'))
            ->assertRedirect(route('login'));

        // My-duties is teacher-only by controller guard: every
        // non-teacher lands on the dashboard, not login.
        $this->actingAs($principal)->get(route('exam_duties.my'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->get(route('exam_duties.my'))
            ->assertRedirect(route('dashboard'));

        // Teachers likewise cannot use the admin index at all.
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $this->actingAs($teacher)->get(route('exam_duties.index'))
            ->assertRedirect(route('login'));
    }
}
