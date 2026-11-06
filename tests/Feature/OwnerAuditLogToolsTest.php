<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 19 (2026-11-01): Audit Log tooling — filters, group pills,
 * plain-English labels, resolved subject names, 15/page, CSV
 * export of the filtered set. Visibility scope untouched.
 */
class OwnerAuditLogToolsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function seedRows(): void
    {
        $exam = Exam::findOrFail(1);
        AuditLog::create(['user_id' => $this->teacher()->id, 'action' => 'marks.submitted',
            'subject_type' => Exam::class, 'subject_id' => $exam->id,
            'description' => 'I19MARKSROW submitted the unit', 'ip_address' => '10.0.0.9']);
        AuditLog::create(['user_id' => $this->admin()->id, 'action' => 'report.exported',
            'description' => 'I19REPORTROW exported a report', 'ip_address' => '10.0.0.8']);
    }

    public function test_labels_subject_names_and_pills_render()
    {
        $this->seedRows();
        $examName = Exam::findOrFail(1)->name;
        $html = $this->actingAs($this->admin())->get(route('audit_logs.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Marks submitted', $html);
        $this->assertStringContainsString('Report exported', $html);
        // The subject shows its NAME, not "Exam #1".
        $this->assertStringContainsString('<b>' . $examName . '</b>', $html);
        $this->assertStringNotContainsString('Exam #1<', $html);
        // Quick pills with counts + the filter form + export link.
        $this->assertStringContainsString('au-qpill', $html);
        $this->assertStringContainsString('name="date_from"', $html);
        $this->assertStringContainsString('name="user_id"', $html);
        $this->assertStringContainsString(route('audit_logs.export'), $html);
    }

    public function test_filters_narrow_the_set()
    {
        $this->seedRows();
        $admin = $this->admin();

        $bySearch = $this->actingAs($admin)->get(route('audit_logs.index', ['q' => 'I19MARKSROW']))->assertOk()->getContent();
        $this->assertStringContainsString('I19MARKSROW', $bySearch);
        $this->assertStringNotContainsString('I19REPORTROW', $bySearch);

        $byUser = $this->actingAs($admin)->get(route('audit_logs.index', ['user_id' => $this->teacher()->id]))->assertOk()->getContent();
        $this->assertStringContainsString('I19MARKSROW', $byUser);
        $this->assertStringNotContainsString('I19REPORTROW', $byUser);

        $byAction = $this->actingAs($admin)->get(route('audit_logs.index', ['action' => 'report.exported']))->assertOk()->getContent();
        $this->assertStringContainsString('I19REPORTROW', $byAction);
        $this->assertStringNotContainsString('I19MARKSROW', $byAction);

        $byGroup = $this->actingAs($admin)->get(route('audit_logs.index', ['group' => 'marks']))->assertOk()->getContent();
        $this->assertStringContainsString('I19MARKSROW', $byGroup);
        $this->assertStringNotContainsString('I19REPORTROW', $byGroup);

        $byDate = $this->actingAs($admin)->get(route('audit_logs.index', ['date_from' => '2999-01-01']))->assertOk()->getContent();
        $this->assertStringContainsString('No audit entries match these filters.', $byDate);
    }

    public function test_pagination_is_fifteen_per_page()
    {
        for ($i = 0; $i < 20; $i++) {
            AuditLog::create(['user_id' => $this->admin()->id, 'action' => 'auth.login',
                'description' => 'I19PAGE row ' . $i]);
        }
        $response = $this->actingAs($this->admin())->get(route('audit_logs.index', ['q' => 'I19PAGE']))->assertOk();
        $this->assertCount(15, $response->viewData('logs')->items());
        $this->assertSame(20, $response->viewData('logs')->total());
    }

    public function test_csv_export_carries_the_filtered_set_with_labels()
    {
        $this->seedRows();
        $csv = $this->actingAs($this->admin())
            ->get(route('audit_logs.export', ['action' => 'marks.submitted']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('When,User,Action,Subject,Details,IP', $csv);
        $this->assertStringContainsString('Marks submitted', $csv);
        $this->assertStringContainsString('I19MARKSROW', $csv);
        $this->assertStringNotContainsString('I19REPORTROW', $csv);
    }

    public function test_export_keeps_the_school_scope_for_admin()
    {
        $sa = User::where('email', 'superadmin@hackstra.app')->firstOrFail();
        AuditLog::create(['user_id' => $sa->id, 'action' => 'settings.updated',
            'description' => 'I19SAONLYROW']);
        $csvAdmin = $this->actingAs($this->admin())->get(route('audit_logs.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('I19SAONLYROW', $csvAdmin);
    }

    public function test_export_for_super_admin_includes_platform_rows()
    {
        // Split from the admin case: switching the acting user
        // mid-test trips the team middleware's session state.
        $sa = User::where('email', 'superadmin@hackstra.app')->firstOrFail();
        AuditLog::create(['user_id' => $sa->id, 'action' => 'settings.updated',
            'description' => 'I19SAONLYROW']);
        $csvSa = $this->actingAs($sa)->get(route('audit_logs.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('I19SAONLYROW', $csvSa);
    }
}
