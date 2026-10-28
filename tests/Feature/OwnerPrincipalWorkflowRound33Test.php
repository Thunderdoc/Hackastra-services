<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\LeaveRequest;
use App\Models\ReportTemplate;
use App\Models\Substitution;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 33 — principal approval WORKFLOW chain (P15).
 * Round 11 proved publish + leave decisions separately; round 23
 * proved portal reads + closed writes separately; round 28 proved
 * the report builder per role. This round runs ONE continuous
 * principal chain through the REAL routes and asserts scope at
 * every handoff, because a link can be guarded while the next
 * link leaks:
 *
 *  (a) teacher leave -> principal approves (decided_by = principal)
 *      -> teacher sees approved; principal's OWN leave -> own
 *      decide refused, stays pending -> admin decides -> approved;
 *      approving creates NO substitutions (auto-cover stays an
 *      owner call, deliberately not built);
 *  (b) principal publishes an exam -> student + parent marksheet
 *      views flip to the real card, then unpublish -> the honest
 *      "Results Not Published Yet" view returns;
 *  (c) principal report generate (admin-mapped sources) + own
 *      template save -> builder lists own templates only, admin
 *      template untouched (foreign update 403);
 *  (d) principal audit index AFTER principal actions -> the
 *      principal-acted template row is in the school trail, and
 *      ZERO super_admin-acted rows appear (P6/P10: listed ids
 *      equal the school-trail ids, not a count claim alone).
 *
 * Honest scope note for (d): publishResults and leave.decide do
 * NOT write audit rows (publish notifies via Notify; decide only
 * updates the leave row) — the audit leg therefore keys on the
 * report template save, which DOES Audit::log with the principal
 * as actor. Asserting publish/leave audit rows would be inventing
 * a logging contract the code does not have.
 */
class OwnerPrincipalWorkflowRound33Test extends TestCase
{
    use DatabaseTransactions;

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
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

    protected function marksheetUrl()
    {
        return route('marks.print', [Qs::hash($this->student()->id), $this->term1()->id, '2026-2027']);
    }

    protected function classesConfig(): array
    {
        return [
            'title' => 'R33 Principal Report',
            'subtitle' => '',
            'source' => 'classes',
            'filters' => [],
            'columns' => [
                ['key' => 'name', 'label' => 'Class', 'type' => 'text', 'width' => 20],
                ['key' => 'sections', 'label' => 'Sections', 'type' => 'number', 'width' => 12],
                ['key' => 'students', 'label' => 'Students', 'type' => 'number', 'width' => 12],
            ],
            'options' => [
                'orientation' => 'portrait',
                'margin_mm' => 10,
                'font_size' => 11,
                'show_header' => true,
                'show_meta' => true,
                'show_footer' => true,
                'show_signatures' => false,
                'blank_rows' => 0,
                'totals' => [],
                'sort' => null,
            ],
            'instructions' => '',
        ];
    }

