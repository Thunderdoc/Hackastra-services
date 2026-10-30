<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Builder rb5 (owner, 2026-10-30): the editable-document canvas.
 * POST reports/preview-html returns the SAME print view as HTML
 * with editable regions marked; paper size and the footer line
 * are real engine options; the role gate and the row cap hold on
 * the new endpoint exactly as on the PDF paths.
 */
class OwnerBuilderCanvasTest extends TestCase
{
    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function config(array $options = []): array
    {
        return [
            'title' => 'Prep Roster',
            'subtitle' => 'Academic Year: 2026 - 2027',
            'source' => 'students',
            'filters' => ['class_id' => 1],
            'columns' => [
                ['key' => 'roll_no', 'label' => 'Roll No', 'type' => 'number', 'width' => 8],
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
            ],
            'options' => array_merge([
                'orientation' => 'portrait', 'margin_mm' => 10, 'font_size' => 11,
                'show_header' => true, 'show_meta' => true, 'show_footer' => true,
                'show_signatures' => false, 'blank_rows' => 0, 'totals' => [], 'sort' => null,
            ], $options),
            'instructions' => '',
        ];
    }

    public function test_canvas_html_is_the_real_document_with_editable_regions()
    {
        $admin = $this->user('admin@hackstra.app');
        $response = $this->actingAs($admin)->post(route('reports.preview_html'), [
            'config' => json_encode($this->config(['footer_text' => 'Hackstra Academy'])),
        ]);
        $response->assertOk();
        $html = $response->getContent();
        // The real document, real records (Prep's first student).
        $this->assertStringContainsString('doc-title', $html);
        $this->assertStringContainsString('Aarav Sharma', $html);
        // Editable regions are marked for the workspace.
        $this->assertStringContainsString('data-edit="title"', $html);
        $this->assertStringContainsString('data-edit="subtitle"', $html);
        $this->assertStringContainsString('data-edit="footer"', $html);
        $this->assertStringContainsString('Hackstra Academy', $html);
        // Canvas-only CSS rides along; the PDF path never sees it.
        $this->assertStringContainsString('position: static', $html);
    }

    public function test_paper_size_flows_to_the_document()
    {
        $admin = $this->user('admin@hackstra.app');
        $letter = $this->actingAs($admin)->post(route('reports.preview_html'), [
            'config' => json_encode($this->config(['paper' => 'letter'])),
        ]);
        $letter->assertOk();
        $this->assertStringContainsString('size: LETTER', $letter->getContent());

        $a4 = $this->actingAs($admin)->post(route('reports.preview_html'), [
            'config' => json_encode($this->config()),
        ]);
        $this->assertStringContainsString('size: A4', $a4->getContent());
    }

    public function test_canvas_endpoint_keeps_the_role_gate_and_row_cap()
    {
        $accountant = $this->user('accountant@hackstra.app');
        $this->actingAs($accountant)->post(route('reports.preview_html'), [
            'config' => json_encode($this->config()),
        ])->assertForbidden();

        // Unfiltered students = 3041 rows — the cap refuses with
        // the engine's own message, as an error document.
        $admin = $this->user('admin@hackstra.app');
        $cfg = $this->config();
        $cfg['filters'] = [];
        $response = $this->actingAs($admin)->post(route('reports.preview_html'), [
            'config' => json_encode($cfg),
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('Too many rows for one PDF (3041)', $response->getContent());
    }

    public function test_pdf_download_still_works_with_new_options()
    {
        $admin = $this->user('admin@hackstra.app');
        $response = $this->actingAs($admin)->post(route('reports.pdf'), [
            'config' => json_encode($this->config(['paper' => 'letter', 'footer_text' => 'Hackstra Academy'])),
        ]);
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
    }
}
