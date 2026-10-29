<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\ReportTemplate;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent C round 13 — report builder role gates.
 *
 * Settled rules under test: ReportSources whitelisted adapters ONLY
 * (unknown source refused, never raw SQL); ReportPresets::forRole
 * maps 'principal' to 'admin'; templates owner-private ONLY; row
 * cap 800 → redirect + flash, never 500.
 *
 * REAL FINDING (F-R13-1, fixed in this round): the presets mapping
 * was the ONLY principal→admin mapping. No report source listed
 * 'principal' in its roles, so the principal — whose sidebar item
 * 13 is "Reports & Analytics" — could OPEN the builder but got
 * 403 on EVERY generate (preview, pdf, and template save all run
 * through ReportEngine::validateConfig → ReportSources::allowed).
 * The builder page was dead for the role it was linked from. Fix:
 * ReportSources::allowedFor maps 'principal' to 'admin' before the
 * role lookup, mirroring ReportPresets exactly; the platform source
 * stays super_admin-only (its roles list is untouched).
 * rows() is already safe for the widened role: teacherClassIds
 * returns null for non-teachers, so a principal resolves
 * school-wide rows exactly like an admin.
 */
class OwnerReportsRoleGateRound13Test extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function config(string $source, array $columns, array $filters = []): array
    {
        return [
            'title' => 'Test Report',
            'subtitle' => '',
            'source' => $source,
            'filters' => $filters,
            'columns' => $columns,
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

    protected function studentsConfig(int $classId = 1): array
    {
        return $this->config('students', [
            ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
            ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
        ], ['class_id' => $classId]);
    }

    protected function assertPdfResponse($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_principal_builder_opens_and_generates_school_report()
    {
        $principal = $this->userByEmail('principal@hackstra.app');
        $this->actingAs($principal)->get(route('reports.builder'))->assertOk();

        $response = $this->actingAs($principal)->post(route('reports.pdf'), [
            'config' => $this->studentsConfig(1),
        ]);
        $this->assertPdfResponse($response);
    }

    public function test_principal_preview_and_template_save_work_but_platform_stays_closed()
    {
        $principal = $this->userByEmail('principal@hackstra.app');

        $this->actingAs($principal)->post(route('reports.preview'), [
            'config' => $this->studentsConfig(1),
        ])->assertOk();

        $this->actingAs($principal)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'Principal Roster',
                'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $this->assertDatabaseHas('report_templates', ['name' => 'Principal Roster', 'user_id' => $principal->id]);

        // Platform source stays super_admin-only even after the fix.
        $this->actingAs($principal)->post(route('reports.pdf'), [
            'config' => $this->config('platform', [
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'text', 'width' => 60],
                ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'width' => 40],
            ]),
        ])->assertForbidden();
    }

    public function test_student_and_parent_forbidden_on_every_builder_action()
    {
        $student = $this->userByEmail('student@hackstra.app');
        $parent = $this->userByEmail('parent@hackstra.app');

        $this->actingAs($student)->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($student)->post(route('reports.pdf'), [
            'config' => $this->studentsConfig(1),
        ])->assertForbidden();
        $this->actingAs($parent)->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($parent)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'Parent Tpl',
                'config' => $this->studentsConfig(1),
            ])->assertForbidden();
    }

    public function test_teacher_platform_and_unknown_source_refused_cleanly()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');

        // Platform source: 403, never data.
        $this->actingAs($teacher)->post(route('reports.pdf'), [
            'config' => $this->config('platform', [
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'text', 'width' => 60],
                ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'width' => 40],
            ]),
        ])->assertForbidden();

        // Unknown source: redirect + flash, never 500.
        $this->actingAs($teacher)->from(route('reports.builder'))
            ->post(route('reports.preview'), [
                'config' => $this->config('no_such_source', [
                    ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 20],
                ]),
            ])->assertRedirect(route('reports.builder'))
            ->assertSessionHas('flash_danger', 'Unknown report source.');
    }

    public function test_accountant_and_librarian_cannot_use_school_or_platform_sources()
    {
        $accountant = $this->userByEmail('accountant@hackstra.app');
        $librarian = $this->userByEmail('librarian@hackstra.app');

        // 'students' roles are teacher + admin only.
        $this->actingAs($accountant)->post(route('reports.pdf'), [
            'config' => $this->studentsConfig(1),
        ])->assertForbidden();
        $this->actingAs($librarian)->post(route('reports.pdf'), [
            'config' => $this->studentsConfig(1),
        ])->assertForbidden();
        $this->actingAs($accountant)->post(route('reports.pdf'), [
            'config' => $this->config('platform', [
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'text', 'width' => 60],
                ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'width' => 40],
            ]),
        ])->assertForbidden();
    }

    public function test_super_admin_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only, so the
        // Super Admin is 403 at the gate even on the platform source.
        $sa = $this->userByEmail('superadmin@hackstra.app');

        $this->actingAs($sa)->post(route('reports.pdf'), [
            'config' => $this->config('platform', [
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'text', 'width' => 60],
                ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'width' => 40],
            ]),
        ])->assertForbidden();

        $this->actingAs($sa)->post(route('reports.pdf'), [
            'config' => $this->studentsConfig(1),
        ])->assertForbidden();
    }

    public function test_templates_owner_private_and_missing_is_404()
    {
        // Owner is now the principal, not the admin: the new builder gate
        // (2026-10-29, teacher + principal only) 403s the admin before
        // any template action runs, so the admin can no longer be the
        // template owner here. The foreign user stays the teacher, who
        // passes the gate — so the foreign 403s below are genuine
        // owner-private checks, not gate artefacts.
        $principal = $this->userByEmail('principal@hackstra.app');
        $teacher = $this->userByEmail('teacher@hackstra.app');

        $this->actingAs($principal)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'Principal Roster',
                'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $template = ReportTemplate::where('name', 'Principal Roster')
            ->where('user_id', $principal->id)->firstOrFail();

        // Foreign template: 403 on update, destroy, and duplicate.
        $this->actingAs($teacher)->from(route('reports.builder'))
            ->put(route('reports.templates.update', [$template->id]), [
                'name' => 'Hijacked',
                'config' => $this->studentsConfig(1),
            ])->assertForbidden();
        $this->actingAs($teacher)->from(route('reports.builder'))
            ->delete(route('reports.templates.destroy', [$template->id]))
            ->assertForbidden();
        $this->actingAs($teacher)->from(route('reports.builder'))
            ->post(route('reports.templates.duplicate', [$template->id]))
            ->assertForbidden();
        $this->assertDatabaseHas('report_templates', ['id' => $template->id, 'name' => 'Principal Roster']);

        // Missing template: 404, not a leak-shaped error.
        $this->actingAs($principal)->from(route('reports.builder'))
            ->put(route('reports.templates.update', [999999]), [
                'name' => 'Ghost',
                'config' => $this->studentsConfig(1),
            ])->assertNotFound();

        // Owner update works.
        $this->actingAs($principal)->from(route('reports.builder'))
            ->put(route('reports.templates.update', [$template->id]), [
                'name' => 'Principal Roster v2',
                'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $this->assertDatabaseHas('report_templates', ['id' => $template->id, 'name' => 'Principal Roster v2']);
    }
}
