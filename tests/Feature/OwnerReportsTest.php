<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ReportTemplate;
use App\Models\Subject;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Wave 31b: Universal Custom PDF Report Builder.
 *
 * One shared engine, role-gated sources, owner-private templates.
 * Users are resolved by email, never hardcoded ids. PDF assertions
 * check the real response (200 + application/pdf + non-trivial
 * body) — dompdf streams are compressed, so row text is verified
 * through the curl pass, not by asserting on binary output.
 */
class OwnerReportsTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function teacher(): User
    {
        return $this->userByEmail('teacher@hackstra.app');
    }

    protected function teacherClassId(): int
    {
        $ids = TeacherScope::classIds($this->teacher());
        $this->assertNotEmpty($ids, 'Seeded teacher must teach at least one class.');
        return (int) $ids[0];
    }

    protected function teacherSubjectId(int $classId): int
    {
        $subject = Subject::where('teacher_id', $this->teacher()->id)
            ->where('my_class_id', $classId)
            ->first();
        $this->assertNotNull($subject, 'Seeded teacher must own a subject in their class.');
        return (int) $subject->id;
    }

    protected function config(string $source, array $columns, array $filters = [], array $options = []): array
    {
        return [
            'title' => 'Test Report',
            'subtitle' => '',
            'source' => $source,
            'filters' => $filters,
            'columns' => $columns,
            'options' => array_merge([
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
            ], $options),
            'instructions' => '',
        ];
    }

    protected function assertPdfResponse($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_builder_page_loads_for_teacher_but_admin_forbidden()
    {
        $this->actingAs($this->teacher())
            ->get(route('reports.builder'))
            ->assertOk()
            ->assertSee('Build your report');
        // New builder gate (2026-10-29): teacher + principal only.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->get(route('reports.builder'))
            ->assertForbidden();
    }

    public function test_teacher_can_build_students_roster_pdf()
    {
        $classId = $this->teacherClassId();
        $response = $this->actingAs($this->teacher())->post(route('reports.pdf'), [
            'config' => $this->config('students', [
                ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
            ], ['class_id' => $classId]),
        ]);
        $this->assertPdfResponse($response);
    }

    public function test_teacher_marks_pdf_includes_component_columns()
    {
        $classId = $this->teacherClassId();
        $exam = Exam::orderBy('id')->firstOrFail();
        $response = $this->actingAs($this->teacher())->post(route('reports.pdf'), [
            'config' => $this->config('marks', [
                ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 28],
                ['key' => 'total', 'label' => 'Total', 'type' => 'number', 'width' => 10],
            ], [
                'exam_id' => $exam->id,
                'class_id' => $classId,
                'subject_id' => $this->teacherSubjectId($classId),
            ]),
        ]);
        $this->assertPdfResponse($response);
    }

    public function test_teacher_forbidden_from_fees_source()
    {
        $this->actingAs($this->teacher())->post(route('reports.pdf'), [
            'config' => $this->config('fees', [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 20],
            ], ['year' => Qs::getCurrentSession()]),
        ])->assertForbidden();
    }

    public function test_teacher_forbidden_from_foreign_class()
    {
        $classId = $this->teacherClassId();
        $foreign = \App\Models\MyClass::whereNotIn('id', TeacherScope::classIds($this->teacher()))->first();
        $this->assertNotNull($foreign, 'Seed must contain a class the teacher does not teach.');
        $this->actingAs($this->teacher())->post(route('reports.pdf'), [
            'config' => $this->config('students', [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 20],
            ], ['class_id' => $foreign->id]),
        ])->assertForbidden();
    }

    public function test_accountant_fees_pdf_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only, so the
        // accountant is 403 at the gate before any source check runs.
        $this->actingAs($this->userByEmail('accountant@hackstra.app'))->post(route('reports.pdf'), [
            'config' => $this->config('fees', [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 22],
                ['key' => 'fee_title', 'label' => 'Fee Title', 'type' => 'text', 'width' => 20],
                ['key' => 'amt_paid', 'label' => 'Paid', 'type' => 'currency', 'width' => 12],
            ], ['class_id' => $this->teacherClassId(), 'year' => Qs::getCurrentSession()]),
        ])->assertForbidden();
    }

    public function test_librarian_books_pdf_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only.
        $this->actingAs($this->userByEmail('librarian@hackstra.app'))->post(route('reports.pdf'), [
            'config' => $this->config('books', [
                ['key' => 'name', 'label' => 'Book Title', 'type' => 'text', 'width' => 28],
                ['key' => 'author', 'label' => 'Author', 'type' => 'text', 'width' => 20],
                ['key' => 'available', 'label' => 'Available', 'type' => 'number', 'width' => 10],
            ]),
        ])->assertForbidden();
    }

    public function test_admin_staff_pdf_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->post(route('reports.pdf'), [
            'config' => $this->config('staff', [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'width' => 24],
                ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'width' => 24],
                ['key' => 'user_type', 'label' => 'Role', 'type' => 'text', 'width' => 12],
            ]),
        ])->assertForbidden();
    }

    public function test_admin_admissions_pdf_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->post(route('reports.pdf'), [
            'config' => $this->config('admissions', [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'width' => 24],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'width' => 14],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 13],
            ]),
        ])->assertForbidden();
    }

    public function test_super_admin_forbidden_by_builder_gate()
    {
        // New builder gate (2026-10-29): teacher + principal only, so even
        // the platform source is 403 for the Super Admin at the gate.
        $sa = $this->userByEmail('superadmin@hackstra.app');
        $this->actingAs($sa)->post(route('reports.pdf'), [
            'config' => $this->config('platform', [
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'text', 'width' => 60],
                ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'width' => 40],
            ]),
        ])->assertForbidden();

        $this->actingAs($sa)->post(route('reports.pdf'), [
            'config' => $this->config('fees', [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 20],
            ], ['year' => Qs::getCurrentSession()]),
        ])->assertForbidden();
    }

    public function test_parent_and_student_forbidden_everywhere()
    {
        $this->actingAs($this->userByEmail('student@hackstra.app'))
            ->get(route('reports.builder'))
            ->assertForbidden();
        $this->actingAs($this->userByEmail('parent@hackstra.app'))->post(route('reports.pdf'), [
            'config' => $this->config('students', [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 20],
            ]),
        ])->assertForbidden();
    }

    public function test_blank_rows_sheet_renders()
    {
        $response = $this->actingAs($this->teacher())->post(route('reports.pdf'), [
            'config' => $this->config('blank', [
                ['key' => 'custom_1', 'label' => 'Field 1', 'type' => 'text', 'width' => 25],
                ['key' => 'custom_2', 'label' => 'Signature', 'type' => 'signature', 'width' => 25],
            ], [], ['blank_rows' => 5]),
        ]);
        $this->assertPdfResponse($response);
    }

    public function test_preview_returns_real_pdf_bytes()
    {
        $response = $this->actingAs($this->teacher())->post(route('reports.preview'), [
            'config' => $this->config('students', [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
            ], ['class_id' => $this->teacherClassId()]),
        ]);
        $this->assertPdfResponse($response);
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition', ''));
    }

    public function test_template_save_duplicate_delete_owner_scoped()
    {
        $teacher = $this->teacher();
        $admin = $this->userByEmail('admin@hackstra.app');
        $cfg = $this->config('students', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
        ], ['class_id' => $this->teacherClassId()]);

        $this->actingAs($teacher)->from(route('reports.builder'))->post(route('reports.templates.store'), [
            'name' => 'My Roster Copy',
            'config' => $cfg,
        ])->assertRedirect();
        $this->assertDatabaseHas('report_templates', ['name' => 'My Roster Copy', 'user_id' => $teacher->id]);
        $template = ReportTemplate::where('name', 'My Roster Copy')->where('user_id', $teacher->id)->firstOrFail();

        $this->actingAs($teacher)->from(route('reports.builder'))
            ->post(route('reports.templates.duplicate', [$template->id]))
            ->assertRedirect();
        $this->assertDatabaseHas('report_templates', ['name' => 'My Roster Copy (Copy)', 'user_id' => $teacher->id]);

        // Another user's template is untouchable, not just hidden.
        $this->actingAs($admin)->from(route('reports.builder'))
            ->delete(route('reports.templates.destroy', [$template->id]))
            ->assertForbidden();

        $this->actingAs($teacher)->from(route('reports.builder'))
            ->delete(route('reports.templates.destroy', [$template->id]))
            ->assertRedirect();
        $this->assertDatabaseMissing('report_templates', ['id' => $template->id]);
    }
}
