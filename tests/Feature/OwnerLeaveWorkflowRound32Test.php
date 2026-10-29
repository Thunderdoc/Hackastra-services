<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Models\UserNotification;
use App\Support\LeaveCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roles audit round 32 — end-to-end LEAVE lifecycle workflow (P15
 * chain probe: per-link guards were proven separately, never as one
 * chain). One continuous chain through the REAL routes:
 * student apply -> teacher queue -> teacher approve (no periods, so
 * no cover) -> manual cover -> uncovered count recomputes -> admin
 * approval triggers the system's auto-cover for the rest
 * (owner order 2026-10-29);
 * staff emergency auto-approves + bells admins AND principals;
 * self-decision bans; wrong-queue decisions; parent/accountant/
 * librarian negatives; re-decision refused.
 *
 * Premise correction locked in test 1 (P5/P10): a STUDENT leave row
 * in the teacher queue carries an allocation line ("Prep — A") but
 * uncovered_periods NULL by design — cover is owed by TEACHERS,
 * not by the absent student (attachCoverInfo only counts teachers).
 * The count-vs-rows leg therefore runs on the TEACHER leave in
 * test 2, where the count is real and must drop by exactly 1 after
 * one substitution is arranged.
 */
class OwnerLeaveWorkflowRound32Test extends TestCase
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

    protected function teacher2()
    {
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function freshUser(string $tag, string $type): User
    {
        return User::create([
            'name' => 'R32 ' . $tag,
            'email' => 'r32' . $tag . '@example.com',
            'password' => bcrypt('password'),
            'code' => 'R32' . strtoupper($tag),
            'user_type' => $type,
            'status' => 'active',
        ]);
    }

    protected function queueRow($response, int $leaveId)
    {
        $requests = $response->viewData('requests');
        $this->assertNotNull($requests, 'queue view data missing');
        foreach ($requests as $row) {
            if ((int) $row->id === $leaveId) {
                return $row;
            }
        }
        $this->fail('leave ' . $leaveId . ' not listed in queue');
    }

    protected function ananyaMondayCell(): TimeTable
    {
        $overrides = [];
        foreach (DB::table('section_subject_teachers')->get() as $row) {
            $overrides[$row->section_id . '|' . $row->subject_id] = (int) $row->teacher_id;
        }
        $cells = TimeTable::with(['subject', 'tt_record'])
            ->where('day', 'Monday')->whereNotNull('subject_id')->orderBy('id')->get();
        foreach ($cells as $cell) {
            $sectionId = optional($cell->tt_record)->section_id;
            $effective = ($sectionId && isset($overrides[$sectionId . '|' . $cell->subject_id]))
                ? $overrides[$sectionId . '|' . $cell->subject_id]
                : ($cell->subject ? (int) $cell->subject->teacher_id : null);
            if ($effective === (int) $this->teacher()->id) {
                return $cell;
            }
        }
        $this->fail('No Monday cell with effective teacher Ananya found');
    }

    public function test_student_normal_leave_chain_teacher_approves_no_auto_cover()
    {
        $student = $this->student();
        $response = $this->actingAs($student)->post(route('leave.store'), [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'R32 student chain',
            'leave_type' => 'normal',
        ]);
        $response->assertRedirect();
        $leave = LeaveRequest::where('user_id', $student->id)
            ->where('reason', 'R32 student chain')->firstOrFail();
        $this->assertSame('pending', $leave->status);

        // Teacher queue (P5 list leg): listed, allocation line present,
        // uncovered NULL by design for a student row (cover is owed by
        // teachers, not by the absent student).
        $queue = $this->actingAs($this->teacher())->get(route('leave.student_requests'));
        $queue->assertOk();
        $row = $this->queueRow($queue, $leave->id);
        $this->assertContains('Prep — A', $row->allocation_lines);
        $this->assertNull($row->uncovered_periods);

        // Section teacher approves; student sees approved on My Leave.
        $decide = $this->actingAs($this->teacher())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
            'decision_note' => 'R32 approved',
        ]);
        $decide->assertRedirect();
        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame((int) $this->teacher()->id, (int) $leave->decided_by);

        $mine = $this->actingAs($student)->get(route('leave.my'));
        $mine->assertOk();
        $mine->assertSee('approved');

        // A student has no periods, so even after the 2026-10-29
        // auto-cover order an approval arranges nothing for them.
        $this->assertSame(0, Substitution::count());
    }

    public function test_teacher_leave_cover_recomputes_uncovered_count()
    {
        $teacher = $this->teacher();
        // Baseline BEFORE the submit: with no cover rows at all,
        // every Monday period Ananya teaches counts as uncovered.
        $total = LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02');
        $this->assertNotNull($total);
        $this->assertGreaterThan(0, $total);

        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'R32 teacher chain',
            'leave_type' => 'normal',
        ]);
        $leave = LeaveRequest::where('user_id', $teacher->id)
            ->where('reason', 'R32 teacher chain')->firstOrFail();
        $this->assertSame('pending', $leave->status);

        // Owner order 2026-10-29 (night) supersedes this test's
        // original "pending = nothing arranged" premise: the system
        // prepares the cover plan AT SUBMIT, so the queue already
        // shows the allocation. P10 still holds — the queue count
        // EQUALS the live helper count (no stale number, no drift),
        // and planned + uncovered balance against the baseline.
        $expected = LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02');
        $this->assertNotNull($expected);
        $queue = $this->actingAs($this->admin())->get(route('leave.index'));
        $queue->assertOk();
        $row = $this->queueRow($queue, $leave->id);
        $this->assertSame($expected, $row->uncovered_periods);
        $this->assertNotEmpty($row->allocation_lines);
        $this->assertSame($total - $expected, (int) $row->planned_periods);
        $this->assertGreaterThan(0, (int) $row->planned_periods);

        // Admin adjustment BEFORE approval still works: the admin
        // switches the planned substitute on one Monday period to a
        // teacher of their own choosing (the plan row is pending,
        // so the switch is the board's pre-approval edit path).
        $cell = $this->ananyaMondayCell();
        $substitute = $this->freshUser('sub', 'teacher');
        $planRow = Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', '2026-11-02')
            ->where('absent_teacher_id', $teacher->id)->firstOrFail();
        $this->assertSame('pending', $planRow->status);
        $switch = $this->actingAs($this->admin())->post(route('substitutions.switch', $planRow->id), [
            'substitute_teacher_id' => $substitute->id,
        ]);
        $switch->assertRedirect();
        $sub = Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();
        $this->assertSame((int) $teacher->id, (int) $sub->absent_teacher_id);
        $this->assertSame((int) $substitute->id, (int) $sub->substitute_teacher_id);
        // A switch inside an unsubmitted plan rings no bell yet —
        // the substitute is belled only when the plan is submitted.
        $this->assertFalse(UserNotification::where('user_id', $substitute->id)
            ->where('type', 'substitution_assigned')->exists());

        // P10 again: the queue count RECOMPUTES from the live data —
        // a switch changes WHO covers, not how much is covered, so
        // the count still equals the helper exactly.
        $afterManual = LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02');
        $this->assertSame($expected, $afterManual);
        $queue2 = $this->actingAs($this->admin())->get(route('leave.index'));
        $row2 = $this->queueRow($queue2, $leave->id);
        $this->assertSame($afterManual, $row2->uncovered_periods);
        $this->assertSame($total - $expected, (int) $row2->planned_periods);

        // Owner order 2026-10-29 (auto-cover, supersedes this test's
        // original "approving creates NO substitutions" premise):
        // the plan was prepared at submit; approving reconciles it
        // (fills any gap, drops stale picks, duplicates nothing).
        // The books must balance exactly — every
        // period is either covered by a row or still counted
        // uncovered — and the manual row is neither duplicated nor
        // replaced.
        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ])->assertRedirect();
        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame((int) $this->admin()->id, (int) $leave->decided_by);
        $rowsAfter = Substitution::where('absent_teacher_id', $teacher->id)->count();
        $uncoveredAfter = LeaveCover::uncoveredCount($teacher->id, '2026-11-02', '2026-11-02');
        $this->assertSame($total, $rowsAfter + $uncoveredAfter);
        $manualRow = Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();
        $this->assertSame((int) $substitute->id, (int) $manualRow->substitute_teacher_id);
        $this->assertSame('Auto-arranged (leave)', $manualRow->reason);
        if ($rowsAfter > 1) {
            $this->assertTrue(Substitution::where('absent_teacher_id', $teacher->id)
                ->where('reason', 'Auto-arranged (leave)')->exists());
        }
    }

    public function test_staff_emergency_auto_approves_and_bells_admins_and_principals()
    {
        $admin = $this->freshUser('eadm', 'admin');
        $principal = $this->freshUser('eprin', 'principal');
        $teacher = $this->freshUser('eteach', 'teacher');

        $response = $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-17',
            'reason' => 'R32 emergency chain',
            'leave_type' => 'emergency',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('flash_success', 'Emergency leave auto-approved.');
        $leave = LeaveRequest::where('user_id', $teacher->id)
            ->where('reason', 'R32 emergency chain')->firstOrFail();
        $this->assertSame('approved', $leave->status);
        $this->assertNull($leave->decided_by);
        $this->assertSame('Auto-approved (emergency).', $leave->decision_note);
        $this->assertNotNull($leave->decided_at);

        // Bells to ALL admins AND principals — the fresh pair each got
        // exactly one (seeded admins/principals also belled, but the
        // fresh pair isolates this submission's bells).
        $this->assertSame(1, UserNotification::where('user_id', $admin->id)
            ->where('type', 'leave_emergency')->count());
        $this->assertSame(1, UserNotification::where('user_id', $principal->id)
            ->where('type', 'leave_emergency')->count());
        $this->assertSame(0, UserNotification::where('user_id', $teacher->id)
            ->where('type', 'leave_emergency')->count());
    }

    public function test_student_emergency_bells_section_teacher_only()
    {
        $admin = $this->freshUser('sadm', 'admin');
        $before = UserNotification::where('user_id', $this->teacher()->id)
            ->where('type', 'leave_emergency')->count();

        $this->actingAs($this->student())->post(route('leave.store'), [
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-16',
            'reason' => 'R32 student emergency',
            'leave_type' => 'emergency',
        ])->assertRedirect();
        $leave = LeaveRequest::where('user_id', $this->student()->id)
            ->where('reason', 'R32 student emergency')->firstOrFail();
        $this->assertSame('approved', $leave->status);
        $this->assertSame('Auto-approved (emergency).', $leave->decision_note);

        // Section teacher (Ananya, Prep A) belled exactly once more;
        // admins are NOT belled for a student emergency.
        $this->assertSame($before + 1, UserNotification::where('user_id', $this->teacher()->id)
            ->where('type', 'leave_emergency')->count());
        $this->assertSame(0, UserNotification::where('user_id', $admin->id)
            ->where('type', 'leave_emergency')->count());
    }

    public function test_principal_self_decision_banned_then_admin_decides()
    {
        $principal = $this->freshUser('pleave', 'principal');
        $this->actingAs($principal)->post(route('leave.store'), [
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-19',
            'reason' => 'R32 principal chain',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $leave = LeaveRequest::where('user_id', $principal->id)
            ->where('reason', 'R32 principal chain')->firstOrFail();
        $this->assertSame('pending', $leave->status);

        // Principal attempts to decide own leave: refused, stays pending.
        $self = $this->actingAs($principal)->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ]);
        $self->assertRedirect();
        $self->assertSessionHas('flash_danger', 'You cannot decide your own leave request — another admin must decide it.');
        $leave->refresh();
        $this->assertSame('pending', $leave->status);
        $this->assertNull($leave->decided_by);

        // Admin decides it: approved, decided_by = admin.
        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ])->assertRedirect();
        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame((int) $this->admin()->id, (int) $leave->decided_by);
    }

    public function test_teacher_wrong_queue_decisions_refused_admin_decides_student_leave()
    {
        // (a) Teacher deciding a colleague's STAFF leave: bounced to
        // dashboard (decide allows teachers ONLY for student leaves
        // of sections they teach).
        $colleague = $this->freshUser('col', 'teacher');
        $this->actingAs($colleague)->post(route('leave.store'), [
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'reason' => 'R32 colleague leave',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $staffLeave = LeaveRequest::where('user_id', $colleague->id)
            ->where('reason', 'R32 colleague leave')->firstOrFail();
        $wrong = $this->actingAs($this->teacher())->post(route('leave.decide', $staffLeave->id), [
            'status' => 'approved',
        ]);
        $wrong->assertRedirect(route('dashboard'));
        $staffLeave->refresh();
        $this->assertSame('pending', $staffLeave->status);

        // (b) Teacher deciding a student leave for a section she does
        // NOT lead (Priya vs Prep A): bounced to dashboard.
        $studentLeave = LeaveRequest::create([
            'user_id' => $this->student()->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'R32 foreign student leave',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $foreign = $this->actingAs($this->teacher2())->post(route('leave.decide', $studentLeave->id), [
            'status' => 'approved',
        ]);
        $foreign->assertRedirect(route('dashboard'));
        $studentLeave->refresh();
        $this->assertSame('pending', $studentLeave->status);

        // (c) Positive control: admin decides a student leave fine.
        $this->actingAs($this->admin())->post(route('leave.decide', $studentLeave->id), [
            'status' => 'approved',
        ])->assertRedirect();
        $studentLeave->refresh();
        $this->assertSame('approved', $studentLeave->status);
        $this->assertSame((int) $this->admin()->id, (int) $studentLeave->decided_by);
    }

    public function test_parent_cannot_apply_for_leave()
    {
        $response = $this->actingAs($this->parent())->post(route('leave.store'), [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'R32 parent probe',
            'leave_type' => 'normal',
        ]);
        $response->assertRedirect(route('dashboard'));
        $this->assertFalse(LeaveRequest::where('reason', 'R32 parent probe')->exists());

        $this->actingAs($this->parent())->get(route('leave.my'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_librarian_queue_closed_but_own_leave_open()
    {
        // P6: leave.index middleware (teamOrPrincipal:SAWrite) bounces
        // accountant/librarian to LOGIN, not to dashboard.
        $this->actingAs($this->accountant())->get(route('leave.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->librarian())->get(route('leave.index'))
            ->assertRedirect(route('login'));

        // Their own leave pages still work (round 19/20 shape).
        $this->actingAs($this->accountant())->get(route('leave.my'))->assertOk();
        $this->actingAs($this->librarian())->get(route('leave.my'))->assertOk();
    }

    public function test_decided_leave_cannot_be_redecided()
    {
        $leave = LeaveRequest::create([
            'user_id' => $this->student()->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'R32 redecide probe',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $this->actingAs($this->teacher())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ])->assertRedirect();
        $leave->refresh();
        $this->assertSame('approved', $leave->status);

        $again = $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'rejected',
        ]);
        $again->assertRedirect();
        $again->assertSessionHas('flash_danger', 'This request has already been decided.');
        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame((int) $this->teacher()->id, (int) $leave->decided_by);
    }
}
