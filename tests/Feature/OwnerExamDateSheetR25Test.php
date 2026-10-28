<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 25) — two seams never
 * probed before:
 *
 * (a) PARENT ADMIT MY_CARD SCOPING. myCard() (AdmitCardController)
 * allows exactly: the student themself, a parent of that student
 * (Qs::userIsMyChild), or teamSA office staff — anyone else gets
 * abort(403). The bulk-print teamSA gate (round 17) and the student
 * print param-ignored rule (round 22/23 matrix) are different
 * routes; this locks the self-service card route itself. Note the
 * fee-defaulter block inside myCard: student 58 carries an owing
 * payment record, so the success cases use student 57 (Aarav, no
 * owing record) and the fee-blocked shape is deliberately NOT
 * re-probed here (it is the round-2026-10-22 ruling, unchanged).
 * my_card takes RAW ids (route param is {student_id}, not the
 * hash-bound {id}).
 *
 * (b) MANUAL ASSIGN PAST + LEAVE COMBO. Two settled rules meet:
 * manual past assignment is allowed (round 2 — autoAssign skips
 * past sittings, manual does not) and manual leave assignment
 * warns only, duty IS created (round 6). The combo must therefore
 * CREATE the duty AND carry the leave note. A refusal would
 * contradict both rules; a missing leave note would defeat the
 * warning's purpose (round 6 built it precisely so an on-leave
 * assignment never passes silently). assign() contains no past
 * check at all, and the leave note is appended unconditionally
 * after the own-subject note — so the expected flash is exactly
 * 'Invigilator saved. Note: this teacher is on approved leave
 * covering this date.' with no own-subject note (the probe
 * teacher teaches no subject in the sitting's class).
 */
class OwnerExamDateSheetR25Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function student57()
    {
        return User::findOrFail(57);
    }

    public function test_student_own_my_card_renders_own_card()
    {
        $response = $this->actingAs($this->student57())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 57]));

        $response->assertStatus(200);
        $response->assertSee('Aarav Sharma', false);
    }

    public function test_parent_my_card_for_own_child_renders_child_card()
    {
        $response = $this->actingAs($this->parent())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 57]));

        $response->assertStatus(200);
        $response->assertSee('Aarav Sharma', false);
    }

    public function test_parent_my_card_for_foreign_child_is_refused()
    {
        // Student user 100: class 1, my_parent_id NULL — truly
        // foreign to parent@ (whose children are records 1 + 2 only).
        $response = $this->actingAs($this->parent())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 100]));

        $response->assertStatus(403);
    }

    public function test_teacher_my_card_is_refused()
    {
        // Teachers are teamSA-gated on bulk print (round 17) and
        // 403 here too: myCard allows self / own child / teamSA
        // only, and a teacher is none of those for student 57.
        $response = $this->actingAs($this->teacher())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 57]));

        $response->assertStatus(403);
    }

    public function test_admin_my_card_is_allowed_as_office_use()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 57]));

        $response->assertStatus(200);
        $response->assertSee('Aarav Sharma', false);
    }

    public function test_manual_assign_past_sitting_to_on_leave_teacher_creates_duty_with_leave_note()
    {
        $probe = User::create([
            'name' => 'R25 PastLeave',
            'username' => 'R25PL',
            'email' => 'r25pastleave@teacher.com',
            'password' => 'secret',
            'code' => 'R25PL',
            'user_type' => 'teacher',
        ]);
        // Past sitting (today is 2026-10-28) + leave covering it.
        $sitting = ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-10-21',
            'start_time' => '09:00',
        ]);
        LeaveRequest::create([
            'user_id' => $probe->id,
            'start_date' => '2026-10-21',
            'end_date' => '2026-10-21',
            'reason' => 'R25 past leave',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.assign'), [
                'exam_date_id' => $sitting->id,
                'teacher_id' => $probe->id,
            ]);

        $response->assertRedirect();
        $this->assertSame(
            'Invigilator saved. Note: this teacher is on approved leave covering this date.',
            $response->getSession()->get('flash_success')
        );
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)
            ->where('teacher_id', $probe->id)->count());
    }
}
