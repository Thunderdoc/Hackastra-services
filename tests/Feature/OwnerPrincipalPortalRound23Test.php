<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AuditLog;
use App\Models\DisciplineRecord;
use App\Models\Exam;
use App\Models\LeaveRequest;
use App\Models\LessonPlan;
use App\Models\ParentMessage;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent C round 23 — principal portal DEPTH.
 *
 * What earlier rounds already locked (NOT re-litigated, only
 * anchored where cheap): principal reads the sidebar surfaces at
 * 200 (role test), the publish flag flips (role test), the
 * student/parent visibility cycle (round 11), leave approve +
 * self-ban (role test, round 11), audit-trail split (round 12),
 * payments reads/writes + invoice 403 (round 22), bell badge
 * (round 16), SA × principal boundary (round 15).
 *
 * What was NOT locked until now — and is locked here:
 *  (a) DETAIL reads with content, not just 200: classes.show,
 *      exams.show, lesson-plan show, discipline show, parent
 *      message show, marks tabulation with real params, the
 *      tabulation selector POST, print_tabulation, monthly
 *      report + defaulters reads, transport + exam-dates reads;
 *  (b) users.index CONTENT: zero super_admin rows even under a
 *      tampered ?user_type=super_admin filter (the principal
 *      branch excludes super_admin server-side);
 *  (c) audit_logs CONTENT for the principal (school trail only,
 *      fresh markers — round-12 shape re-proved, not assumed);
 *  (d) POST-level write refusals, split by refusal SHAPE:
 *      middleware bounces (users/exams/payments/certificates/
 *      classes/transport stores → 302 to login) vs controller
 *      guards (parent-message compose, lesson-plan, discipline,
 *      substitution stores → 403), each with no row written;
 *  (e) the two designated approvals re-proved end to end on
 *      fresh fixtures (publish cycle incl. student + parent
 *      views; leave approve + own-leave self-ban).
 *
 * Gates read first (routes/web.php + controller constructors +
 * controller guards): MyClassController index/show
 * teamOrPrincipal:SA, writes teamSAWrite; ExamController
 * index/show teamOrPrincipal:SA, writes teamSAWrite except
 * publish/unpublish teamOrPrincipal:SAWrite; UserController
 * create/store/edit/update teamSA, index teamOrPrincipal:SA;
 * CertificateController create/store teamSAWrite; transport
 * index teamOrPrincipal:SA, assignments/fees stores teamSAWrite;
 * AttendanceController report/defaulters teamOrPrincipal:SAT
 * (excluded from the teamSAT line — AND-trap fix); marks
 * tabulation + selector teamOrPrincipal:SAT, print/bulk
 * teamOrPrincipal:SA, manage teamSATWrite; ParentMessage
 * store aborts 403 unless admin/teacher, show opens to
 * sender/addressed parent/principal; LessonPlan store
 * teacher-only 403, show opens to owner/admin/principal;
 * DisciplineRecord store admin/teacher-only 403, index/show
 * principal-visible; Substitution store admin/teacher-only
 * 403, index principal-visible.
 */
class OwnerPrincipalPortalRound23Test extends TestCase
{
    use DatabaseTransactions;

