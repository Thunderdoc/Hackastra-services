<?php

namespace Tests\Feature;

use App\Support\Reports\ReportEngine;
use App\Support\Reports\ReportSources;
use App\User;
use Tests\TestCase;

/**
 * Owner order 2026-10-30 (evening): "the document must never be
 * blank." Students + All classes (the whole school) used to hit
 * the engine row-cap and REFUSE — the builder showed an error
 * above an empty white page and no PDF was produced. The cap now
 * TRUNCATES with a note inside the document, identically on the
 * canvas (preview-html) and the PDF paths, and the builder page
 * itself carries only the owner's keep-list chrome (no toolbar).
 *
 * All expectations are read live from the same source rows the
 * engine renders (adm_no is unique per student, so first / last /
 * first-excluded rows are pinned exactly, never by name).
 */
class OwnerBuilderTruncationTest extends TestCase
{
    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function overCapConfig(): array
    {
        return [
            'title' => 'Whole School Roster',
            'subtitle' => '',
            'source' => 'students',
            'filters' => [],
            'columns' => [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
            ],
            'options' => [
                'orientation' => 'portrait', 'margin_mm' => 10, 'font_size' => 11,
                'show_header' => true, 'show_meta' => true, 'show_footer' => true,
                // Fixed footer line: the default "Generated on …"
                // stamp is minute-resolution and would make the
                // preview/download byte-parity check flaky.
                'footer_text' => 'Hackstra Academy',
                'show_signatures' => false, 'blank_rows' => 0, 'totals' => [], 'sort' => null,
            ],
            'instructions' => '',
        ];
    }

    /** @return array{0: array, 1: int} raw source rows + their count */
    private function sourceRows(User $admin): array
    {
        $rows = ReportSources::rows('students', [], $admin);
        $this->assertGreaterThan(
            ReportEngine::MAX_ROWS,
            count($rows),
            'fixture precondition: the unfiltered roster must exceed the cap'
        );
        return [$rows, count($rows)];
    }

    private function expectedNote(int $total): string
    {
        return 'Showing first ' . ReportEngine::MAX_ROWS . ' of ' . $total
            . ' rows — narrow by class or section for the complete list.';
    }

    public function test_preview_html_renders_real_rows_and_the_truncation_note()
    {
        $admin = $this->admin();
        [$rows, $total] = $this->sourceRows($admin);
        $cap = ReportEngine::MAX_ROWS;

        $response = $this->actingAs($admin)->post(route('reports.preview_html'), [
            'config' => json_encode($this->overCapConfig()),
        ]);
        $response->assertOk();
        $html = $response->getContent();

        // Real content, never a blank page: the note, the first
        // row, and the last row that fits under the cap.
        $this->assertStringContainsString($this->expectedNote($total), $html);
        $this->assertStringContainsString((string) $rows[0]['student'], $html);
        $this->assertStringContainsString((string) $rows[0]['adm_no'], $html);
        $this->assertStringContainsString((string) $rows[$cap - 1]['adm_no'], $html);
        // The first row BEYOND the cap is not in the document.
        $this->assertStringNotContainsString((string) $rows[$cap]['adm_no'], $html);
    }

    public function test_pdf_endpoint_returns_a_real_pdf_for_the_over_cap_config()
    {
        $admin = $this->admin();
        $this->sourceRows($admin);

        $response = $this->actingAs($admin)->post(route('reports.pdf'), [
            'config' => json_encode($this->overCapConfig()),
        ]);
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(10000, strlen($response->getContent()));
    }

    public function test_preview_and_pdf_carry_the_same_truncated_row_set()
    {
        $admin = $this->admin();
        [$rows, $total] = $this->sourceRows($admin);
        $cap = ReportEngine::MAX_ROWS;
        $config = $this->overCapConfig();

        // Engine truth: exactly cap rows, the note, and the
        // first/last cells are the source's first/cap-th rows.
        $doc = ReportEngine::resolve(ReportEngine::validateConfig($config, $admin), $admin);
        $this->assertCount($cap, $doc['rows']);
        $this->assertSame($this->expectedNote($total), $doc['truncation_note']);
        $this->assertSame((string) $rows[0]['adm_no'], $doc['rows'][0]['cells']['adm_no']);
        $this->assertSame((string) $rows[$cap - 1]['adm_no'], $doc['rows'][$cap - 1]['cells']['adm_no']);

        // The canvas renders that same set (note + first + last,
        // first-excluded absent — proven in the sibling test; here
        // the note and boundary rows are re-asserted against the
        // engine doc above).
        $html = $this->actingAs($admin)->post(route('reports.preview_html'), [
            'config' => json_encode($config),
        ])->getContent();
        $this->assertStringContainsString($doc['truncation_note'], $html);
        $this->assertStringContainsString($doc['rows'][0]['cells']['adm_no'], $html);
        $this->assertStringContainsString($doc['rows'][$cap - 1]['cells']['adm_no'], $html);

        // The two PDF paths are the same document byte-for-byte
        // (modulo PDF timestamps/IDs) — the truncated set the
        // preview shows is the set the download carries.
        $preview = $this->actingAs($admin)->post(route('reports.preview'), ['config' => json_encode($config)]);
        $preview->assertOk();
        $download = $this->actingAs($admin)->post(route('reports.pdf'), ['config' => json_encode($config)]);
        $download->assertOk();
        $this->assertSame(
            $this->normalizePdf($preview->getContent()),
            $this->normalizePdf($download->getContent()),
            'preview and download must be the same truncated document (modulo PDF timestamps/IDs)'
        );
    }

    public function test_builder_page_has_no_toolbar_and_keeps_the_owners_list()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        $html = $response->getContent();

        // None of the deleted toolbar controls exist.
        foreach (['rb5Toolbar', 'rbTbFont', 'rbTbFontSize', 'rbTbBold', 'rbTbItalic', 'rbTbUnderline',
                     'rbTbColor', 'rbTbAlignLeft', 'rbTbAlignCenter', 'rbTbAlignRight',
                     'rbTbZoom', 'rbTbUndo', 'rbTbRedo', 'rbTbPrint', 'rbFontSize'] as $gone) {
            $this->assertStringNotContainsString('id="' . $gone . '"', $html, $gone . ' must be deleted, not hidden');
        }

        // The keep-list: template pick, data source, class,
        // section, columns chooser, Print, Download PDF.
        $response->assertSee('id="rbPreset"', false);
        $response->assertSee('id="rbSource"', false);
        $response->assertSee('id="rbClass"', false);
        $response->assertSee('id="rbSection"', false);
        $response->assertSee('id="rbColBody"', false);
        $response->assertSee('id="rbDocPrint"', false);
        $response->assertSee('Download PDF', false);

        // Page settings: ONE collapsed section in the left panel,
        // closed by default, holding paper + orientation.
        $response->assertSee('class="collapse" id="rbPageSettings"', false);
        $response->assertSee('id="rbPaper"', false);
        $response->assertSee('id="rbOrientation"', false);
    }

    protected function normalizePdf(string $bytes): string
    {
        // dompdf embeds volatile metadata; parity is about the
        // document, not the timestamp. Strip only volatile fields.
        $out = preg_replace('/\/CreationDate\s*\(D:[^)]*\)/', '/CreationDate ()', $bytes);
        $out = preg_replace('/\/ModDate\s*\(D:[^)]*\)/', '/ModDate ()', $out);
        $out = preg_replace('/\/ID\s*\[[^\]]*\]/', '/ID []', $out);
        return $out ?? $bytes;
    }
}
