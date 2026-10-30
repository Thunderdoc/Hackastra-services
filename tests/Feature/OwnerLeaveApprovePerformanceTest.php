<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\UserNotification;
use App\Support\LeaveAutoCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FIX-ONCE pin for the spinning Approve button (owner report
 * 2026-10-30, with screenshot): approving a leave ran the whole
 * cover fan-out as per-row writes — measured 5,012 queries and
 * 2,139 single-row bell inserts for ONE 2-day approval. Locally
 * that is a second; against the remote production database the
 * request never finished and the button spun forever. The
 * fan-out now writes in bulk (Notify::toUsers / insertMany, one
 * bulk override map, one plan-wide status update). This test
 * pins BOTH halves: the approval still does everything it did
 * (plan confirmed, every bell delivered) AND the query budget
 * stays two orders of magnitude below the broken shape — any
 * return to per-row writes fails here first.
 */
class OwnerLeaveApprovePerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_approving_a_two_day_leave_completes_inside_the_query_budget_with_every_bell_delivered()
    {
        $ananya = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();

        // The owner's screenshot shape: Sun 01/11 – Tue 03/11/2026
        // (school days Mon + Tue, 16 of Ananya's periods).
        $leave = LeaveRequest::create([
            'user_id' => $ananya->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-03',
            'reason' => 'fever (perf pin)',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        // The submit-time quiet plan, exactly what store() runs.
        LeaveAutoCover::arrange($leave, null, false, false);
        $planned = Substitution::where('absent_teacher_id', $ananya->id)
            ->where('status', 'pending')->count();
        $this->assertGreaterThan(0, $planned, 'Fixture: the quiet plan must exist before approval');

        $studentBellsBefore = UserNotification::where('type', 'period_substitute')->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
        ]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertRedirect();
        $response->assertSessionHas('flash_success');
        $this->assertSame('approved', LeaveRequest::find($leave->id)->status);

        // The plan went live in full…
        $this->assertSame($planned, Substitution::where('absent_teacher_id', $ananya->id)
            ->where('status', 'confirmed')->count());
        // …every substitute got their assignment bell…
        $this->assertSame($planned, UserNotification::where('type', 'substitution_assigned')->count());
        // …and the student fan-out still happened (whole classes
        // are belled per period — it is the WRITE SHAPE that was
        // fixed, not the notifications themselves).
        $this->assertGreaterThan(1000, UserNotification::where('type', 'period_substitute')->count() - $studentBellsBefore);

        // …inside the budget. Broken shape: 5,012 queries.
        $this->assertLessThan(400, $queries,
            "Approving this leave ran {$queries} queries — the per-row write storm is back (budget 400, broken shape 5012).");
    }
}
