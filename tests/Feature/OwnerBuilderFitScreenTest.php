<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: the Report Builder is fit-to-screen —
 * after the sidebar, the screen splits into TWO EQUAL halves:
 * every input on the left (one step at a time, so nothing needs
 * to scroll), the live PDF on the right, updating on its own as
 * any input changes. Simple: no extra buttons, no extra panels.
 */
class OwnerBuilderFitScreenTest extends TestCase
{
    use DatabaseTransactions;

    public function test_builder_is_two_equal_halves_with_tabbed_inputs()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $response = $this->actingAs($admin)->get(route('reports.builder'));
        $response->assertOk();

        // Two equal halves.
        $response->assertSee('col-lg-6 rb2-settings', false);
        $response->assertSee('col-lg-6 rb2-preview', false);
        $response->assertSee('calc(100dvh - 208px)', false);

        // The four steps are tabs over panels on the left.
        foreach (['data', 'filters', 'columns', 'finish'] as $step) {
            $response->assertSee('data-rb4-tab="' . $step . '"', false);
            $response->assertSee('data-rb4-panel="' . $step . '"', false);
        }

        // Category shortcuts live inside the left half now.
        $html = $response->getContent();
        $this->assertGreaterThan(
            strpos($html, 'id="rbSettings"'),
            strpos($html, 'id="rbCatBar"'),
            'Category chips belong to the left (inputs) half');

        // The live preview contract is untouched.
        $response->assertSee('Live preview');
        $response->assertSee('id="previewFrame"', false);
    }
}
