<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Limited principal role (owner order 2026-10-28): 'principal'
 * is its own user_type — school-wide READ + two designated
 * approvals (leave decisions, exam result publication) — and
 * NOTHING else. Every write surface below must refuse the
 * principal at the gate (302 to login), while the read surfaces
 * and the two approvals pass.
 */
class OwnerPrincipalRoleTest extends TestCase
{
    use DatabaseTransactions;

    protected function principal()
    {
        $user = User::where('email', 'principal@hackstra.app')->firstOrFail();
        $user->user_type = 'principal';
        $user->save();

        return $user;
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_principal_reads_school_wide()
    {
        $principal = $this->principal();
        $classId = \App\Models\MyClass::first()->id;

        foreach ([
            route('users.index'),
            route('students.list', $classId),
            route('classes.index'),
            route('tt.index'),
            route('attendance.periods.index'),
            route('attendance.periods.show', 11),
            route('attendance.report'),
            route('marks.tabulation'),
            route('exams.index'),
            route('exam_dates.index'),
            route('transport.index'),
            route('audit_logs.index'),
            route('reports.builder'),
            route('discipline_records.index'),
            route('parent_messages.index'),
            route('payments.manage'),
            route('lesson_plans.index'),
            route('leave.index'),
            route('ask.index'),
        ] as $url) {
            $this->actingAs($principal)->get($url)->assertOk("Principal must read $url");
        }
    }

    public function test_principal_writes_denied()
    {
        $principal = $this->principal();

        // Each of these must bounce to login (team middleware),
        // never render or act.
        $this->actingAs($principal)->get(route('settings'))->assertRedirect(route('login'));
        $this->actingAs($principal)->get(route('users.create'))->assertRedirect(route('login'));
        $this->actingAs($principal)->get(route('exams.create'))->assertRedirect(route('login'));
        $this->actingAs($principal)->get(route('payments.create'))->assertRedirect(route('login'));
        $this->actingAs($principal)->get(route('tt.generate.form'))->assertRedirect(route('login'));
        $this->actingAs($principal)->get(route('marks.manage', [1, 1, 1, 1]))->assertRedirect(route('login'));
        $this->actingAs($principal)->get(route('certificates.create'))->assertRedirect(route('login'));
        $this->actingAs($principal)->get(route('attendance.periods.take', [788, 'date' => '2026-11-02']))->assertRedirect(route('login'));
    }

    public function test_principal_decides_leave_but_not_own()
    {
        $principal = $this->principal();
        $teacher = $this->teacher();

        $leave = LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-03',
            'reason' => 'test leave',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);

        $this->actingAs($principal)->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
            'decision_note' => 'Approved by principal.',
        ]);
        $this->assertEquals('approved', $leave->fresh()->status);

        $own = LeaveRequest::create([
            'user_id' => $principal->id,
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-11',
            'reason' => 'own leave',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $this->actingAs($principal)->post(route('leave.decide', $own->id), [
            'status' => 'approved',
        ]);
        $this->assertEquals('pending', $own->fresh()->status, 'Nobody decides their own leave');
    }

    public function test_principal_applies_for_own_leave()
    {
        $response = $this->actingAs($this->principal())->post(route('leave.store'), [
            'start_date' => '2026-12-15',
            'end_date' => '2026-12-16',
            'reason' => 'principal leave test',
            'leave_type' => 'normal',
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $this->principal()->id,
            'reason' => 'principal leave test',
        ]);
    }

    public function test_principal_publishes_and_unpublishes_results()
    {
        $exam = Exam::where('term', 1)->orderBy('id')->firstOrFail();
        $this->assertFalse((bool) $exam->results_published);

        $this->actingAs($this->principal())->post(route('exams.publish_results', $exam->id));
        $this->assertTrue((bool) $exam->fresh()->results_published);

        $this->actingAs($this->principal())->post(route('exams.unpublish_results', $exam->id));
        $this->assertFalse((bool) $exam->fresh()->results_published);
    }

    public function test_admin_and_teacher_unchanged()
    {
        $this->actingAs($this->admin())->get(route('settings'))->assertOk();
        $this->actingAs($this->admin())->get(route('users.create'))->assertOk();
        $this->actingAs($this->teacher())->get(route('attendance.periods.index'))->assertOk();
    }
}
