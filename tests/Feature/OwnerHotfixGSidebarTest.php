<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\UserPreference;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX G (2026-11-08, owner screenshots 10:58-10:59 IST) —
 * sidebar cutting defects.
 *
 * (1) Collapsed rail: the Design B shell's .sidebar-content
 * side padding (12px, meant for the EXPANDED rail's group
 * cards) also applied in the 3.5rem mini rail, shifting the
 * nav column 12px right so links spilled ~11px past the rail
 * edge and the scroller's overflow-x:hidden clipped the
 * active highlight flush at the edge; and the base theme's
 * collapsed centring corrections (link padding 0, icon
 * margin 0) were tied on specificity by the Loop 91 row
 * rules and lost on file order, leaving icons ~6px off
 * centre. The appended Hotfix G block in qs.css restates
 * all three at winning shapes; measured after: links
 * x=0 -> right=56 inside the 57px rail, icon centre = 28 =
 * rail centre (1920x860, dark + light).
 *
 * (2) Expanded rail on short viewports: the Loop 35 zones
 * (brand pinned top, .card-sidebar-mobile scrolling
 * internally, .hk-sb-userchip pinned bottom in real layout
 * space) were measured intact for student / teacher / admin
 * at 1366x768 and 1920x860 in both themes; the Hotfix G
 * block re-asserts them at B-shell specificity as the
 * file's final word so the chip can never sit in flow over
 * the list.
 *
 * (3) The timetable record pages (ttr.show / ttr.manage)
 * keep their standing forced mini rail (master layout —
 * deliberate since Loop 25, restated in the Round 5 P4
 * comment); the fix above is what makes that rail render
 * correctly, so the force itself is pinned as-is.
 *
 * (4) The student's "Select Exam Year" page is
 * marks/select_year.blade.php — already opted into the
 * form_page form_compact hug (rendered card measured at
 * exactly 680px); pinned here alongside the sidebar work
 * from the same screenshot.
 */
class OwnerHotfixGSidebarTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function bodyTag(string $html): string
    {
        $this->assertSame(1, preg_match('/<body class="[^"]*"/', $html, $m));

        return $m[0];
    }

    public function test_collapsed_rail_geometry_rules_are_restated_at_winning_shapes()
    {
        $css = $this->css();

        // Full rail width for the collapsed content column.
        $this->assertStringContainsString(
            'body.sidebar-xs .sidebar-main.hk-sb-b .sidebar-content{padding-left:0;padding-right:0}',
            $css);
        // The base theme's centring corrections, past the
        // Loop 91 ties (padding:10px 12px / margin-right:12px).
        $this->assertStringContainsString(
            'body.sidebar-xs .sidebar.sidebar-main.hk-sb-b .nav-sidebar>.nav-item>.nav-link{padding-left:0;padding-right:0}',
            $css);
        $this->assertStringContainsString(
            'body.sidebar-xs .sidebar.sidebar-main.hk-sb-b .nav-sidebar>.nav-item>.nav-link>i{margin-right:0}',
            $css);
    }

    public function test_expanded_rail_zone_lock_rules_are_present()
    {
        $css = $this->css();

        $this->assertStringContainsString(
            '.sidebar.sidebar-main.hk-sb-b{display:flex;flex-direction:column}', $css);
        $this->assertStringContainsString(
            '.sidebar.sidebar-main.hk-sb-b .sidebar-content{flex:1 1 auto;min-height:0}', $css);
        $this->assertStringContainsString(
            '.sidebar.sidebar-main.hk-sb-b .card-sidebar-mobile{flex:1 1 auto;min-height:0;overflow-y:auto}', $css);
        $this->assertStringContainsString(
            '.sidebar.sidebar-main.hk-sb-b .hk-sb-userchip{flex:0 0 auto}', $css);
    }

    public function test_user_chip_lives_in_the_content_column_and_is_flex_ordered_last()
    {
        $html = $this->actingAs($this->user('student@hackstra.app'))
            ->get(route('dashboard'))->assertOk()->getContent();

        $content = strpos($html, 'sidebar-content');
        $card = strpos($html, 'card-sidebar-mobile');
        $chip = strpos($html, 'hk-sb-userchip');

        $this->assertNotFalse($content);
        $this->assertNotFalse($card);
        $this->assertNotFalse($chip);
        // Both the nav card (the scroller) and the chip live
        // inside the .sidebar-content flex column...
        $this->assertLessThan($card, $content);
        $this->assertLessThan($chip, $content);
        // ...and the chip is pinned BELOW the scroller by flex
        // order (DOM order in the blade is chip-first; the
        // visual zones come from these two rules).
        $css = $this->css();
        $this->assertStringContainsString(
            '.sidebar-main.hk-sb-b .card-sidebar-mobile{order:1}', $css);
        $this->assertSame(1, preg_match(
            '/\.hk-sb-userchip\{[^}]*order:2/', $css));
    }

    public function test_timetable_record_page_keeps_the_forced_mini_rail()
    {
        $student = $this->user('student@hackstra.app');
        UserPreference::where('user_id', $student->id)->delete();

        // A normal page honours the (empty) preference: expanded.
        $html = $this->actingAs($student)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('sidebar-xs', $this->bodyTag($html));

        // The record page itself forces the mini rail,
        // regardless of the stored preference.
        $sr = \App\Models\StudentRecord::where('user_id', $student->id)->firstOrFail();
        $record = \App\Models\TimeTableRecord::where('my_class_id', $sr->my_class_id)
            ->where(function ($q) use ($sr) {
                $q->where('section_id', $sr->section_id)->orWhereNull('section_id');
            })->firstOrFail();
        $html = $this->actingAs($student)->get(route('ttr.show', $record->id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('sidebar-xs', $this->bodyTag($html));
    }

    public function test_student_select_year_page_renders_the_compact_hug()
    {
        $student = $this->user('student@hackstra.app');

        $this->actingAs($student)
            ->get(route('marks.year_selector', Qs::hash($student->id)))
            ->assertOk()
            ->assertSee('pat-form-compact', false);
    }
}
