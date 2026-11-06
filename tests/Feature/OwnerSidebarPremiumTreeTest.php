<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Item 9 (owner choice, 2026-11-01): sidebar "B — Premium Tree".
 * The Oct-29 tree classes are upgraded by appended rules in qs.css:
 * gradient connector line, ~11px ringed nodes, glowing white node
 * + soft blue tint row for the open page. Stylesheet-content pins
 * (fit-screen precedent) — menu names/links/order are untouched and
 * stay covered by the existing sidebar href-sequence pins.
 */
class OwnerSidebarPremiumTreeTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    public function test_stylesheet_carries_the_gradient_connector_line()
    {
        $css = $this->css();
        $this->assertStringContainsString(
            'linear-gradient(180deg, rgba(94,168,255,.55), rgba(94,168,255,.08))', $css);
        $this->assertStringContainsString('.nav-group-sub::before{width:2px;left:5.5px', $css);
    }

    public function test_stylesheet_carries_the_glowing_active_node_and_tint_row()
    {
        $css = $this->css();
        // Node grows to ~11px with the steel-blue ring.
        $this->assertStringContainsString('width:11px;height:11px', $css);
        $this->assertStringContainsString('border:2px solid #33597d', $css);
        // Open page: white core, bright ring, halo + glow shadow.
        $this->assertStringContainsString(
            'box-shadow:0 0 0 4px rgba(89,169,255,.25), 0 0 12px rgba(89,169,255,.8)', $css);
        // Open row: soft blue tint.
        $this->assertStringContainsString('.nav-link.active{background:rgba(64,156,255,.13)', $css);
    }

    public function test_premium_rules_are_scoped_to_the_expanded_sidebar()
    {
        $css = $this->css();
        // Every premium-tree rule hangs off body:not(.sidebar-xs) so the
        // collapsed rail keeps its plain look.
        $pos = strpos($css, 'Sidebar Premium Tree');
        $this->assertNotFalse($pos);
        // Bound the scan to the item-9 block itself (re-expressed
        // 2026-11-01): later items append their own rules after this
        // block, and those are not sidebar rules — the intent is
        // that the PREMIUM TREE rules stay scoped, nothing more.
        $end = strpos($css, 'Marks selector Light Luxe', $pos);
        $this->assertNotFalse($end);
        $block = substr($css, $pos, $end - $pos);
        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if (strpos($line, '{') === false) {
                continue; // comments and blank lines carry no rules
            }
            $this->assertStringStartsWith('body:not(.sidebar-xs)', $line, 'premium rule escapes the expanded sidebar: ' . $line);
        }
    }
}