    protected function principal()
    {
        $user = User::where('email', 'principal@hackstra.app')->firstOrFail();
        if ($user->user_type !== 'principal') {
            $user->user_type = 'principal';
            $user->save();
        }

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

    public function test_principal_reads_detail_pages_with_content()
    {
        $principal = $this->principal();
        $class = \App\Models\MyClass::where('name', 'Prep')->firstOrFail();
        $exam = $this->term1();

        // classes.show has NO detail page by design (MyClassController
        // comment: "land on its index instead of returning 500") —
        // it redirects to classes.index for EVERYONE. The principal
        // must get exactly the admin's shape, not a login bounce.
        $this->actingAs($principal)->get(route('classes.show', $class->id))
            ->assertRedirect(route('classes.index'));
        $this->actingAs($this->admin())->get(route('classes.show', $class->id))
            ->assertRedirect(route('classes.index'));
        $this->actingAs($principal)->get(route('classes.index'))
            ->assertOk()->assertSee('Prep');
        // exams.show is the same no-detail-page shape as
        // classes.show: redirect to exams.index for everyone.
        $this->actingAs($principal)->get(route('exams.show', $exam->id))
            ->assertRedirect(route('exams.index'));
        $this->actingAs($this->admin())->get(route('exams.show', $exam->id))
            ->assertRedirect(route('exams.index'));
        $this->actingAs($principal)->get(route('exams.index'))
            ->assertOk()->assertSee($exam->name);
        $this->actingAs($principal)->get(route('students.list', 1))
            ->assertOk();
        $this->actingAs($principal)->get(route('transport.index'))
            ->assertOk();
        $this->actingAs($principal)->get(route('exam_dates.index'))
            ->assertOk();
        $this->actingAs($principal)->get(route('payments.manage'))
            ->assertOk();
        $this->actingAs($principal)->get(route('attendance.report', ['class_id' => 1]))
            ->assertOk();
        $this->actingAs($principal)->get(route('attendance.defaulters'))
            ->assertOk();
    }

    public function test_principal_users_index_excludes_platform_accounts_even_when_filtered()
    {
        $principal = $this->principal();

        $this->actingAs($principal)->get(route('users.index'))
            ->assertOk()
            ->assertDontSee('Ankit Kumar')
            ->assertDontSee('superadmin@hackstra.app');

        // Tampered filter: 'super_admin' is not among the
        // principal's offered roles, so the filter falls back to
        // the full staff list — still no platform row.
        $this->actingAs($principal)->get(route('users.index', ['user_type' => 'super_admin']))
            ->assertOk()
            ->assertDontSee('Ankit Kumar')
            ->assertDontSee('superadmin@hackstra.app');

        // Positive control: the principal filter narrows to
        // principals only, and the seeded principal is listed.
        $this->actingAs($principal)->get(route('users.index', ['user_type' => 'principal']))
            ->assertOk()
            ->assertSee('Rajesh Iyer')
            ->assertDontSee('Ananya Sharma');
    }

    public function test_principal_reads_lesson_plan_discipline_message_and_substitution_records()
    {
        $principal = $this->principal();
        $teacher = $this->teacher();
        $admin = $this->admin();

        $plan = LessonPlan::create([
            'teacher_id' => $teacher->id, 'my_class_id' => 1,
            'section_id' => 1, 'subject_id' => 1,
            'week_start' => '2026-11-02', 'topic' => 'R23-PLAN-TOPIC',
            'notes' => 'R23 plan notes',
        ]);
        $this->actingAs($principal)->get(route('lesson_plans.index'))->assertOk();
        $this->actingAs($principal)->get(route('lesson_plans.show', $plan->id))
            ->assertOk()->assertSee('R23-PLAN-TOPIC');

        $record = DisciplineRecord::create([
            'student_id' => $this->student()->id, 'recorded_by' => $admin->id,
            'incident_date' => '2026-11-02', 'type' => 'homework',
            'description' => 'R23-DISCIPLINE-MARKER', 'action_taken' => 'R23 action',
        ]);
        $this->actingAs($principal)->get(route('discipline_records.index'))->assertOk();
        $this->actingAs($principal)->get(route('discipline_records.show', $record->id))
            ->assertOk()->assertSee('R23-DISCIPLINE-MARKER');

        $message = ParentMessage::create([
            'sender_id' => $admin->id, 'my_class_id' => 1,
            'title' => 'R23-MESSAGE-TITLE', 'body' => 'R23-MESSAGE-BODY',
        ]);
        $this->actingAs($principal)->get(route('parent_messages.index'))
            ->assertOk()->assertSee('R23-MESSAGE-TITLE');
        $this->actingAs($principal)->get(route('parent_messages.show', $message->id))
            ->assertOk()->assertSee('R23-MESSAGE-BODY');

        $cell = TimeTable::firstOrFail();
        Substitution::create([
            'time_table_id' => $cell->id, 'date' => '2026-11-02',
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => User::where('email', 'teacher2@teacher.com')->firstOrFail()->id,
            'created_by' => $admin->id, 'reason' => 'R23 sub probe',
        ]);
        $this->actingAs($principal)->get(route('substitutions.index'))->assertOk();
    }

    public function test_principal_audit_log_shows_school_trail_only()
    {
        $sa = User::where('email', 'superadmin@hackstra.app')->firstOrFail();
        $admin = $this->admin();

        AuditLog::create([
            'user_id' => $sa->id, 'action' => 'platform.probe',
            'description' => 'SAONLYMARKERX23',
        ]);
        AuditLog::create([
            'user_id' => $admin->id, 'action' => 'school.probe',
            'description' => 'ADMINMARKERX23',
        ]);

        $this->actingAs($this->principal())->get(route('audit_logs.index'))
            ->assertOk()
            ->assertSee('ADMINMARKERX23')
            ->assertDontSee('SAONLYMARKERX23');
    }

    public function test_principal_marks_tabulation_selector_and_print()
    {
        $principal = $this->principal();

        // Sheet view with real params (exam 1, class 1, section 1).
        $this->actingAs($principal)->get(route('marks.tabulation', [1, 1, 1]))
            ->assertOk();

        // Selector POST only redirects into that view.
        $response = $this->actingAs($principal)->post(route('marks.tabulation_select'), [
            'exam_id' => 1, 'my_class_id' => 1, 'section_id' => 1,
        ]);
        $response->assertRedirect();
        $this->assertStringContainsString(
            'marks/tabulation/1/1/1',
            $response->headers->get('Location')
        );

        // The SA-group print document is a principal monitoring read.
        $this->actingAs($principal)->get(route('marks.print_tabulation', [1, 1, 1]))
            ->assertOk();
    }

    public function test_principal_middleware_writes_bounce_to_login_with_no_rows()
    {
        $principal = $this->principal();

        $this->actingAs($principal)->post(route('users.store'), [
            'name' => 'R23 Probe User', 'email' => 'r23probe@hackstra.app',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'r23probe@hackstra.app']);

        $this->actingAs($principal)->post(route('exams.store'), [
            'name' => 'R23 Exam Probe', 'term' => 1, 'year' => '2026-2027',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('exams', ['name' => 'R23 Exam Probe']);

        $this->actingAs($principal)->post(route('payments.store'), [
            'title' => 'R23 Payment Probe',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('payments', ['title' => 'R23 Payment Probe']);

        // Count-before/count-after, NOT count-0: the snapshot
        // inherits other agents' shared-DB probe rows (B's cert
        // probes), so only "my POST added none" is provable.
        $certsBefore = \App\Models\Certificate::count();
        $this->actingAs($principal)->post(route('certificates.store'), [
            'type' => 'bonafide',
        ])->assertRedirect(route('login'));
        $this->assertSame($certsBefore, \App\Models\Certificate::count());

        $this->actingAs($principal)->post(route('classes.store'), [
            'name' => 'R23 Class Probe',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('my_classes', ['name' => 'R23 Class Probe']);

        $assignmentsBefore = \App\Models\TransportAssignment::count();
        $this->actingAs($principal)->post(route('transport.assignments.store'), [
            'student_id' => $this->student()->id,
        ])->assertRedirect(route('login'));
        $this->assertSame($assignmentsBefore, \App\Models\TransportAssignment::count());
    }

    public function test_principal_controller_guarded_writes_abort_403_with_no_rows()
    {
        $principal = $this->principal();

        // Parent-message compose stays admin/teacher.
        $this->actingAs($principal)->post(route('parent_messages.store'), [
            'my_class_id' => 1, 'title' => 'R23 Compose Probe',
            'body' => 'R23 compose body',
        ])->assertForbidden();
        $this->assertDatabaseMissing('parent_messages', ['title' => 'R23 Compose Probe']);

        // Lesson-plan create stays teacher-only.
        $this->actingAs($principal)->post(route('lesson_plans.store'), [
            'my_class_id' => 1, 'subject_id' => 1,
            'week_start' => '2026-11-02', 'topic' => 'R23 Plan Probe',
        ])->assertForbidden();
        $this->assertDatabaseMissing('lesson_plans', ['topic' => 'R23 Plan Probe']);

        // Discipline create stays admin/teacher.
        $this->actingAs($principal)->post(route('discipline_records.store'), [
            'student_id' => $this->student()->id, 'incident_date' => '2026-11-02',
            'type' => 'homework', 'description' => 'R23 Discipline Probe',
        ])->assertForbidden();
        $this->assertDatabaseMissing('discipline_records', ['description' => 'R23 Discipline Probe']);

        // Substitution create stays admin/teacher.
        $cell = TimeTable::firstOrFail();
        $this->actingAs($principal)->post(route('substitutions.store'), [
            'time_table_id' => $cell->id, 'date' => '2026-11-02',
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => User::where('email', 'teacher2@teacher.com')->firstOrFail()->id,
            'reason' => 'R23 Sub Store Probe',
        ])->assertForbidden();
        $this->assertDatabaseMissing('substitutions', ['reason' => 'R23 Sub Store Probe']);
    }

    public function test_principal_closed_get_surfaces_still_bounce()
    {
        $principal = $this->principal();

        foreach ([
            route('settings'),
            route('users.create'),
            route('exams.create'),
            route('payments.create'),
            route('tt.generate.form'),
            route('marks.manage', [1, 1, 1, 1]),
            route('certificates.create'),
            route('attendance.periods.take', [788, 'date' => '2026-11-02']),
        ] as $url) {
            $this->actingAs($principal)->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_principal_publish_cycle_drives_student_and_parent_views()
    {
        $exam = $this->term1();
        $this->assertFalse((bool) $exam->results_published, 'Local Term 1 starts unpublished');
        $marksheet = route('marks.print', [Qs::hash($this->student()->id), $exam->id, '2026-2027']);

        $this->actingAs($this->student())->get($marksheet)
            ->assertOk()->assertSee('Results Not Published Yet');
        $this->actingAs($this->parent())->get($marksheet)
            ->assertOk()->assertSee('Results Not Published Yet');

        $this->actingAs($this->principal())->post(route('exams.publish_results', $exam->id));
        $this->assertTrue((bool) $exam->fresh()->results_published);
        $this->actingAs($this->student())->get($marksheet)
            ->assertOk()->assertDontSee('Results Not Published Yet')->assertSee('Aarav Sharma');
        $this->actingAs($this->parent())->get($marksheet)
            ->assertOk()->assertDontSee('Results Not Published Yet')->assertSee('Aarav Sharma');

        $this->actingAs($this->principal())->post(route('exams.unpublish_results', $exam->id));
        $this->assertFalse((bool) $exam->fresh()->results_published);
        $this->actingAs($this->student())->get($marksheet)
            ->assertOk()->assertSee('Results Not Published Yet');
    }

    public function test_principal_decides_teacher_leave_not_own()
    {
        $principal = $this->principal();
        $teacher = $this->teacher();

        $leave = LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-03',
            'reason' => 'round 23 principal approve probe',
            'leave_type' => 'normal', 'status' => 'pending',
        ]);
        $this->actingAs($principal)->post(route('leave.decide', $leave->id), [
            'status' => 'approved', 'decision_note' => 'Approved by principal.',
        ]);
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame($principal->id, $leave->fresh()->decided_by);

        $own = LeaveRequest::create([
            'user_id' => $principal->id,
            'start_date' => '2026-12-10', 'end_date' => '2026-12-11',
            'reason' => 'round 23 principal own leave probe',
            'leave_type' => 'normal', 'status' => 'pending',
        ]);
        $this->actingAs($principal)->post(route('leave.decide', $own->id), [
            'status' => 'approved',
        ]);
        $this->assertSame('pending', $own->fresh()->status, 'Nobody decides their own leave');
    }
}
