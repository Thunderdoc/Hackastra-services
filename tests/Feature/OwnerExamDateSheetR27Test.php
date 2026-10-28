<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 27) — two invigilation
 * seams never probed before:
 *
 * (a) AUTO-ASSIGN WITH THE ONLY ELIGIBLE TEACHER ON LEAVE.
 * Round 24 proved the partial-leave boundary (Mon in-leave
 * skipped, Thu out-of-leave filled) with the WHOLE pool on leave
 * for the skipped sitting. This round sharpens it: the pool is
 * reduced to exactly ONE teacher (every seeded pool teacher is
 * suspended, which removes them from Invigilation::pool() — the
 * "otherwise excluded" isolation, stated explicitly), and THAT
 * teacher is on approved leave covering the sitting date. The
 * sitting must stay empty AND the flash must still carry the
 * leave note — the note must fire when exactly one pool member
 * is leave-excluded, not only when the whole pool is. A control
 * (same isolation, probe NOT on leave) proves the suspension
 * isolation itself does not break auto-assign.
 *
 * (b) MY-DUTIES PAST-DUTY VISIBILITY. Code read first:
 * ExamDutyController::myDuties() applies NO date filter — it
 * lists every duty for the teacher, sorted ascending by
 * date + start time — and the my_duties blade has no Past /
 * Upcoming sectioning at all (headers are only Date / Time /
 * Exam / Class / Subject). So the expected shape is: the past
 * duty LISTS, before the future one, distinguished by its date
 * cell alone. A past duty silently vanishing would be an
 * observation; a past duty rendered with no date distinction
 * would be a REAL finding. Neither happens — the date column
 * plus ascending order IS the distinction, and the test locks
 * exactly that.
 *
 * Dates are relative to the real today (2026-10-28): the target
 * sitting 2026-11-09 (Mon) and the past duty 2026-10-21 are on
 * opposite sides of today, so the past-skip branch (round 2)
 * cannot interfere with (a), and (b) needs no auto-assign at
 * all (duties created directly — manual past assignment is
 * allowed by the round-2 rule).
 */
class OwnerExamDateSheetR27Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function seededPool()
    {
        return User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->get();
    }

    protected function probeTeacher(string $suffix)
    {
        return User::create([
            'name' => 'R27 ' . $suffix,
            'username' => 'R27' . strtoupper($suffix),
            'email' => 'r27' . strtolower($suffix) . '@teacher.com',
            'password' => 'secret',
            'code' => 'R27' . strtoupper($suffix),
            'user_type' => 'teacher',
        ]);
    }

    /** Suspend every seeded pool teacher; returns the pool ids. */
    protected function suspendSeededPool(): array
    {
        $ids = $this->seededPool()->pluck('id')->all();
        DB::table('users')->whereIn('id', $ids)->update(['status' => 'suspended']);

        return $ids;
    }

    public function test_auto_assign_single_candidate_on_leave_fills_nothing_and_names_leave()
    {
        // Isolation, stated exactly: every seeded pool teacher is
        // suspended, so pool() admits nobody but the fresh probe.
        $this->suspendSeededPool();

        $probe = $this->probeTeacher('Single');
        LeaveRequest::create([
            'user_id' => $probe->id,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'reason' => 'R27 probe single-candidate leave',
            'status' => 'approved',
        ]);

        // The pool is exactly the probe, and the probe is on leave
        // for the sitting date.
        $this->assertSame([$probe->id], Invigilation::pool()->pluck('id')->all());
        $this->assertTrue(Invigilation::isOnApprovedLeave($probe->id, '2026-11-09'));

        $sitting = ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-11-09',
            'start_time' => '09:00',
        ]);

        $response = $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas('flash_danger',
            'Auto-assignment complete: 0 sitting(s) got an invigilator.'
            . ' Could not fill: 2026-11-09 09:00 — every teacher already has a duty at this time (or no teachers exist).'
            . ' Teachers on approved leave were not assigned.');

        // Nothing filled, and the leave-excluded teacher is never
        // belled about a duty she did not get.
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $sitting->id]);
        $this->assertSame(0, UserNotification::where('user_id', $probe->id)
            ->where('type', 'duty_assigned')->count());
    }

    public function test_auto_assign_single_candidate_without_leave_is_filled()
    {
        // Same isolation as the leave test, minus the leave: the
        // single candidate must be fillable, proving suspension
        // of the rest of the pool does not break auto-assign.
        $this->suspendSeededPool();

        $probe = $this->probeTeacher('Control');
        $this->assertSame([$probe->id], Invigilation::pool()->pluck('id')->all());
        $this->assertFalse(Invigilation::isOnApprovedLeave($probe->id, '2026-11-09'));

        $sitting = ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-11-09',
            'start_time' => '09:00',
        ]);

        $response = $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas('flash_success',
            'Auto-assignment complete: 1 sitting(s) got an invigilator.');

        $this->assertDatabaseHas('exam_duties', [
            'exam_date_id' => $sitting->id,
            'teacher_id' => $probe->id,
        ]);
        $this->assertSame(1, UserNotification::where('user_id', $probe->id)
            ->where('type', 'duty_assigned')->count());
    }

    public function test_my_duties_lists_past_duty_chronologically_with_no_section_split()
    {
        $teacher = $this->probeTeacher('Duties');
        $past = ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-10-21',
            'start_time' => '09:00',
        ]);
        $future = ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 2,
            'exam_date' => '2026-11-09',
            'start_time' => '09:00',
        ]);
        ExamDuty::create([
            'exam_date_id' => $past->id,
            'teacher_id' => $teacher->id,
            'assigned_by' => $this->admin()->id,
        ]);
        ExamDuty::create([
            'exam_date_id' => $future->id,
            'teacher_id' => $teacher->id,
            'assigned_by' => $this->admin()->id,
        ]);

        $response = $this->actingAs($teacher)->get(route('exam_duties.my'));
        $response->assertStatus(200);
        $html = $response->getContent();

        // Both duties list, past first (ascending sort), each
        // distinguished by its own date cell.
        $this->assertStringContainsString('<td>2026-10-21</td>', $html);
        $this->assertStringContainsString('<td>2026-11-09</td>', $html);
        $this->assertLessThan(
            strpos($html, '<td>2026-11-09</td>'),
            strpos($html, '<td>2026-10-21</td>')
        );

        // No Past / Upcoming sectioning: the tbody holds only
        // duty rows (2), with no section labels at all.
        preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $matches);
        $tbody = $matches[1] ?? '';
        $this->assertSame(2, substr_count($tbody, '<tr>'));
        $this->assertStringNotContainsString('Past', $tbody);
        $this->assertStringNotContainsString('Upcoming', $tbody);

        // Unrelated teacher sees neither duty.
        $other = $this->probeTeacher('Other');
        $otherResponse = $this->actingAs($other)->get(route('exam_duties.my'));
        $otherResponse->assertStatus(200);
        $otherResponse->assertSee('No invigilation duties assigned yet.', false);
        $this->assertStringNotContainsString('2026-10-21', $otherResponse->getContent());
        $this->assertStringNotContainsString('2026-11-09', $otherResponse->getContent());
    }
}
