<?php

namespace Tests\Feature;

use App\Support\Reports\ReportSources;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Overnight builder stream (S3-BUILDER, 2026-10-29):
 *  - Item 7: Reports & PDF Builder is teacher + principal ONLY at the
 *    server gate. Admin (and accountant, librarian, super_admin,
 *    student, parent) must be refused on every builder route.
 *    Teacher scope stays TeacherScope::classIds; principal maps to
 *    admin sources in ReportSources::allowedFor (unchanged).
 *  - Item 6: live preview must be the download: same engine, same
 *    document. An invalid preview posted from the builder iframe
 *    (preview_frame=1) must return a 422 error document, never a
 *    redirect that loads the builder page inside the frame.
 *  - Item 5: two-panel builder page (settings left, preview right)
 *    with an honest status line and auto-update hooks.
 *  - Standing rule: resolve() still refuses >800 rows with the exact
 *    flash, on both the pdf and the preview path; preview never
 *    invents records (an empty result is the print blade's own
 *    "No records found" note, covered by the engine doc assertion).
 */
class OwnerOvernightBuilderTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function config(string $source, array $columns, array $filters = [], array $optionOverrides = []): array
    {
        return [
            'title' => 'Overnight Builder Report',
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
            ], $optionOverrides),
            'instructions' => '',
        ];
    }

    protected function blankConfig(): array
    {
        return $this->config('blank', [
            ['key' => 'custom_1', 'label' => 'Note', 'type' => 'text', 'width' => 50],
        ]);
    }

    protected function classesConfig(): array
    {
        return $this->config('classes', [
            ['key' => 'name', 'label' => 'Class', 'type' => 'text', 'width' => 20],
            ['key' => 'sections', 'label' => 'Sections', 'type' => 'number', 'width' => 12],
            ['key' => 'students', 'label' => 'Students', 'type' => 'number', 'width' => 12],
        ]);
    }

    protected function assertPdfResponse($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    // ---- Item 7: server gate — teacher + principal, and ADMIN
    // too since the owner's same-evening correction (he works in
    // the builder as Admin himself; the overnight teacher+
    // principal-only gate is reversed for admin). ----

    public function test_admin_is_allowed_on_every_builder_route()
    {
        $admin = $this->userByEmail('admin@hackstra.app');
        $this->actingAs($admin)->get(route('reports.builder'))->assertOk();
        $this->actingAs($admin)->post(route('reports.preview'), ['config' => json_encode($this->blankConfig())])->assertOk();
        $this->assertPdfResponse($this->actingAs($admin)->post(route('reports.pdf'), ['config' => json_encode($this->blankConfig())]));
        $this->actingAs($admin)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), ['config' => json_encode($this->blankConfig()), 'name' => 'Admin Tpl'])
            ->assertRedirect();
    }

    public function test_non_teacher_principal_roles_are_refused_on_builder()
    {
        foreach (['accountant@hackstra.app', 'librarian@hackstra.app', 'superadmin@hackstra.app', 'student@hackstra.app', 'parent@hackstra.app'] as $email) {
            $this->actingAs($this->userByEmail($email))->get(route('reports.builder'))->assertForbidden();
        }
    }

    public function test_teacher_and_principal_open_builder_and_principal_maps_to_admin_sources()
    {
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->get(route('reports.builder'))->assertOk();
        // Principal maps to the admin set PLUS the library sources
        // the Admin lost in the 2026-10-29 library purge (the
        // purge was about the Admin panel; the mapping is intact).
        $this->assertEqualsCanonicalizing(
            array_merge(ReportSources::allowedFor('admin'), ReportSources::LIBRARY_SOURCES),
            ReportSources::allowedFor('principal')
        );
        $this->assertEqualsCanonicalizing(
            ReportSources::LIBRARY_SOURCES,
            array_values(array_diff(ReportSources::allowedFor('principal'), ReportSources::allowedFor('admin')))
        );
        $this->assertNotContains('platform', ReportSources::allowedFor('principal'));
    }

    public function test_teacher_builder_classes_stay_scoped()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $classes = $this->actingAs($teacher)->get(route('reports.builder'))->viewData('classes');
        $ids = collect($classes)->map(function ($c) { return (int) $c->id; })->sort()->values()->all();
        $this->assertSame(collect(TeacherScope::classIds($teacher))->sort()->values()->all(), $ids);
    }

    public function test_principal_preview_and_pdf_work_on_admin_source()
    {
        $principal = $this->userByEmail('principal@hackstra.app');
        $this->assertPdfResponse($this->actingAs($principal)->post(route('reports.preview'), ['config' => json_encode($this->classesConfig())]));
        $pdf = $this->actingAs($principal)->post(route('reports.pdf'), ['config' => json_encode($this->classesConfig())]);
        $pdf->assertOk();
        $this->assertStringContainsString('attachment', $pdf->headers->get('Content-Disposition', ''));
    }

    // ---- Item 6: preview is the download; iframe errors are 422 ----

    public function test_teacher_preview_is_real_pdf_inline_and_pdf_is_download()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $preview = $this->actingAs($teacher)->post(route('reports.preview'), ['config' => json_encode($this->blankConfig())]);
        $this->assertPdfResponse($preview);
        $this->assertStringContainsString('inline', $preview->headers->get('Content-Disposition', ''));
        $pdf = $this->actingAs($teacher)->post(route('reports.pdf'), ['config' => json_encode($this->blankConfig())]);
        $pdf->assertOk();
        $this->assertStringContainsString('attachment', $pdf->headers->get('Content-Disposition', ''));
    }

    public function test_preview_frame_invalid_config_returns_422_error_document_not_redirect()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $bad = $this->blankConfig();
        $bad['title'] = '';
        $response = $this->actingAs($teacher)->post(route('reports.preview'), [
            'config' => json_encode($bad),
            'preview_frame' => '1',
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('data-rb-preview-error', $response->getContent());
        $this->assertStringContainsString('report title', strtolower($response->getContent()));
    }

    public function test_direct_preview_invalid_config_still_redirects_with_flash()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $bad = $this->blankConfig();
        $bad['title'] = '';
        $response = $this->actingAs($teacher)->from(route('reports.builder'))->post(route('reports.preview'), ['config' => json_encode($bad)]);
        $response->assertRedirect();
        $this->assertStringContainsString('report title', strtolower(session('flash_danger') ?? ''));
    }

    public function test_preview_and_pdf_both_refuse_over_800_rows_with_exact_flash()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $huge = $this->config('students', [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
        ], []);
        $pdf = $this->actingAs($teacher)->from(route('reports.builder'))->post(route('reports.pdf'), ['config' => json_encode($huge)]);
        $pdf->assertRedirect();
        $this->assertStringContainsString('Too many rows for one PDF', session('flash_danger') ?? '');
        $this->assertStringContainsString('Filter by class or section first, then generate.', session('flash_danger') ?? '');
        // Iframe path: same refusal, as a 422 error document, never a redirect-in-frame.
        $preview = $this->actingAs($teacher)->post(route('reports.preview'), ['config' => json_encode($huge), 'preview_frame' => '1']);
        $preview->assertStatus(422);
        $this->assertStringContainsString('Too many rows for one PDF', $preview->getContent());
    }

    public function test_preview_never_invents_records_for_empty_result()
    {
        // Engine-level: a students report filtered to a class/section
        // combination with no rows resolves to zero data rows.
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $scoped = TeacherScope::classIds($teacher);
        $this->assertNotEmpty($scoped, 'teacher fixture must have at least one scoped class');
        $config = \App\Support\Reports\ReportEngine::validateConfig(
            $this->config('students', [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
            ], ['class_id' => $scoped[0], 'section_id' => 999999]),
            $teacher
        );
        $doc = \App\Support\Reports\ReportEngine::resolve($config, $teacher);
        $this->assertSame([], $doc['rows']);
    }

    // ---- Item 5: two-panel page, honest states, auto-update hooks ----

    public function test_builder_page_is_two_panel_with_live_preview_states()
    {
        $response = $this->actingAs($this->userByEmail('teacher@hackstra.app'))->get(route('reports.builder'));
        $response->assertOk();
        $response->assertSee('rb-settings', false);
        $response->assertSee('rb-preview', false);
        $response->assertSee('rbPreviewStatus', false);
        $response->assertSee('preview_frame', false);
        $response->assertSee('schedulePreview', false);
        $response->assertSee('Live preview', false);
    }
}
