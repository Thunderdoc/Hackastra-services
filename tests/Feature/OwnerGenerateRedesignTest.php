<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 18 (2026-11-01): Auto-Generate Timetables rebuild. The two
 * named defects — the Generate-for switch hid nothing, and the
 * subject list never filtered by class in One-class mode — are
 * client-side behaviours, so the pins assert the wiring that
 * implements them (the pattern of the item-7 family pins), plus
 * the full POST contract staying intact.
 */
class OwnerGenerateRedesignTest extends TestCase
{
    use DatabaseTransactions;

    private function page(): string
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        return $this->actingAs($admin)->get(route('tt.generate.form'))->assertOk()->getContent();
    }

    public function test_generate_page_carries_the_rebuild_chrome()
    {
        $html = $this->page();

        // Segmented Generate-for + steps + sticky bar + timeline.
        $this->assertStringContainsString('id="gen-seg"', $html);
        $this->assertStringContainsString('data-scope="all"', $html);
        $this->assertStringContainsString('data-scope="one"', $html);
        $this->assertStringContainsString('name="scope" id="scope"', $html);
        $this->assertStringContainsString('gen-sticky', $html);
        $this->assertStringContainsString('id="gen-timeline"', $html);
        $this->assertStringContainsString('id="gen-sum-main"', $html);
        $this->assertStringContainsString('Generate Timetables</button>', $html);

        // Level presets are summary cards with Edit toggles, and
        // their fields exist exactly once per level.
        $this->assertStringContainsString('gen-lvl-sum-primary', $html);
        $this->assertStringContainsString('gen-lvl-sum-secondary', $html);
        $this->assertSame(1, substr_count($html, 'name="primary_periods_per_day"'));
        $this->assertSame(1, substr_count($html, 'name="secondary_periods_per_day"'));
        $this->assertSame(1, substr_count($html, 'name="periods_per_day"'));

        // Subjects: 13 class pills + 13 panels + the POST contract.
        $this->assertSame(13, substr_count($html, 'class="gen-cpill" data-class-pill="'));
        $this->assertSame(13, substr_count($html, 'gen-class-panel" data-class-panel="'));
        $this->assertStringContainsString('name="subjects[13][]"', $html);
        $this->assertStringContainsString('name="subjects_present[13]"', $html);
        $this->assertStringContainsString('id="tt-subject-filter"', $html);
    }

    public function test_mode_switch_and_one_class_subject_filter_are_wired()
    {
        $html = $this->page();

        // applyMode shows one-class blocks only in one-mode and the
        // level presets only in all-mode (the "hides nothing" defect).
        $this->assertStringContainsString('function applyMode()', $html);
        $this->assertStringContainsString("querySelectorAll('.gen-one-only')", $html);
        $this->assertStringContainsString("querySelectorAll('.gen-all-only')", $html);

        // renderPanels narrows the visible subject panels to the
        // chosen class in one-mode (the "never filters" defect).
        $this->assertStringContainsString('function renderPanels()', $html);
        $this->assertStringContainsString("id === chosen", $html);
        $this->assertStringContainsString('gen-subj-hint', $html);

        // The timeline is computed from the active field set.
        $this->assertStringContainsString('function dayPlan(prefix)', $html);
        $this->assertStringContainsString('function renderTimeline()', $html);
    }

    public function test_generate_page_has_no_working_note_text_and_chips_have_a_dark_treatment()
    {
        $html = $this->page();

        // Visual-coherence round (2026-11-06): leftover Hinglish
        // working notes rendered on the page — they are gone, the
        // English explanations carry the same meaning.
        $this->assertStringNotContainsString('Sirf dekhne', $html);
        $this->assertStringNotContainsString('dobara nahi bharne', $html);
        $this->assertStringNotContainsString('wale kabhi replace', $html);
        $this->assertStringNotContainsString('jinpar attendance', $html);

        // The selected-subject chips (.gen-subj) had NO dark rule
        // — near-white pills in dark mode. The stylesheet now
        // gives selected chips the app-blue tint with light text,
        // and restates .off muted at higher specificity.
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('html.hk-dark .gen-subj{background:rgba(52,120,246,.16)', $css);
        $this->assertStringContainsString('html.hk-dark .gen-subj.off{background:rgba(255,255,255,.04)', $css);
    }

    public function test_manual_create_form_uses_native_selects()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $html = $this->actingAs($admin)->get(route('tt.index'))->assertOk()->getContent();
        $start = strpos($html, 'id="tt-create"');
        $this->assertNotFalse($start);
        $form = substr($html, $start, 4000);
        // No select2 ("select" / "select-search") classes inside the
        // create form — native selects cannot overlay or flicker.
        $this->assertStringNotContainsString('form-control select"', $form);
        $this->assertStringNotContainsString('select-search', $form);
        $this->assertStringContainsString('name="my_class_id"', $form);
    }
}
