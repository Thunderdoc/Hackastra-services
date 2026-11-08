<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Selector hug (owner, 2026-11-07 — image-133): short
 * select-then-submit forms on the shared form_page pattern
 * stretched a full-width card around one small field (measured:
 * a lone 403px field inside a 1291px card at 1600px) with the
 * submit floating in a tall, near-empty action strip — "pure
 * site me issue hai". The pattern gained a compact mode: the
 * card caps at content width, fields take the card's full inner
 * width, and the section/actionbar padding stack tightens.
 * Long forms do not opt in. These pins hold the mode's wiring:
 * the component emits the class, the CSS rules exist, the
 * selector family renders compact, and the (healthy, full)
 * marks selector stays untouched.
 */
class OwnerSelectorHugTest extends TestCase
{
    use DatabaseTransactions;

    protected function file($rel)
    {
        return file_get_contents(base_path($rel));
    }

    public function test_pattern_component_and_css_carry_the_compact_mode()
    {
        $pattern = $this->file('resources/views/partials/patterns/form_page.blade.php');
        $this->assertStringContainsString('form_compact', $pattern);
        $this->assertStringContainsString('pat-form-compact', $pattern);

        $css = $this->file('public/assets/css/qs.css');
        $this->assertStringContainsString('.pat-form.pat-form-compact .pat-form-card{max-width:680px}', $css);
        $this->assertStringContainsString('.pat-form.pat-form-compact .pat-fields{grid-template-columns:minmax(0,1fr)}', $css);
        $this->assertStringContainsString('.pat-form.pat-form-compact .pat-actionbar{padding-top:10px;padding-bottom:10px}', $css);
    }

    public function test_select_exam_year_renders_compact()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();

        $resp = $this->actingAs($teacher)->get(route('marks.year_selector', Qs::hash($student->id)));
        $resp->assertOk();
        $resp->assertSee('pat-form-compact', false);
        $resp->assertSee('name="year"', false);
    }

    public function test_share_forms_opt_in_and_marks_selector_stays_full()
    {
        foreach (['assignments/share.blade.php', 'materials/share.blade.php'] as $rel) {
            $blade = $this->file('resources/views/pages/support_team/' . $rel);
            $this->assertStringContainsString("'form_compact' => true", $blade, $rel . ' must opt into compact mode');
        }

        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $resp = $this->actingAs($teacher)->get(route('marks.index'));
        $resp->assertOk();
        $resp->assertDontSee('pat-form-compact', false);
    }
}
