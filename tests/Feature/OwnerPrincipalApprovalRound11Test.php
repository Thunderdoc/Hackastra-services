<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent C round 11 — principal approval powers END TO END, plus the
 * by-design limits around them.
 *
 * What round 10's OwnerPrincipalRoleTest already locked (NOT repeated
 * here): the publish/unpublish flag flips, principal approves a
 * teacher leave, and nobody decides their own leave. What was NOT
 * locked until now — and is locked here:
 *
 *  (a) the STUDENT-VISIBLE outcome of publishing: a student (and
 *      their parent) sees the honest "Results Not Published Yet"
 *      view while Term 1 is unpublished, the real marksheet once
 *      the principal publishes, and the not-published view again
 *      after unpublish;
 *  (b) the REJECT path of leave decisions, and a principal deciding
 *      a STUDENT leave (the code comment allows principals any
 *      leave, staff or student);
 *  (c) the by-design CLOSED surfaces: own-payslip pages 403 (the
 *      principal is not in getStaff, and a foreign payslip 403s on
 *      ownership), and the mobile API for a principal token is
 *      login + /me + own notifications only — timetable, marks,
 *      attendance and messages all 403.
 *
 * Anchors: principal@hackstra.app (Rajesh Iyer, user_type
 * 'principal'); student@hackstra.app is Aarav Sharma (user 57,
 * Prep, student_record 1, NOT a fee defaulter — verified, so no
 * defaulter block interferes with the marksheet reads); parent@
 * (id 5) is linked to records 1 + 2, so parent@ may read Aarav's
 * sheet. Exam 1 is the global Term 1 exam, unpublished locally.
 * A limit that OPENS (principal suddenly reaching payslips or API
 * marks) would fail here by design — that is a finding, not a
 * feature.
 */
class OwnerPrincipalApprovalRound11Test extends TestCase
{
    use DatabaseTransactions;

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function term1()
    {
        return Exam::where('term', 1)->orderBy('id')->firstOrFail();
    }

    protected function marksheetUrl()
    {
        return route('marks.print', [Qs::hash($this->student()->id), $this->term1()->id, '2026-2027']);
    }

    public function test_principal_publish_cycle_drives_student_and_parent_marksheet_visibility()
    {
        $exam = $this->term1();
        $this->assertFalse((bool) $exam->results_published, 'Local Term 1 starts unpublished');

        // Unpublished: student + parent get the honest empty state,
        // with no marks data on the page.
        $this->actingAs($this->student())->get($this->marksheetUrl())
            ->assertOk()
            ->assertSee('Results Not Published Yet');
        $this->actingAs($this->parent())->get($this->marksheetUrl())
            ->assertOk()
            ->assertSee('Results Not Published Yet');

        // Principal publishes: both surfaces now show the real card.
        $this->actingAs($this->principal())->post(route('exams.publish_results', $exam->id));
        $this->assertTrue((bool) $exam->fresh()->results_published);
        $this->actingAs($this->student())->get($this->marksheetUrl())
            ->assertOk()
            ->assertDontSee('Results Not Published Yet')
            ->assertSee('Aarav Sharma');
        $this->actingAs($this->parent())->get($this->marksheetUrl())
            ->assertOk()
            ->assertDontSee('Results Not Published Yet')
            ->assertSee('Aarav Sharma');

        // Principal unpublishes: the empty state returns.
        $this->actingAs($this->principal())->post(route('exams.unpublish_results', $exam->id));
        $this->assertFalse((bool) $exam->fresh()->results_published);
        $this->actingAs($this->student())->get($this->marksheetUrl())
            ->assertOk()
            ->assertSee('Results Not Published Yet');
    }

    public function test_principal_rejects_teacher_leave_and_decides_student_leave()
    {
        $principal = $this->principal();
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();

        $leave = LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-03',
            'reason' => 'round 11 reject probe',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $this->actingAs($principal)->post(route('leave.decide', $leave->id), [
            'status' => 'rejected',
            'decision_note' => 'Not approved by principal.',
        ]);
        $decided = $leave->fresh();
        $this->assertSame('rejected', $decided->status);
        $this->assertSame($principal->id, $decided->decided_by);
        $this->assertSame('Not approved by principal.', $decided->decision_note);

        // A student leave is also decidable by the principal.
        $studentLeave = LeaveRequest::create([
            'user_id' => $this->student()->id,
            'start_date' => '2026-12-05',
            'end_date' => '2026-12-06',
            'reason' => 'round 11 student leave probe',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $this->actingAs($principal)->post(route('leave.decide', $studentLeave->id), [
            'status' => 'approved',
        ]);
        $this->assertSame('approved', $studentLeave->fresh()->status);
        $this->assertSame($principal->id, $studentLeave->fresh()->decided_by);
    }

    public function test_principal_payslip_access_stays_closed()
    {
        $principal = $this->principal();

        // Own-payslip index: principal is not in getStaff().
        $this->actingAs($principal)->get(route('my_payslips.index'))->assertStatus(403);

        // A foreign payslip (teacher@'s Oct 2026 payroll id 1) 403s
        // on ownership even if the index gate ever changed.
        $payroll = Payroll::where('user_id', User::where('email', 'teacher@hackstra.app')->firstOrFail()->id)
            ->orderBy('id')->firstOrFail();
        $this->actingAs($principal)->get(route('my_payslips.payslip', $payroll->id))->assertStatus(403);
    }

    public function test_principal_mobile_api_limited_to_login_me_and_own_notifications()
    {
        // Login works for the principal (only super_admin is barred).
        $login = $this->postJson('/api/login', [
            'identity' => 'principal@hackstra.app',
            'password' => 'hackstra',
        ]);
        $login->assertOk();
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $auth = ['Authorization' => 'Bearer ' . $token];
        $this->withHeaders($auth)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.user_type', 'principal');

        // Own notifications are the caller's own only — allowed.
        $this->withHeaders($auth)->getJson('/api/notifications')->assertOk();

        // Everything else scoped to student/parent/teacher data: 403.
        $this->withHeaders($auth)->getJson('/api/timetable/mine')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/marks/mine')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/attendance/mine')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/messages')->assertStatus(403);
    }
}