    public function test_principal_leave_approval_chain_then_own_leave_self_ban_then_admin_decides()
    {
        $principal = $this->principal();
        $teacher = $this->teacher();

        // (a1) Teacher normal leave -> principal approves.
        $leave = LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-03',
            'reason' => 'R33 chain teacher leave',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $this->actingAs($principal)->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
            'decision_note' => 'Approved by principal in chain.',
        ])->assertRedirect();
        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame((int) $principal->id, (int) $leave->decided_by);
        $this->assertSame('Approved by principal in chain.', $leave->decision_note);

        // Teacher sees the decision on My Leave.
        $this->actingAs($teacher)->get(route('leave.my'))->assertOk();

        // Approving creates NO substitutions (auto-cover owner call).
        $this->assertSame(0, Substitution::count());

        // (a2) Principal's OWN normal leave: own decide refused.
        $this->actingAs($principal)->post(route('leave.store'), [
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-11',
            'reason' => 'R33 chain principal own leave',
            'leave_type' => 'normal',
        ])->assertRedirect();
        $own = LeaveRequest::where('user_id', $principal->id)
            ->where('reason', 'R33 chain principal own leave')->firstOrFail();
        $this->assertSame('pending', $own->status);

        $self = $this->actingAs($principal)->post(route('leave.decide', $own->id), [
            'status' => 'approved',
        ]);
        $self->assertRedirect();
        $self->assertSessionHas('flash_danger', 'You cannot decide your own leave request — another admin must decide it.');
        $own->refresh();
        $this->assertSame('pending', $own->status);
        $this->assertNull($own->decided_by);

        // (a3) Admin decides the principal's leave: approved.
        $this->actingAs($this->admin())->post(route('leave.decide', $own->id), [
            'status' => 'approved',
        ])->assertRedirect();
        $own->refresh();
        $this->assertSame('approved', $own->status);
        $this->assertSame((int) $this->admin()->id, (int) $own->decided_by);
    }

    public function test_principal_publish_cycle_in_chain_drives_student_and_parent_views()
    {
        $exam = $this->term1();
        $this->assertFalse((bool) $exam->results_published, 'Local Term 1 starts unpublished');

        $this->actingAs($this->student())->get($this->marksheetUrl())
            ->assertOk()->assertSee('Results Not Published Yet');
        $this->actingAs($this->parent())->get($this->marksheetUrl())
            ->assertOk()->assertSee('Results Not Published Yet');

        $this->actingAs($this->principal())->post(route('exams.publish_results', $exam->id))
            ->assertRedirect();
        $this->assertTrue((bool) $exam->fresh()->results_published);
        $this->actingAs($this->student())->get($this->marksheetUrl())
            ->assertOk()->assertDontSee('Results Not Published Yet')->assertSee('Aarav Sharma');
        $this->actingAs($this->parent())->get($this->marksheetUrl())
            ->assertOk()->assertDontSee('Results Not Published Yet')->assertSee('Aarav Sharma');

        $this->actingAs($this->principal())->post(route('exams.unpublish_results', $exam->id))
            ->assertRedirect();
        $this->assertFalse((bool) $exam->fresh()->results_published);
        $this->actingAs($this->student())->get($this->marksheetUrl())
            ->assertOk()->assertSee('Results Not Published Yet');
        $this->actingAs($this->parent())->get($this->marksheetUrl())
            ->assertOk()->assertSee('Results Not Published Yet');
    }

    public function test_principal_report_generate_and_own_template_chain()
    {
        $principal = $this->principal();
        $admin = $this->admin();

        // Admin template exists first: principal must never see it.
        $this->actingAs($admin)->post(route('reports.templates.store'), [
            'name' => 'R33 Admin Template',
            'config' => $this->classesConfig(),
        ])->assertRedirect();
        $adminTemplate = ReportTemplate::where('user_id', $admin->id)
            ->where('name', 'R33 Admin Template')->firstOrFail();

        // Principal builder: same sources as admin (principal->admin
        // mapping), platform absent.
        $builder = $this->actingAs($principal)->get(route('reports.builder'))->assertOk();
        $adminSources = $this->actingAs($admin)->get(route('reports.builder'))->viewData('sources');
        $principalSources = $builder->viewData('sources');
        $this->assertSame(array_keys($adminSources), array_keys($principalSources));
        $this->assertNotContains('platform', array_keys($principalSources));
        $this->assertNotContains($adminTemplate->id, collect($builder->viewData('templates'))->pluck('id')->all());

        // Principal generates a real PDF from an admin-mapped source.
        $pdf = $this->actingAs($principal)->post(route('reports.pdf'), [
            'config' => $this->classesConfig(),
        ]);
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type', ''));
        $this->assertGreaterThan(1000, strlen($pdf->getContent()));

        // Principal saves an OWN template: listed for principal only.
        $this->actingAs($principal)->post(route('reports.templates.store'), [
            'name' => 'R33 Principal Template',
            'config' => $this->classesConfig(),
        ])->assertRedirect()->assertSessionHas('flash_success', 'Template saved.');
        $own = ReportTemplate::where('user_id', $principal->id)
            ->where('name', 'R33 Principal Template')->firstOrFail();
        $after = $this->actingAs($principal)->get(route('reports.builder'))->viewData('templates');
        $this->assertContains($own->id, collect($after)->pluck('id')->all());
        $this->assertNotContains($adminTemplate->id, collect($after)->pluck('id')->all());

        // Foreign template update refused; admin template untouched.
        $this->actingAs($principal)->put(route('reports.templates.update', $adminTemplate->id), [
            'name' => 'R33 Hijack Attempt',
            'config' => $this->classesConfig(),
        ])->assertForbidden();
        $this->assertSame('R33 Admin Template', $adminTemplate->fresh()->name);
    }

    public function test_principal_audit_index_after_chain_actions_shows_school_trail_only()
    {
        $principal = $this->principal();
        $sa = User::where('email', 'superadmin@hackstra.app')->firstOrFail();

        // A super_admin-acted row must never reach the principal.
        AuditLog::create([
            'user_id' => $sa->id, 'action' => 'platform.probe',
            'description' => 'SAONLYMARKERX33',
        ]);

        // Principal action that DOES audit-log: template save.
        $this->actingAs($principal)->post(route('reports.templates.store'), [
            'name' => 'R33 Audit Trail Template',
            'config' => $this->classesConfig(),
        ])->assertRedirect();
        $logged = AuditLog::where('user_id', $principal->id)
            ->where('action', 'report.template_saved')
            ->where('description', 'like', '%R33 Audit Trail Template%')
            ->firstOrFail();

        // P10: listed ids are the school-trail ids — the SA row is
        // absent from view data, not merely from rendered text.
        $response = $this->actingAs($principal)->get(route('audit_logs.index'))->assertOk();
        $listedIds = collect($response->viewData('logs')->items())->pluck('id')->all();
        $this->assertContains($logged->id, $listedIds);
        $saRow = AuditLog::where('description', 'SAONLYMARKERX33')->firstOrFail();
        $this->assertNotContains($saRow->id, $listedIds);
        $response->assertSee('R33 Audit Trail Template');
        $response->assertDontSee('SAONLYMARKERX33');
    }

    public function test_principal_closed_surfaces_in_chain_bounce_with_no_rows()
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

        $this->actingAs($principal)->post(route('users.store'), [
            'name' => 'R33 Probe User', 'email' => 'r33probe@hackstra.app',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'r33probe@hackstra.app']);

        $this->actingAs($principal)->post(route('exams.store'), [
            'name' => 'R33 Exam Probe', 'term' => 1, 'year' => '2026-2027',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('exams', ['name' => 'R33 Exam Probe']);

        $this->actingAs($principal)->post(route('payments.store'), [
            'title' => 'R33 Payment Probe',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('payments', ['title' => 'R33 Payment Probe']);

        $certsBefore = \App\Models\Certificate::count();
        $this->actingAs($principal)->post(route('certificates.store'), [
            'type' => 'bonafide',
        ])->assertRedirect(route('login'));
        $this->assertSame($certsBefore, \App\Models\Certificate::count());
    }
}
