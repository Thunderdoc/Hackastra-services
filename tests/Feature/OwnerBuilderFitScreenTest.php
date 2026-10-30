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

        // The document side: toolbar + canvas + Print.
        $response->assertSee('id="rb5Toolbar"', false);
        $response->assertSee('id="previewFrame"', false);
        $response->assertSee('id="rbTbPrint"', false);
        $response->assertSee('id="rbPaper"', false);
        $response->assertSee('id="rbOrientation"', false);

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
