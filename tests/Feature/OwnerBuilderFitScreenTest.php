<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30 (rb5, full replacement): the Report
 * Builder is ONE fit-to-screen workspace in TWO panels — the
 * configuration on the left (43%), the live EDITABLE document
 * on the right (57%). No wizard tabs, no separate preview
 * screen, no Preview button: the document on the right IS the
 * preview, and Print prints only it.
 *
 * Simplification (owner, same evening — "fix this, and I do NOT
 * want all this stuff"): the right-panel toolbar card is DELETED
 * (font family/size, bold/italic/underline, colour, alignment,
 * zoom, undo/redo — all gone). Above the document sits ONE slim
 * header row: the "Report: …" summary + Print + Download PDF.
 * Paper + orientation moved into a collapsed "Page settings"
 * section in the LEFT panel. Report title / Subtitle / Sort By /
 * Sort Order have no visible fields — they ride as hidden inputs
 * (title/subtitle are edited in the document itself).
 */
class OwnerBuilderFitScreenTest extends TestCase
{
    use DatabaseTransactions;

    public function test_builder_is_two_panels_43_57_with_editable_document()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $response = $this->actingAs($admin)->get(route('reports.builder'));
        $response->assertOk();

        // The 43 / 57 split.
        $response->assertSee('flex: 0 0 43%', false);
        $response->assertSee('flex: 1 1 57%', false);
        $response->assertSee('id="rb5Wrap"', false);
        $response->assertSee('id="rbSettings"', false);

        // The document side: slim header + canvas. No toolbar.
        $response->assertSee('rb5-docbar', false);
        $response->assertSee('id="rbSummary"', false);
        $response->assertSee('id="rbDocPrint"', false);
        $response->assertSee('Download PDF', false);
        $response->assertSee('id="previewFrame"', false);

        // The deleted toolbar: every control is absent, not hidden.
        $response->assertDontSee('id="rb5Toolbar"', false);
        $response->assertDontSee('id="rbTbFont"', false);
        $response->assertDontSee('id="rbTbFontSize"', false);
        $response->assertDontSee('id="rbTbBold"', false);
        $response->assertDontSee('id="rbTbItalic"', false);
        $response->assertDontSee('id="rbTbUnderline"', false);
        $response->assertDontSee('id="rbTbColor"', false);
        $response->assertDontSee('id="rbTbAlignLeft"', false);
        $response->assertDontSee('id="rbTbAlignCenter"', false);
        $response->assertDontSee('id="rbTbAlignRight"', false);
        $response->assertDontSee('id="rbTbZoom"', false);
        $response->assertDontSee('id="rbTbUndo"', false);
        $response->assertDontSee('id="rbTbRedo"', false);
        $response->assertDontSee('id="rbTbPrint"', false);

        // Paper + orientation live in the LEFT panel's collapsed
        // Page settings section (closed by default).
        $response->assertSee('Page settings', false);
        $response->assertSee('class="collapse" id="rbPageSettings"', false);
        $response->assertSee('id="rbPaper"', false);
        $response->assertSee('id="rbOrientation"', false);

        // Title / subtitle / sort: hidden inputs only, no fields.
        $response->assertSee('<input type="hidden" id="rbTitle">', false);
        $response->assertSee('<input type="hidden" id="rbSubtitle">', false);
        $response->assertSee('<input type="hidden" id="rbSortKey" value="">', false);
        $response->assertSee('<input type="hidden" id="rbSortDir" value="asc">', false);
        $response->assertDontSee('<label>Report title *</label>', false);
        $response->assertDontSee('<label>Subtitle</label>', false);
        $response->assertDontSee('<label>Sort By</label>', false);
        $response->assertDontSee('<label>Sort Order</label>', false);
        // Font size control is gone entirely (engine default).
        $response->assertDontSee('id="rbFontSize"', false);

        // The old wizard is gone — ordered removed, not hidden.
        $response->assertDontSee('data-rb4-tab', false);
        $response->assertDontSee('data-rb4-panel', false);
        $response->assertDontSee('rbPreviewBtn', false);
        $response->assertDontSee('Refresh preview', false);

        // The config half precedes the document half in the DOM.
        $html = $response->getContent();
        $this->assertGreaterThan(
            strpos($html, 'id="rbSettings"'),
            strpos($html, 'id="previewFrame"'),
            'Configuration belongs to the left half, the document to the right');
    }
}
