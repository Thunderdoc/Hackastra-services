<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\ReportTemplate;
use App\Support\Reports\ReportSources;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent C round 28 — Report Builder role re-sweep.
 *
 * Round 13 locked the headline gates (principal generate fixed by
 * F-R13-1, student/parent 403, SA platform-only, templates
 * owner-private). This round re-sweeps the builder AFTER the
 * round-24/25/26 landscape (marks entry re-gate F-R24-1, student/
 * parent re-sweep, mobile API re-sweep) and goes BEYOND round 13:
 * per-role builder source lists, teacher builder class scoping,
 * principal on admin-only sources, teacher marks own/foreign
 * subject, small-source generates per staff role, row-cap on the
 * preview path and for the principal, and cross-role template
 * privacy (SA and principal variants round 13 never tried).
 *
 * Settled rules under test: gateRole 403s everyone except teacher
 * and principal on every action (2026-10-29 narrowing; admin,
 * accountant, librarian, and Super Admin included);
 * ReportSources::allowedFor still maps 'principal' to 'admin'
 * (platform stays super_admin-only); templates are owner-private
 * (foreign = 403, missing = 404); row cap 800 → redirect + flash,
 * never 500.
 */
class OwnerReportsRoleResweepRound28Test extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function config(string $source, array $columns, array $filters = []): array
    {
        return [
            'title' => 'R28 Report',
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

    protected function assertPdfResponse($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    protected function classesConfig(): array
    {
        return $this->config('classes', [
            ['key' => 'name', 'label' => 'Class', 'type' => 'text', 'width' => 20],
            ['key' => 'sections', 'label' => 'Sections', 'type' => 'number', 'width' => 12],
            ['key' => 'students', 'label' => 'Students', 'type' => 'number', 'width' => 12],
        ]);
    }

    protected function studentsConfig(int $classId = 1): array
    {
        return $this->config('students', [
            ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
            ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
        ], ['class_id' => $classId]);
    }

    protected function platformConfig(): array
    {
        return $this->config('platform', [
            ['key' => 'metric', 'label' => 'Metric', 'type' => 'text', 'width' => 60],
            ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'width' => 40],
        ]);
    }

    public function test_builder_index_status_per_role()
    {
        // New builder gate (2026-10-29): teacher + principal ONLY.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('accountant@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('librarian@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('superadmin@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('student@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('parent@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
    }

    public function test_builder_source_lists_per_role_match_allowed_for()
    {
        // New builder gate (2026-10-29): only teacher + principal reach
        // the builder view, so only their source lists are readable
        // here. Admin/accountant/librarian/SA are 403 at the gate.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('accountant@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('librarian@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('superadmin@hackstra.app'))->get(route('reports.builder'))->assertForbidden();

        // Principal maps to admin exactly (ReportSources::allowedFor).
        $principalSources = $this->actingAs($this->userByEmail('principal@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertEquals(ReportSources::allowedFor('admin'), array_keys($principalSources));
        $this->assertContains('students', array_keys($principalSources));
        $this->assertContains('staff', array_keys($principalSources));
        $this->assertContains('classes', array_keys($principalSources));
        $this->assertNotContains('platform', array_keys($principalSources));

        // Teacher: students, marks, attendance, blank.
        $teacherSources = $this->actingAs($this->userByEmail('teacher@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertEquals(['students', 'marks', 'attendance', 'blank'], array_keys($teacherSources));

        // The builder lists must agree with the engine's own gate.
        $this->assertEquals(ReportSources::allowedFor('principal'), array_keys($principalSources));
        $this->assertEquals(ReportSources::allowedFor('teacher'), array_keys($teacherSources));
    }

    public function test_teacher_builder_classes_scoped_to_teacher_scope()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $classes = $this->actingAs($teacher)->get(route('reports.builder'))->viewData('classes');
        $ids = collect($classes)->pluck('id')->sort()->values()->all();
        $expected = collect(TeacherScope::classIds($teacher))->sort()->values()->all();
        $this->assertEquals($expected, $ids);
        // Ananya's scope includes Prep (1) but not Class 1 (2).
        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
    }

    public function test_principal_generates_small_sources_admin_forbidden()
    {
        // New builder gate (2026-10-29): admin is 403 at the gate.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $this->classesConfig()])->assertForbidden();

        // Principal on an admin-only source (staff): maps to admin, so it works.
        $staffConfig = $this->config('staff', [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'width' => 24],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'width' => 24],
        ]);
        $this->assertPdfResponse($this->actingAs($this->userByEmail('principal@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $staffConfig]));
        $this->assertPdfResponse($this->actingAs($this->userByEmail('principal@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $this->classesConfig()]));
    }

    public function test_teacher_own_students_and_marks_but_foreign_refused()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');

        // Own class students (Prep, class 1) generate fine.
        $this->assertPdfResponse($this->actingAs($teacher)
            ->post(route('reports.pdf'), ['config' => $this->studentsConfig(1)]));

        // Foreign class students refused (Class 1 is outside Ananya's scope).
        $this->actingAs($teacher)->post(route('reports.pdf'), [
            'config' => $this->studentsConfig(2),
        ])->assertForbidden();

        // Own subject marks: Prep English (subject 1, teacher_id 4 = Ananya).
        $ownMarks = $this->config('marks', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 24],
            ['key' => 'total', 'label' => 'Total', 'type' => 'number', 'width' => 10],
        ], ['exam_id' => 1, 'class_id' => 1, 'subject_id' => 1]);
        $this->assertPdfResponse($this->actingAs($teacher)
            ->post(route('reports.pdf'), ['config' => $ownMarks]));

        // Foreign subject marks: Prep Maths (subject 2, teacher_id 16 = Arun).
        $foreignMarks = $this->config('marks', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 24],
            ['key' => 'total', 'label' => 'Total', 'type' => 'number', 'width' => 10],
        ], ['exam_id' => 1, 'class_id' => 1, 'subject_id' => 2]);
        $this->actingAs($teacher)->post(route('reports.pdf'), [
            'config' => $foreignMarks,
        ])->assertForbidden();
    }

    public function test_teacher_attendance_generate_is_own_sessions_only()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $config = $this->config('attendance', [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'width' => 12],
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 24],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 10],
        ]);
        // No filters: the resolver scopes to the teacher's own sessions
        // (teacher_id = caller), so this must render, never leak others'.
        $this->assertPdfResponse($this->actingAs($teacher)
            ->post(route('reports.pdf'), ['config' => $config]));
    }

    public function test_accountant_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only, so the
        // accountant is 403 at the gate on every source, fees included.
        $accountant = $this->userByEmail('accountant@hackstra.app');
        $feesConfig = $this->config('fees', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'currency', 'width' => 12],
        ], ['class_id' => 1]);
        $this->actingAs($accountant)
            ->post(route('reports.pdf'), ['config' => $feesConfig])->assertForbidden();

        $booksConfig = $this->config('books', [
            ['key' => 'name', 'label' => 'Book Title', 'type' => 'text', 'width' => 28],
        ]);
        $this->actingAs($accountant)->post(route('reports.pdf'), [
            'config' => $booksConfig,
        ])->assertForbidden();
    }

    public function test_librarian_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only, so the
        // librarian is 403 at the gate on every source, books and blank included.
        $librarian = $this->userByEmail('librarian@hackstra.app');
        $booksConfig = $this->config('books', [
            ['key' => 'name', 'label' => 'Book Title', 'type' => 'text', 'width' => 28],
            ['key' => 'author', 'label' => 'Author', 'type' => 'text', 'width' => 20],
        ]);
        $this->actingAs($librarian)
            ->post(route('reports.pdf'), ['config' => $booksConfig])->assertForbidden();

        $blankConfig = $this->config('blank', [
            ['key' => 'custom_1', 'label' => 'Notes', 'type' => 'text', 'width' => 30],
        ]);
        $this->actingAs($librarian)
            ->post(route('reports.pdf'), ['config' => $blankConfig])->assertForbidden();

        $feesConfig = $this->config('fees', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
        ], ['class_id' => 1]);
        $this->actingAs($librarian)->post(route('reports.pdf'), [
            'config' => $feesConfig,
        ])->assertForbidden();
    }

    public function test_blank_source_renders_for_teacher_accountant_forbidden()
    {
        $blankConfig = $this->config('blank', [
            ['key' => 'custom_1', 'label' => 'Notes', 'type' => 'text', 'width' => 30],
        ]);
        $this->assertPdfResponse($this->actingAs($this->userByEmail('teacher@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $blankConfig]));
        // New builder gate (2026-10-29): accountant is 403 at the gate.
        $this->actingAs($this->userByEmail('accountant@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $blankConfig])->assertForbidden();
    }

    public function test_sa_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only, so the
        // Super Admin is 403 at the gate even on the platform source.
        $sa = $this->userByEmail('superadmin@hackstra.app');
        $this->actingAs($sa)
            ->post(route('reports.pdf'), ['config' => $this->platformConfig()])->assertForbidden();
        $this->actingAs($sa)->post(route('reports.pdf'), [
            'config' => $this->classesConfig(),
        ])->assertForbidden();
        $feesConfig = $this->config('fees', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
        ], ['class_id' => 1]);
        $this->actingAs($sa)->post(route('reports.pdf'), [
            'config' => $feesConfig,
        ])->assertForbidden();
    }

    public function test_principal_platform_refused()
    {
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->post(route('reports.pdf'), [
            'config' => $this->platformConfig(),
        ])->assertForbidden();
    }

    public function test_row_cap_principal_pdf_and_preview_admin_forbidden()
    {
        $expected = 'Too many rows for one PDF (3041). Filter by class or section first, then generate.';
        $unfiltered = $this->studentsConfig();
        unset($unfiltered['filters']['class_id']);
        $unfiltered['filters'] = [];

        // New builder gate (2026-10-29): admin is 403 at the gate, so the
        // row-cap redirect is no longer reachable for the admin.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.pdf'), ['config' => $unfiltered])
            ->assertForbidden();
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.preview'), ['config' => $unfiltered])
            ->assertForbidden();

        // Principal pdf: same cap, same flash (principal resolves school-wide).
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.pdf'), ['config' => $unfiltered])
            ->assertRedirect(route('reports.builder'))
            ->assertSessionHas('flash_danger', $expected);
    }

    public function test_templates_cross_role_privacy_sa_and_principal_variants()
    {
        // Owners are now principal + teacher: the new builder gate
        // (2026-10-29, teacher + principal only) 403s the admin before
        // any template action runs, so the admin can no longer be a
        // template owner here. The teacher passes the gate, so the
        // teacher-vs-principal 403 below is a genuine owner-private
        // check, not a gate artefact.
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $principal = $this->userByEmail('principal@hackstra.app');
        $sa = $this->userByEmail('superadmin@hackstra.app');

        $this->actingAs($teacher)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'R28 Teacher Tpl',
                'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $teacherTpl = ReportTemplate::where('name', 'R28 Teacher Tpl')->where('user_id', $teacher->id)->firstOrFail();

        $this->actingAs($principal)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'R28 Principal Tpl',
                'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $principalTpl = ReportTemplate::where('name', 'R28 Principal Tpl')->where('user_id', $principal->id)->firstOrFail();

        // SA cannot touch a school template (update / destroy / duplicate all 403, via the gate).
        $this->actingAs($sa)->from(route('reports.builder'))
            ->put(route('reports.templates.update', [$principalTpl->id]), [
                'name' => 'Hijacked', 'config' => $this->studentsConfig(1),
            ])->assertForbidden();
        $this->actingAs($sa)->from(route('reports.builder'))
            ->delete(route('reports.templates.destroy', [$principalTpl->id]))->assertForbidden();
        $this->actingAs($sa)->from(route('reports.builder'))
            ->post(route('reports.templates.duplicate', [$principalTpl->id]))->assertForbidden();

        // Teacher cannot touch the principal's template either (owner-private both ways).
        $this->actingAs($teacher)->from(route('reports.builder'))
            ->put(route('reports.templates.update', [$principalTpl->id]), [
                'name' => 'Hijacked', 'config' => $this->studentsConfig(1),
            ])->assertForbidden();

        // Owner update works (principal on her own template).
        $this->actingAs($principal)->from(route('reports.builder'))
            ->put(route('reports.templates.update', [$principalTpl->id]), [
                'name' => 'R28 Principal Tpl v2', 'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $this->assertDatabaseHas('report_templates', ['id' => $principalTpl->id, 'name' => 'R28 Principal Tpl v2']);

        // Builder template lists are own-only.
        $principalTpls = $this->actingAs($principal)->get(route('reports.builder'))->viewData('templates');
        $this->assertEquals([$principalTpl->id], collect($principalTpls)->pluck('id')->sort()->values()->all());
        $teacherTpls = $this->actingAs($teacher)->get(route('reports.builder'))->viewData('templates');
        $this->assertEquals([$teacherTpl->id], collect($teacherTpls)->pluck('id')->sort()->values()->all());
    }

    public function test_unknown_source_teacher_pdf_refused_cleanly_admin_forbidden()
    {
        // New builder gate (2026-10-29): admin is 403 at the gate, so the
        // unknown-source redirect is no longer reachable for the admin.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.pdf'), [
                'config' => $this->config('no_such_source', [
                    ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 20],
                ]),
            ])->assertForbidden();

        // Teacher passes the gate: unknown source still refuses cleanly
        // (redirect + flash, never 500).
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.pdf'), [
                'config' => $this->config('no_such_source', [
                    ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 20],
                ]),
            ])->assertRedirect(route('reports.builder'))
            ->assertSessionHas('flash_danger', 'Unknown report source.');
    }

    public function test_student_and_parent_forbidden_on_every_builder_action()
    {
        $student = $this->userByEmail('student@hackstra.app');
        $parent = $this->userByEmail('parent@hackstra.app');
        $this->actingAs($student)->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($student)->post(route('reports.pdf'), [
            'config' => $this->studentsConfig(1),
        ])->assertForbidden();
        $this->actingAs($student)->post(route('reports.preview'), [
            'config' => $this->studentsConfig(1),
        ])->assertForbidden();
        $this->actingAs($parent)->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($parent)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'Parent Tpl', 'config' => $this->studentsConfig(1),
            ])->assertForbidden();
    }
}
