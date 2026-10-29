<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\LeaveCover;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Leave allocation visibility (roles agent, owner question
 * 2026-10-28): the queue must show what an absence costs — the
 * applicant's allocated classes, and how many of a teacher's
 * periods inside the leave dates still have no substitute.
 * Approving a leave must NOT silently create cover: that stays
 * a manual, owner-decided step on the substitutions board.
 */
class OwnerLeaveAllocationTest extends TestCase
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

    protected function makeLeave($userId, $start, $end, $status = 'pending')
    {
        return LeaveRequest::create([
            'user_id' => $userId,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'probe',
            'leave_type' => 'normal',
            'status' => $status,
        ]);
    }

    /**
     * NOTE (scope correction 2026-10-28): the controller wiring
     * that attaches these lines to the queue rows is a PROPOSAL
     * for the parent (it edits LeaveRequestController, which the
     * parent is rewriting for the principal role). These tests
     * therefore cover the helper itself; the rendered-queue
     * assertions live in the proposal and re-enable once the
     * wiring lands.
     */
    public function test_allocation_lines_for_teacher()
    {
        $lines = LeaveCover::allocationLines($this->ananya());
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('Class teacher:', $joined);
        $this->assertStringContainsString('Prep', $joined);
        $this->assertStringContainsString('Subjects:', $joined);
        $this->assertSame(8, LeaveCover::uncoveredCount($this->ananya()->id, '2026-11-02', '2026-11-02'));
    }

    public function test_uncovered_count_drops_to_zero_when_covered()
    {
        $teacher = $this->ananya();
        $query = TimeTable::query()->select('id', 'day');
        SectionTeacher::applyTeacherFilter($query, $teacher->id);
        $mondayIds = $query->get()->where('day', 'Monday')->pluck('id');
        $this->assertNotEmpty($mondayIds, 'Ananya must have Monday periods for the covered case');

        $substitute = User::where('email', 'teacher2@teacher.com')->firstOrFail();
        foreach ($mondayIds as $ttId) {
            Substitution::create([
                'time_table_id' => $ttId,
                'date' => '2026-11-02',
                'absent_teacher_id' => $teacher->id,
                'substitute_teacher_id' => $substitute->id,
                'created_by' => $this->admin()->id,
                'reason' => 'probe cover',
            ]);
        }

        $this->assertSame(0, LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02'));
    }

    public function test_allocation_lines_for_student()
    {
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();

        $this->assertSame(['Prep — A'], LeaveCover::allocationLines($student));
    }

    public function test_queue_page_renders_with_new_columns()
    {
        // The blade carries the columns already; without the
        // controller wiring the cells degrade to dashes.
        $response = $this->actingAs($this->admin())->get(route('leave.index'));
        $response->assertOk();
        $response->assertSee('Allocated Classes');
        $response->assertSee('Uncovered Periods');
    }

    public function test_non_teaching_staff_have_no_allocation_lines()
    {
        $accountant = User::where('email', 'accountant@hackstra.app')->firstOrFail();

        $this->assertSame([], LeaveCover::allocationLines($accountant));
    }

    public function test_approve_auto_creates_substitutions()
    {
        // Owner order 2026-10-29 supersedes this test's original
        // premise ("approve does not auto-create"): approval now
        // makes the SYSTEM arrange the cover itself. Ananya has 8
        // Monday periods (pinned above), and the seeded pool covers
        // all of them.
        $teacher = $this->ananya();
        $leave = $this->makeLeave($teacher->id, '2026-11-02', '2026-11-02');

        $response = $this->from(route('leave.index'))->actingAs($this->admin())
            ->post(route('leave.decide', $leave->id), ['status' => 'approved', 'decision_note' => 'probe']);
        $response->assertRedirect(route('leave.index'));
        $this->assertSame('approved', $leave->fresh()->status);
        $rows = Substitution::where('absent_teacher_id', $teacher->id)
            ->whereDate('date', '2026-11-02')->get();
        $this->assertSame(8, $rows->count());
        $this->assertSame(0, LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02'));
        foreach ($rows as $row) {
            $this->assertSame('Auto-arranged (leave)', $row->reason);
            $this->assertSame((int) $this->admin()->id, (int) $row->created_by);
        }
    }
}
