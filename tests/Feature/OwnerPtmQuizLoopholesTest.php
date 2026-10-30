<?php

namespace Tests\Feature;

use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptStart;
use App\Models\QuizQuestion;
use App\Models\StudentRecord;
use App\User;
use Tests\TestCase;

/**
 * The two loopholes reported to the owner on 2026-10-30 and
 * ordered fixed the same morning:
 *  - PTM: parents could book slots that had already ENDED, and
 *    admins had no way to delete a stale slot at all.
 *  - Quizzes: the time limit was a client-side countdown only —
 *    the server accepted a submission at any hour. Timed quizzes
 *    now stamp the start server-side and refuse late submits.
 */
class OwnerPtmQuizLoopholesTest extends TestCase
{
    protected $slotIds = [];
    protected $quizIds = [];

    protected function tearDown(): void
    {
        // Leave no trace: other suites assert exact slot counts
        // and "no such slot" states against the shared demo data.
        if ($this->slotIds) {
            PtmBooking::whereIn('ptm_slot_id', $this->slotIds)->delete();
            PtmSlot::whereIn('id', $this->slotIds)->delete();
        }
        if ($this->quizIds) {
            $attemptIds = QuizAttempt::whereIn('quiz_id', $this->quizIds)->pluck('id');
            \App\Models\QuizAnswer::whereIn('attempt_id', $attemptIds)->delete();
            QuizAttempt::whereIn('quiz_id', $this->quizIds)->delete();
            QuizAttemptStart::whereIn('quiz_id', $this->quizIds)->delete();
            QuizQuestion::whereIn('quiz_id', $this->quizIds)->delete();
            Quiz::whereIn('id', $this->quizIds)->delete();
        }
        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function parent(): User
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function aaravRecord(): StudentRecord
    {
        return StudentRecord::where('user_id', User::where('email', 'student@hackstra.app')->value('id'))
            ->where('grad', 0)->firstOrFail();
    }

    protected function makeSlot(string $date, string $from, string $to): PtmSlot
    {
        $slot = PtmSlot::create([
            'date' => $date, 'start_time' => $from, 'end_time' => $to,
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
            'section_id' => 1, 'created_by' => $this->admin()->id,
        ]);
        $this->slotIds[] = $slot->id;
        return $slot;
    }

    /* ---------------- PTM ---------------- */

    public function test_past_ptm_slot_cannot_be_booked_and_shows_ended()
    {
        $slot = $this->makeSlot(now()->subDay()->toDateString(), '09:00', '09:15');
        $child = $this->aaravRecord();

        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $child->user_id,
        ])->assertSessionHas('flash_danger');
        $this->assertSame(0, PtmBooking::where('ptm_slot_id', $slot->id)->count(),
            'An ended slot must not take a booking');

        $this->actingAs($this->parent())->get(route('ptm.index'))
            ->assertSee('Ended');
    }

    public function test_future_ptm_slot_still_books()
    {
        $slot = $this->makeSlot(now()->addDays(3)->toDateString(), '10:00', '10:15');
        $child = $this->aaravRecord();

        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $child->user_id,
        ])->assertSessionHas('flash_success');
        $this->assertSame(1, PtmBooking::where('ptm_slot_id', $slot->id)->count());
    }

    public function test_admin_deletes_unbooked_slot_but_not_a_booked_one()
    {
        $free = $this->makeSlot(now()->addDays(4)->toDateString(), '11:00', '11:15');
        $booked = $this->makeSlot(now()->addDays(5)->toDateString(), '11:00', '11:15');
        PtmBooking::create([
            'ptm_slot_id' => $booked->id, 'parent_id' => $this->parent()->id,
            'student_user_id' => $this->aaravRecord()->user_id,
        ]);

        // Teachers cannot delete slots at all.
        $this->actingAs($this->teacher())->delete(route('ptm.destroy', $free->id))
            ->assertForbidden();
        $this->assertNotNull(PtmSlot::find($free->id));

        // A booked slot is refused — cancel the booking first.
        $this->actingAs($this->admin())->delete(route('ptm.destroy', $booked->id))
            ->assertSessionHas('flash_danger');
        $this->assertNotNull(PtmSlot::find($booked->id));

        $this->actingAs($this->admin())->delete(route('ptm.destroy', $free->id))
            ->assertSessionHas('flash_success');
        $this->assertNull(PtmSlot::find($free->id));
    }

    /* ---------------- Quiz timer ---------------- */

    protected function timedQuiz(): Quiz
    {
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'title' => 'Timed Quiz ZQ', 'time_limit_minutes' => 10,
            'status' => 'published',
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id, 'question' => '2 + 2 = ?',
            'options' => ['3', '4', '5'], 'correct_index' => 1, 'marks' => 5,
        ]);
        $this->quizIds[] = $quiz->id;
        return $quiz;
    }

    public function test_quiz_submit_without_opening_is_refused()
    {
        $quiz = $this->timedQuiz();
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $question = $quiz->questions()->first();

        $this->actingAs($student)->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$question->id => 1],
        ])->assertSessionHas('flash_danger');
        $this->assertSame(0, QuizAttempt::where('quiz_id', $quiz->id)->count(),
            'A timed submit with no server start stamp must not be recorded');
    }

    public function test_quiz_submit_after_deadline_is_refused_but_in_time_scores()
    {
        $quiz = $this->timedQuiz();
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $record = $this->aaravRecord();
        $question = $quiz->questions()->first();

        // Opening the page stamps the start.
        $this->actingAs($student)->get(route('quizzes.attempt', $quiz->id))->assertOk();
        $start = QuizAttemptStart::where('quiz_id', $quiz->id)
            ->where('student_record_id', $record->id)->first();
        $this->assertNotNull($start, 'Opening a timed quiz must stamp the start server-side');

        // Reopening does not move the stamp.
        $this->actingAs($student)->get(route('quizzes.attempt', $quiz->id))->assertOk();
        $this->assertTrue($start->started_at->equalTo(
            QuizAttemptStart::find($start->id)->started_at), 'A refresh must not reset the clock');

        // In time: accepted and scored.
        $this->actingAs($student)->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$question->id => 1],
        ])->assertSessionHas('flash_success');
        $attempt = QuizAttempt::where('quiz_id', $quiz->id)->firstOrFail();
        $this->assertSame(5, $attempt->score);

        // A second student whose clock ran out is refused.
        $other = StudentRecord::where('my_class_id', 1)->where('grad', 0)
            ->where('user_id', '!=', $record->user_id)->firstOrFail();
        QuizAttemptStart::create([
            'quiz_id' => $quiz->id, 'student_record_id' => $other->id,
            'started_at' => now()->subMinutes(30),
        ]);
        $this->actingAs($other->user)->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$question->id => 1],
        ])->assertSessionHas('flash_danger');
        $this->assertSame(0, QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_record_id', $other->id)->count(),
            'A late submission must not be recorded');
    }
}
