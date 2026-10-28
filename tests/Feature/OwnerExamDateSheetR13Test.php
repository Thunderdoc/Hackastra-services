<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 13) — auto-assign flash
 * RENDERING. Rounds 5–6 proved the auto-assign counts and the
 * session flash strings; rounds 10–11 proved generate/save
 * refusal flashes render as toasts on the form pages. What was
 * untested was the auto-assign flash's rendered outcome on the
 * duties page itself: the danger branch (past skipped + filled
 * despite leave + unfilled with leave note) and the plain success
 * branch must both arrive as readable toasts, and the DB must hold
 * duties only for the sittings that should be filled.
 *
 * Flash composition (ExamDutyController::auto): success =
 * "Auto-assignment complete: N sitting(s) got an invigilator."
 * plus " M past sitting(s) skipped — their papers already
 * happened." plus " K sitting(s) filled while other teachers
 * were on approved leave."; unfilled appends " Could not fill: "
 * + reasons and flips the flash to danger. Toasts render via
 * partials/js/custom_js: flash({msg : '...', type : 'danger'})
 * or type 'success'. The message carries no HTML-special
 * characters, so the exact toast call is asserted with
 * escape=false (round-10 lesson: bare assertSee on generic words
 * is vacuous).
 *
 * Dates are relative to the real today (2026-10-28): past =
 * 2026-10-20, future A = 2026-11-10, future B = 2026-11-11.
 * Exam 1 is the global Term 1 exam; class 1 is Prep; subjects
 * 1/2/3 are Prep English/Mathematics/Science. exam_dates is
 * empty on the shared DB, so inside the transaction these three
 * sittings are the whole auto-assign universe and every count
 * below is exact.
 */
class OwnerExamDateSheetR13Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function pool()
    {
        return User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->get();
    }

    protected function sitting(array $over)
    {
        return ExamDate::create(array_merge([
            'exam_id' => 1,
            'my_class_id' => 1,
            'start_time' => '09:00',
        ], $over));
    }

    protected function leave(int $userId, string $from, string $to)
    {
        return LeaveRequest::create([
            'user_id' => $userId,
            'start_date' => $from,
            'end_date' => $to,
            'reason' => 'probe',
            'leave_type' => 'normal',
            'status' => 'approved',
        ]);
    }

    public function test_auto_assign_danger_flash_renders_all_three_branches()
    {
        $pool = $this->pool();
        $this->assertNotEmpty($pool, 'No live teachers in pool — fixture broken');

        $past = $this->sitting(['subject_id' => 1, 'exam_date' => '2026-10-20']);
        $futureA = $this->sitting(['subject_id' => 2, 'exam_date' => '2026-11-10']);
        $futureB = $this->sitting(['subject_id' => 3, 'exam_date' => '2026-11-11']);

        // Two teachers on leave for future A's date only: A still
        // fills, but leave shaped its pool (filled_despite_leave).
        foreach ($pool->take(2) as $t) {
            $this->leave($t->id, '2026-11-10', '2026-11-10');
        }
        // Every pool teacher on leave for future B's date: B cannot
        // fill, and its reason must name the leave exclusion.
        foreach ($pool as $t) {
            $this->leave($t->id, '2026-11-11', '2026-11-11');
        }

        $expected = 'Auto-assignment complete: 1 sitting(s) got an invigilator.'
            . ' 1 past sitting(s) skipped — their papers already happened.'
            . ' 1 sitting(s) filled while other teachers were on approved leave.'
            . ' Could not fill: 2026-11-11 09:00 — every teacher already has a duty at this time (or no teachers exist).'
            . ' Teachers on approved leave were not assigned.';

        $response = $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas('flash_danger', $expected);

        // Rendered outcome: the duties page carries the exact toast.
        $this->get(route('exam_duties.index'))
            ->assertOk()
            ->assertSee("flash({msg : '{$expected}', type : 'danger'})", false);

        // DB proof: exactly one duty, for future A only.
        $this->assertEquals(1, ExamDuty::count());
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $futureA->id]);
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $past->id]);
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $futureB->id]);
        // Exactly one bell: the filled sitting's teacher only —
        // no bell for the past sitting, none for leave-blocked B.
        $this->assertEquals(1, UserNotification::where('type', 'duty_assigned')->count());
    }

    public function test_auto_assign_success_flash_renders_when_nothing_blocks()
    {
        $sitting = $this->sitting(['subject_id' => 2, 'exam_date' => '2026-11-10']);

        $expected = 'Auto-assignment complete: 1 sitting(s) got an invigilator.';

        $response = $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas('flash_success', $expected);

        $this->get(route('exam_duties.index'))
            ->assertOk()
            ->assertSee("flash({msg : '{$expected}', type : 'success'})", false);

        $this->assertEquals(1, ExamDuty::count());
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $sitting->id]);
    }
}
