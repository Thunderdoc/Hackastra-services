<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 40) — manual assign on a
 * PAST sitting: the asymmetry round 39 left open.
 *
 * Code under test (quoted, not assumed):
 * - Invigilation::autoAssign() SKIPS past sittings (line ~216:
 *   `if (substr((string) $sitting->exam_date, 0, 10) < $today) {
 *   $skippedPast++; continue; }`) and the auto flash names them
 *   (`'N past sitting(s) skipped — their papers already
 *   happened.'`, ExamDutyController::auto).
 * - Invigilation::assign() (the MANUAL path) has NO past-date
 *   check at all: only the clash rule, then updateOrCreate +
 *   duty bell. The auto code comments say it outright: "Manual
 *   assignment stays available for deliberate record-keeping of
 *   who actually invigilated." (same rule as past sittings, and
 *   same for on-leave teachers).
 * - Generate refuses new past schedules (`start_date`
 *   `after_or_equal:today`) and save refuses a NEW past date
 *   (round 39), so a past sitting in the DB is either a
 *   grandfathered row or a direct-DB row — fixtures here use a
 *   direct-DB past sitting, the only shape a past duty can hang
 *   off in current data.
 * - ExamDutyController::assign flash is 'Invigilator saved.' plus
 *   notes ONLY for own-subject and on-leave — there is no past
 *   note. My Duties applies NO date filter (round 27): past
 *   duties list before future ones.
 *
 * Fixtures: exam 1 (Term 1), Prep (class 1) subject 1 (English,
 * teacher Ananya Sharma, teacher@hackstra.app, id 4); Priya Nair
 * (teacher2@teacher.com, id 12) is the non-own-subject teacher.
 * Past dates are computed from Carbon::today() at run time so
 * the "past" property never rots. Each test runs inside its own
 * transaction.
 */
class OwnerExamDateSheetR40Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ananya()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function priya()
    {
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function sitting(array $over = [])
    {
        return ExamDate::create(array_merge([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2027-02-06',
            'start_time' => '09:00:00',
        ], $over));
    }

    protected function assignPost($sittingId, $teacherId)
    {
        $this->actingAs($this->admin());
        return $this->from(route('exam_duties.index', ['exam_id' => 1]))
            ->post(route('exam_duties.assign'), [
                'exam_date_id' => $sittingId,
                'teacher_id' => $teacherId,
            ]);
    }

    public function test_manual_assign_on_past_sitting_creates_duty_and_bells_silently()
    {
        $past = Carbon::today()->subDays(10)->toDateString();
        $sitting = $this->sitting(['exam_date' => $past]);

        $response = $this->assignPost($sitting->id, $this->priya()->id);
        $response->assertRedirect();
        // Exactly 'Invigilator saved.' — no past note exists on the
        // manual path (contrast the auto flash in test 2).
        $this->assertSame('Invigilator saved.', $response->getSession()->get('flash_success'));

        $this->assertDatabaseHas('exam_duties', [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $this->priya()->id,
        ]);
        // The teacher is belled about the already-over paper.
        $bell = UserNotification::where('user_id', $this->priya()->id)
            ->where('type', 'duty_assigned')
            ->where('body', 'like', '%' . $past . '%')
            ->first();
        $this->assertNotNull($bell, 'Past-duty bell must exist for the assigned teacher.');

        // My Duties lists the past duty (no date filter, round 27).
        $this->actingAs($this->priya());
        $content = $this->get(route('exam_duties.my'))->assertOk()->getContent();
        $this->assertStringContainsString($past, $content);
        $this->assertStringContainsString('English Language', $content);
    }

    public function test_auto_assign_skips_past_sitting_and_names_it_in_flash()
    {
        $past = Carbon::today()->subDays(10)->toDateString();
        $exam = Exam::create([
            'name' => 'R40 Past Only Exam',
            'term' => 99,
            'year' => '2026-2027',
            'my_class_id' => 0,
        ]);
        $sitting = $this->sitting(['exam_id' => $exam->id, 'exam_date' => $past]);

        $this->actingAs($this->admin());
        $response = $this->from(route('exam_duties.index', ['exam_id' => $exam->id]))
            ->post(route('exam_duties.auto'), ['exam_id' => $exam->id]);
        $response->assertRedirect();
        $this->assertStringContainsString(
            '1 past sitting(s) skipped — their papers already happened.',
            (string) $response->getSession()->get('flash_success')
        );
        $this->assertSame(0, ExamDuty::where('exam_date_id', $sitting->id)->count());
    }

    public function test_manual_assign_on_future_sitting_creates_normally()
    {
        $sitting = $this->sitting();

        $response = $this->assignPost($sitting->id, $this->priya()->id);
        $response->assertRedirect();
        $this->assertSame('Invigilator saved.', $response->getSession()->get('flash_success'));
        $this->assertDatabaseHas('exam_duties', [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $this->priya()->id,
        ]);
    }

    public function test_manual_assign_on_leave_teacher_warns_but_creates()
    {
        $sitting = $this->sitting();
        LeaveRequest::create([
            'user_id' => $this->priya()->id,
            'start_date' => '2027-02-05',
            'end_date' => '2027-02-07',
            'reason' => 'probe',
            'leave_type' => 'normal',
            'status' => 'approved',
        ]);
        $this->assertTrue(Invigilation::isOnApprovedLeave($this->priya()->id, '2027-02-06'));

        $response = $this->assignPost($sitting->id, $this->priya()->id);
        $response->assertRedirect();
        $this->assertStringContainsString(
            'on approved leave covering this date',
            (string) $response->getSession()->get('flash_success')
        );
        $this->assertDatabaseHas('exam_duties', [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $this->priya()->id,
        ]);
    }

    public function test_manual_assign_clash_still_refuses()
    {
        $sittingA = $this->sitting();
        [$ok] = Invigilation::assign($sittingA, (int) $this->ananya()->id, (int) $this->admin()->id);
        $this->assertTrue($ok);
        $sittingB = $this->sitting(['my_class_id' => 2, 'subject_id' => 7]);
        $this->assertTrue(Invigilation::hasClash((int) $this->ananya()->id, $sittingB));

        $response = $this->assignPost($sittingB->id, $this->ananya()->id);
        $response->assertRedirect();
        $this->assertSame(
            'That teacher already has a duty at this date and time.',
            $response->getSession()->get('flash_danger')
        );
        $this->assertSame(0, ExamDuty::where('exam_date_id', $sittingB->id)->count());
    }
}
