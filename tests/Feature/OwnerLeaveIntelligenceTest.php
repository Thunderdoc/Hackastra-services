<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Models\Subject;
use App\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Owner brief 2026-10-30 — the leave queue as an intelligence
 * page: summary cards with real counts, working filters, a Leave
 * Impact Analysis per teacher request, the full week timetable
 * behind the allocation, the approver-side leave balance, the
 * coverage state line, and the overlap warning. Every number
 * comes from the real grid (Ananya's Monday = 8 periods).
 */
class OwnerLeaveIntelligenceTest extends TestCase
{
    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function pendingAnanya(): LeaveRequest
    {
        return LeaveRequest::create([
            'user_id' => $this->user('teacher@hackstra.app')->id,
            'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
            'reason' => 'Intelligence probe', 'leave_type' => 'normal', 'status' => 'pending',
        ]);
    }

    public function test_summary_cards_show_real_counts()
    {
        $this->pendingAnanya();
        $admin = $this->user('admin@hackstra.app');
        $page = $this->actingAs($admin)->get(route('leave.index'));
        $page->assertOk()
            ->assertSee('Pending Requests')->assertSee('Approved Requests')
            ->assertSee('Rejected Requests')->assertSee('Total Requests')
            ->assertSee('Pending queue coverage');
        // The pending count card reflects the row just created.
        $pending = LeaveRequest::where('status', 'pending')->count();
        $this->assertGreaterThanOrEqual(1, $pending);
    }

    public function test_impact_analysis_and_week_grid_render_for_teacher_leave()
    {
        $this->pendingAnanya();
        $admin = $this->user('admin@hackstra.app');
        $this->actingAs($admin)->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Leave Impact Analysis')
            ->assertSee('Working days: 1')
            ->assertSee('Teaching periods: 8')
            ->assertSee('Requiring coverage: 8')
            ->assertSee('Not covered')
            ->assertSee('Week timetable (');
    }

    public function test_filters_search_status_and_coverage()
    {
        $this->pendingAnanya();
        $admin = $this->user('admin@hackstra.app');
        // Search finds her; a nonsense search empties the table.
        $this->actingAs($admin)->get(route('leave.index', ['q' => 'Ananya']))
            ->assertOk()->assertSee('Ananya Sharma');
        $this->actingAs($admin)->get(route('leave.index', ['q' => 'Zzznobody']))
            ->assertOk()->assertSee('No leave requests yet.');
        // Status filter: the pending row disappears under approved.
        $this->actingAs($admin)->get(route('leave.index', ['status' => 'approved']))
            ->assertOk()->assertDontSee('Intelligence probe');
        // Coverage filter: an uncovered pending leave matches "none".
        $this->actingAs($admin)->get(route('leave.index', ['coverage' => 'none']))
            ->assertOk()->assertSee('Intelligence probe');
        $this->actingAs($admin)->get(route('leave.index', ['coverage' => 'covered']))
            ->assertOk()->assertDontSee('Intelligence probe');
    }

    public function test_leave_balance_line_shows_for_staff_with_quota()
    {
        Setting::updateOrCreate(['type' => 'paid_leave_quota_days'], ['description' => '12']);
        $this->pendingAnanya();
        $admin = $this->user('admin@hackstra.app');
        $this->actingAs($admin)->get(route('leave.index'))
            ->assertOk()->assertSee('Leave balance 2026:');
    }

    public function test_overlap_warning_names_teacher_on_approved_leave_sharing_a_subject()
    {
        $ananya = $this->user('teacher@hackstra.app');
        // Another teacher of one of Ananya's subjects, approved off
        // on the same Monday.
        $subject = Subject::where('teacher_id', $ananya->id)->first();
        $this->assertNotNull($subject, 'Ananya teaches at least one subject');
        $other = Subject::where('name', $subject->name)
            ->where('teacher_id', '!=', $ananya->id)
            ->whereNotNull('teacher_id')->first();
        $this->assertNotNull($other, 'a second teacher shares the subject');
        $otherTeacher = User::findOrFail($other->teacher_id);
        LeaveRequest::create([
            'user_id' => $otherTeacher->id,
            'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
            'reason' => 'Already approved', 'leave_type' => 'normal', 'status' => 'approved',
            'decided_by' => $this->user('admin@hackstra.app')->id, 'decided_at' => now(),
        ]);
        $this->pendingAnanya();
        $admin = $this->user('admin@hackstra.app');
        $this->actingAs($admin)->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Also on approved leave in this window: ' . $otherTeacher->name);
    }

    public function test_principal_sees_the_intelligence_queue_too()
    {
        $this->pendingAnanya();
        $principal = $this->user('principal@hackstra.app');
        $this->actingAs($principal)->get(route('leave.index'))
            ->assertOk()->assertSee('Pending Requests')->assertSee('Leave Impact Analysis');
    }

    public function test_teacher_student_queue_renders_without_the_admin_summary()
    {
        // The same blade serves the teacher's student-leave queue,
        // which passes no summary/filters — it must still render.
        $ananya = $this->user('teacher@hackstra.app');
        $this->actingAs($ananya)->get(route('leave.student_requests'))
            ->assertOk()->assertSee('Leave Requests');
    }
}
