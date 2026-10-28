<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 6 — notification recipient audit, second
 * finding, FIXED by agent E round 8: when a duty is REPLACED
 * (assign() updateOrCreate), the old teacher's 'duty_assigned'
 * bell for that sitting is now deleted (matched on the bell body,
 * which names the sitting's class/subject/date/time — the title
 * is the same for every duty), while the new teacher is belled.
 * Same orphan class as the PTM-cancel bell fixed in round 4.
 */
class OwnerDutyBellReplacementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_replaced_duty_clears_old_teacher_bell()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $first = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $second = User::where('email', 'teacher1@teacher.com')->firstOrFail();
        $examId = \App\Models\Exam::orderBy('id')->firstOrFail()->id;

        $sitting = ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-11-05',
            'start_time' => '09:00',
        ]);

        [$okA] = Invigilation::assign($sitting, (int) $first->id, (int) $admin->id);
        $this->assertTrue($okA);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $first->id,
            'type' => 'duty_assigned',
        ]);

        [$okB] = Invigilation::assign($sitting, (int) $second->id, (int) $admin->id);
        $this->assertTrue($okB);
        // New teacher belled; duty row now points at them.
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $second->id,
            'type' => 'duty_assigned',
        ]);
        $this->assertDatabaseHas('exam_duties', [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $second->id,
        ]);

        // FIXED (E round 8): the replaced teacher's bell for this
        // sitting is cleared; bells for sittings they still hold
        // are untouched (matched on the sitting-specific body).
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $first->id,
            'type' => 'duty_assigned',
        ]);
    }

    /**
     * Round 9: the chain A→B→C. Only the current holder's bell for
     * the sitting may survive; every replaced holder's bell for
     * that sitting is gone — while a bell for a DIFFERENT sitting
     * the first teacher still holds is untouched (the cleanup is
     * body-matched, not a blanket delete of the teacher's bells).
     */
    public function test_second_replacement_clears_both_prior_holders_bells_but_keeps_other_sitting()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $first = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $second = User::where('email', 'teacher1@teacher.com')->firstOrFail();
        $third = User::where('email', 'teacher2@teacher.com')->firstOrFail();
        $examId = \App\Models\Exam::orderBy('id')->firstOrFail()->id;

        $sitting = ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-11-05',
            'start_time' => '09:00',
        ]);
        $otherSitting = ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => 1,
            'subject_id' => 2,
            'exam_date' => '2026-11-06',
            'start_time' => '09:00',
        ]);

        [$okOther] = Invigilation::assign($otherSitting, (int) $first->id, (int) $admin->id);
        $this->assertTrue($okOther);
        [$okA] = Invigilation::assign($sitting, (int) $first->id, (int) $admin->id);
        $this->assertTrue($okA);
        [$okB] = Invigilation::assign($sitting, (int) $second->id, (int) $admin->id);
        $this->assertTrue($okB);
        [$okC] = Invigilation::assign($sitting, (int) $third->id, (int) $admin->id);
        $this->assertTrue($okC);

        $body = Invigilation::dutyBellBody($sitting->fresh(['my_class', 'subject']));
        $otherBody = Invigilation::dutyBellBody($otherSitting->fresh(['my_class', 'subject']));

        // Current holder only.
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $third->id, 'type' => 'duty_assigned', 'body' => $body,
        ]);
        $this->assertDatabaseHas('exam_duties', [
            'exam_date_id' => $sitting->id, 'teacher_id' => $third->id,
        ]);
        // Both prior holders' bells for THIS sitting are gone.
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $first->id, 'type' => 'duty_assigned', 'body' => $body,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $second->id, 'type' => 'duty_assigned', 'body' => $body,
        ]);
        // But the first teacher's bell for the sitting they still
        // hold survives the cleanup.
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $first->id, 'type' => 'duty_assigned', 'body' => $otherBody,
        ]);
    }
}
