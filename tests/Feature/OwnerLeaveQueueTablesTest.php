<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Leave queue tables (owner, 2026-11-07 late): "yha ve tables
 * bne toh mja aajaehga" for the student queue, then the scope
 * correction the same night — "yha ve toh mai proper table type
 * saab bola tha" — the MAIN admin/principal queue is a table
 * too. Both surfaces share leave/index.blade.php, split on the
 * summary the student view never receives. These pins hold the
 * presentation (hk-std sheet + a request row + decision
 * controls) on both surfaces; the decision behaviour itself
 * (endpoints, applicant bell, cover) is pinned by the leave
 * suites around them.
 */
class OwnerLeaveQueueTablesTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_student_queue_renders_a_table_row_with_decision_controls()
    {
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $leave = LeaveRequest::create([
            'user_id' => $student->id,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-10',
            'reason' => 'fever',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $resp = $this->actingAs($this->teacher())->get(route('leave.student_requests'));
        $resp->assertOk();
        // The hk-std sheet and its columns.
        $resp->assertSee('table hk-std lv-sq-table', false);
        foreach (['Student', 'Class – Section', 'Dates', 'Days', 'Type', 'Reason', 'Status', 'Decision'] as $col) {
            $resp->assertSee('<th>' . $col . '</th>', false);
        }
        // The request as ONE row: student, class-section, the
        // d/m/Y date span, status pill, and the decision form
        // posting to the same decide endpoint with its note.
        $resp->assertSee('Aarav Sharma');
        $resp->assertSee('Prep — A');
        $resp->assertSee('09/11/2026 – 10/11/2026', false);
        $resp->assertSee('fever');
        $resp->assertSee(route('leave.decide', $leave->id), false);
        $resp->assertSee('name="decision_note"', false);
        $resp->assertSee('value="approved"', false);
        $resp->assertSee('value="rejected"', false);
        // No card markup on this surface any more.
        $resp->assertDontSee('class="lv-req-card"', false);
    }

    public function test_main_queue_renders_a_table_with_filters_and_summary()
    {
        $leave = LeaveRequest::create([
            'user_id' => $this->teacher()->id,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'reason' => 'Family function',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $resp = $this->actingAs($this->admin())->get(route('leave.index'));
        $resp->assertOk();
        // The main sheet + its columns.
        $resp->assertSee('lv-sq-table lv-mq-table', false);
        foreach (['Applicant', 'Dates', 'Days', 'Type', 'Reason', 'Coverage', 'Status', 'Actions'] as $col) {
            $resp->assertSee('<th>' . $col . '</th>', false);
        }
        // The request row with applicant chip + decision form.
        $resp->assertSee('Ananya Sharma');
        $resp->assertSee('Teacher');
        $resp->assertSee('09/11/2026 – 09/11/2026', false);
        $resp->assertSee(route('leave.decide', $leave->id), false);
        $resp->assertSee('name="decision_note"', false);
        // The furniture above the sheet is untouched: summary
        // tiles + the filters row.
        $resp->assertSee('Pending Requests');
        $resp->assertSee('Pending queue coverage');
        $resp->assertSee('name="status"', false);
        $resp->assertSee('name="coverage"', false);
        $resp->assertDontSee('class="lv-req-card"', false);
    }

    public function test_student_queue_decision_still_bells_the_applicant()
    {
        // Behavioural anchor for the table conversion: approving
        // from the student queue runs the same decide() — the
        // applicant bell (Round 2) fires and the status flips.
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $leave = LeaveRequest::create([
            'user_id' => $student->id,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'reason' => 'fever',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $before = \Illuminate\Support\Facades\DB::table('user_notifications')
            ->where('user_id', $student->id)->count();

        $this->actingAs($this->teacher())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
            'decision_note' => 'Take rest',
        ])->assertRedirect();

        $this->assertSame('approved', $leave->fresh()->status);
        $after = \Illuminate\Support\Facades\DB::table('user_notifications')
            ->where('user_id', $student->id)->count();
        $this->assertGreaterThan($before, $after, 'the applicant must be belled on the decision');
    }
}
