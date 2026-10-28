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
 * Settled rules under test: gateRole 403s student/parent on every
 * action; ReportSources::allowedFor maps 'principal' to 'admin'
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
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('accountant@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('librarian@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('superadmin@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('student@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($this->userByEmail('parent@hackstra.app'))->get(route('reports.builder'))->assertForbidden();
    }

    public function test_builder_source_lists_per_role_match_allowed_for()
    {
        // Admin: everything except platform.
        $adminSources = $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertContains('students', array_keys($adminSources));
        $this->assertContains('staff', array_keys($adminSources));
        $this->assertContains('classes', array_keys($adminSources));
        $this->assertNotContains('platform', array_keys($adminSources));

        // Principal maps to admin exactly.
        $principalSources = $this->actingAs($this->userByEmail('principal@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertEquals(array_keys($adminSources), array_keys($principalSources));

        // SA: platform only.
        $saSources = $this->actingAs($this->userByEmail('superadmin@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertEquals(['platform'], array_keys($saSources));

        // Accountant and librarian: their narrow sets, nothing else.
        $accountantSources = $this->actingAs($this->userByEmail('accountant@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertEquals(['fees', 'defaulters', 'fines', 'fee_summary', 'blank'], array_keys($accountantSources));
        $librarianSources = $this->actingAs($this->userByEmail('librarian@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertEquals(['fines', 'books', 'issues', 'library_summary', 'blank'], array_keys($librarianSources));

        // Teacher: students, marks, attendance, blank.
        $teacherSources = $this->actingAs($this->userByEmail('teacher@hackstra.app'))
            ->get(route('reports.builder'))->viewData('sources');
        $this->assertEquals(['students', 'marks', 'attendance', 'blank'], array_keys($teacherSources));

        // The builder lists must agree with the engine's own gate.
        $this->assertEquals(ReportSources::allowedFor('admin'), array_keys($adminSources));
        $this->assertEquals(ReportSources::allowedFor('super_admin'), array_keys($saSources));
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

    public function test_admin_and_principal_generate_small_sources()
    {
        $this->assertPdfResponse($this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $this->classesConfig()]));

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

    public function test_accountant_fees_generate_but_books_refused()
    {
        $accountant = $this->userByEmail('accountant@hackstra.app');
        $feesConfig = $this->config('fees', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'currency', 'width' => 12],
        ], ['class_id' => 1]);
        $this->assertPdfResponse($this->actingAs($accountant)
            ->post(route('reports.pdf'), ['config' => $feesConfig]));

        $booksConfig = $this->config('books', [
            ['key' => 'name', 'label' => 'Book Title', 'type' => 'text', 'width' => 28],
        ]);
        $this->actingAs($accountant)->post(route('reports.pdf'), [
            'config' => $booksConfig,
        ])->assertForbidden();
    }

    public function test_librarian_books_and_blank_generate_but_fees_refused()
    {
        $librarian = $this->userByEmail('librarian@hackstra.app');
        $booksConfig = $this->config('books', [
            ['key' => 'name', 'label' => 'Book Title', 'type' => 'text', 'width' => 28],
            ['key' => 'author', 'label' => 'Author', 'type' => 'text', 'width' => 20],
        ]);
        $this->assertPdfResponse($this->actingAs($librarian)
            ->post(route('reports.pdf'), ['config' => $booksConfig]));

        // Blank sheet: offered to librarians, must render (0 rows), never 500.
        $blankConfig = $this->config('blank', [
            ['key' => 'custom_1', 'label' => 'Notes', 'type' => 'text', 'width' => 30],
        ]);
        $this->assertPdfResponse($this->actingAs($librarian)
            ->post(route('reports.pdf'), ['config' => $blankConfig]));

        $feesConfig = $this->config('fees', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
        ], ['class_id' => 1]);
        $this->actingAs($librarian)->post(route('reports.pdf'), [
            'config' => $feesConfig,
        ])->assertForbidden();
    }

    public function test_blank_source_renders_for_teacher_and_accountant()
    {
        $blankConfig = $this->config('blank', [
            ['key' => 'custom_1', 'label' => 'Notes', 'type' => 'text', 'width' => 30],
        ]);
        $this->assertPdfResponse($this->actingAs($this->userByEmail('teacher@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $blankConfig]));
        $this->assertPdfResponse($this->actingAs($this->userByEmail('accountant@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => $blankConfig]));
    }

    public function test_sa_platform_ok_but_school_sources_refused()
    {
        $sa = $this->userByEmail('superadmin@hackstra.app');
        $this->assertPdfResponse($this->actingAs($sa)
            ->post(route('reports.pdf'), ['config' => $this->platformConfig()]));
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

    public function test_row_cap_admin_and_principal_pdf_and_preview()
    {
        $expected = 'Too many rows for one PDF (3041). Filter by class or section first, then generate.';
        $unfiltered = $this->studentsConfig();
        unset($unfiltered['filters']['class_id']);
        $unfiltered['filters'] = [];

        // Admin pdf + preview both refuse with the exact flash, never 500.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.pdf'), ['config' => $unfiltered])
            ->assertRedirect(route('reports.builder'))
            ->assertSessionHas('flash_danger', $expected);
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.preview'), ['config' => $unfiltered])
            ->assertRedirect(route('reports.builder'))
            ->assertSessionHas('flash_danger', $expected);

        // Principal pdf: same cap, same flash (principal resolves school-wide).
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->from(route('reports.builder'))
            ->post(route('reports.pdf'), ['config' => $unfiltered])
            ->assertRedirect(route('reports.builder'))
            ->assertSessionHas('flash_danger', $expected);
    }

    public function test_templates_cross_role_privacy_sa_and_principal_variants()
    {
        $admin = $this->userByEmail('admin@hackstra.app');
        $principal = $this->userByEmail('principal@hackstra.app');
        $sa = $this->userByEmail('superadmin@hackstra.app');

        $this->actingAs($admin)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'R28 Admin Tpl',
                'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $adminTpl = ReportTemplate::where('name', 'R28 Admin Tpl')->where('user_id', $admin->id)->firstOrFail();

        $this->actingAs($principal)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), [
                'name' => 'R28 Principal Tpl',
                'config' => $this->studentsConfig(1),
            ])->assertRedirect();
        $principalTpl = ReportTemplate::where('name', 'R28 Principal Tpl')->where('user_id', $principal->id)->firstOrFail();

        // SA cannot touch a school template (update / destroy / duplicate all 403).
        $this->actingAs($sa)->from(route('reports.builder'))
            ->put(route('reports.templates.update', [$adminTpl->id]), [
                'name' => 'Hijacked', 'config' => $this->studentsConfig(1),
            ])->assertForbidden();
        $this->actingAs($sa)->from(route('reports.builder'))
            ->delete(route('reports.templates.destroy', [$adminTpl->id]))->assertForbidden();
        $this->actingAs($sa)->from(route('reports.builder'))
            ->post(route('reports.templates.duplicate', [$adminTpl->id]))->assertForbidden();

        // Admin cannot touch the principal's template either (owner-private both ways).
        $this->actingAs($admin)->from(route('reports.builder'))
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
        $adminTpls = $this->actingAs($admin)->get(route('reports.builder'))->viewData('templates');
        $this->assertEquals([$adminTpl->id], collect($adminTpls)->pluck('id')->sort()->values()->all());
        $saTpls = $this->actingAs($sa)->get(route('reports.builder'))->viewData('templates');
        $this->assertCount(0, $saTpls);
    }

    public function test_unknown_source_admin_pdf_refused_cleanly()
    {
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->from(route('reports.builder'))
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
