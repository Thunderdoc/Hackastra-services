<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sidebar tree motion (2026-11-06, owner: the Premium Tree B
 * branches "look blended" — the promised animation was missing).
 * ADDITIONS ONLY over item 9 + the fix-round-2 restatements:
 * the open submenu's connector draws in, child rows stagger,
 * the open page's node pulses on a new ::after, branch labels
 * nudge on hover. Stylesheet-content pins in the item-9 style;
 * the menu markup itself is untouched (href sequences stay
 * covered by the existing sidebar suites).
 */
class OwnerSidebarTreeMotionTest extends TestCase
{
    private function block(): string
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $pos = strpos($css, 'Sidebar tree motion');
        $this->assertNotFalse($pos, 'tree motion block missing from qs.css');
        return substr($css, $pos);
    }

    public function test_connector_draws_in_when_a_submenu_is_open()
    {
        $block = $this->block();
        $this->assertStringContainsString('@keyframes hkTreeDraw', $block);
        $this->assertStringContainsString('transform:scaleY(0)', $block);
        $this->assertStringContainsString('transform:scaleY(1)', $block);
        $this->assertStringContainsString(
            '.nav-item-open>.nav-group-sub::before', $block);
        $this->assertStringContainsString(
            '.nav-item-expanded>.nav-group-sub::before', $block);
        $this->assertStringContainsString(
            'animation:hkTreeDraw .5s ease-out both;transform-origin:top', $block);
    }

    public function test_child_rows_stagger_in()
    {
        $block = $this->block();
        $this->assertStringContainsString('@keyframes hkTreeItemIn', $block);
        $this->assertStringContainsString('transform:translateX(6px)', $block);
        $this->assertStringContainsString(
            'animation:hkTreeItemIn .35s ease-out both', $block);
        foreach ([1 => '.04s', 2 => '.08s', 5 => '.2s', 10 => '.4s'] as $n => $delay) {
            $this->assertStringContainsString(
                '.nav-item:nth-child(' . $n . ')>.nav-link', $block);
            $this->assertStringContainsString('animation-delay:' . $delay, $block);
        }
    }

    public function test_open_page_node_pulses_on_a_new_after_ring()
    {
        $block = $this->block();
        $this->assertStringContainsString('@keyframes hkNodePulse', $block);
        $this->assertStringContainsString(
            '.nav-link.active::after{animation:hkNodePulse 2.4s ease-out infinite', $block);
        $this->assertStringContainsString('box-shadow:0 0 0 9px rgba(89,169,255,0)', $block);
        // The pinned ::before node declarations stay as they were.
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString(
            'box-shadow:0 0 0 4px rgba(89,169,255,.25), 0 0 12px rgba(89,169,255,.8)', $css);
    }

    public function test_branch_labels_nudge_on_hover_and_motion_is_killed_for_reduced_and_print()
    {
        $block = $this->block();
        $this->assertStringContainsString(
            '.nav-item-submenu>.nav-link>span{display:inline-block;transition:transform .18s ease', $block);
        $this->assertStringContainsString(
            '.nav-item-submenu>.nav-link:hover>span{transform:translateX(2px)}', $block);
        $this->assertStringContainsString('body:not(.sidebar-xs)', $block);

        foreach (['@media (prefers-reduced-motion:reduce)', '@media print'] as $media) {
            $mpos = strpos($block, $media);
            $this->assertNotFalse($mpos, $media . ' guard missing');
            $guard = substr($block, $mpos, 900);
            $this->assertStringContainsString('.nav-group-sub::before', $guard);
            $this->assertStringContainsString('.nav-link.active::after', $guard);
            $this->assertStringContainsString('animation:none!important', $guard);
        }
    }
}
