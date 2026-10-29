<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Overnight batch item 1 (S1-LEAVE): emergency leave must never
 * 500, and one bad cover/allocation row must never 500 the queue.
 *
 * Adoption of Nova-chat commit a5e6b05, adapted to this tree:
 * - the emergency bell block in store() is notification-only, so
 *   any bell failure is swallowed and the already-created,
 *   auto-approved leave still redirects with its success flash
 *   (previously the user saw a 500 and could retry into a
 *   duplicate);
 * - attachCoverInfo() degrades a single bad row to dashes
 *   (allocation_lines = [], uncovered_periods = null, which the
 *   queue blade already renders) instead of 500ing the queue.
 *
 * Failing-first design: the bell-failure test breaks the bell
 * write path itself (dropping the link column on the disposable
 * snapshot DB, restored in finally), and the queue test plants a
 * teacher leave whose dates cannot be parsed by the cover
 * computation. Both fail with a 500 before the fix.
 */
class OwnerOvernightLeaveEmergencyTest extends TestCase
{
    use DatabaseTransactions;

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_emergency_leave_auto_approves_even_when_bell_write_fails()
    {
        // Break the bell write path only: Notify::toUser checks
        // the table exists, then inserts — a missing column makes
        // that insert throw. Snapshot DB only; restored below.
        Schema::table('user_notifications', function ($table) {
            $table->dropColumn('link');
        });
        try {
            $this->actingAs($this->accountant())->post(route('leave.store'), [
                'start_date' => '2026-11-16',
                'end_date' => '2026-11-16',
                'reason' => 'Overnight emergency bell-failure probe',
                'leave_type' => 'emergency',
            ])->assertRedirect();
        } finally {
            Schema::table('user_notifications', function ($table) {
                $table->string('link')->nullable();
            });
        }

        // The leave itself was created and auto-approved despite
        // the bell failure — no 500, no retry-into-duplicate trap.
        $row = LeaveRequest::where('reason', 'Overnight emergency bell-failure probe')->firstOrFail();
        $this->assertSame('approved', $row->status);
        $this->assertSame('emergency', $row->leave_type);
        $this->assertNotNull($row->decided_at);
        $this->assertNull($row->decided_by);
        $this->assertSame(1, LeaveRequest::where('reason', 'Overnight emergency bell-failure probe')->count());
    }

    public function test_leave_queue_survives_one_bad_cover_row()
    {
        // A teacher leave whose dates the cover computation cannot
        // parse (planted directly, bypassing form validation).
        // Pre-fix, uncoveredCount() throws and the whole queue 500s.
        LeaveRequest::create([
            'user_id' => $this->teacher()->id,
            'start_date' => 'not-a-date',
            'end_date' => 'not-a-date',
            'reason' => 'Overnight bad cover row',
            'leave_type' => 'normal',
            'status' => 'approved',
        ]);
        LeaveRequest::create([
            'user_id' => $this->accountant()->id,
            'start_date' => '2026-11-17',
            'end_date' => '2026-11-17',
            'reason' => 'Overnight good queue row',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin())
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Overnight good queue row');
    }

    public function test_emergency_leave_auto_approves_with_bell_on_happy_path()
    {
        $this->actingAs($this->accountant())->post(route('leave.store'), [
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-18',
            'reason' => 'Overnight emergency happy path',
            'leave_type' => 'emergency',
        ])->assertRedirect();

        $row = LeaveRequest::where('reason', 'Overnight emergency happy path')->firstOrFail();
        $this->assertSame('approved', $row->status);
        $this->assertSame('emergency', $row->leave_type);
        $this->assertNotNull($row->decided_at);
        $this->assertNull($row->decided_by);
        $this->assertStringContainsString('Auto-approved', $row->decision_note);

        // Success behaviour unchanged: staff emergency still bells
        // every would-be decider (admins AND principal).
        $this->assertTrue(UserNotification::where('user_id', $this->admin()->id)
            ->where('type', 'leave_emergency')->exists());
        $this->assertTrue(UserNotification::where('user_id', $this->principal()->id)
            ->where('type', 'leave_emergency')->exists());
    }

    public function test_leave_queue_renders_good_rows()
    {
        LeaveRequest::create([
            'user_id' => $this->accountant()->id,
            'start_date' => '2026-11-19',
            'end_date' => '2026-11-19',
            'reason' => 'Overnight queue good row',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin())
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Overnight queue good row');
    }
}
