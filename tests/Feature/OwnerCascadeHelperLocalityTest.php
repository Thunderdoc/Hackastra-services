<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cascade helper locality (owner items 12+13, 2026-11-07): on
 * the live marks selector, picking a class applied the value
 * but the dropdown panel never closed and the Section dropdown
 * stayed at "No results found" — the cascade died and marks
 * entry was blocked. Root cause, reproduced interactively: the
 * cascade functions in the inline custom_js call
 * hkSelectLoading(), which existed ONLY in the external
 * hackstra-ux.js; with that file stale or missing in the
 * client (the service worker serves JS cache-first), the call
 * threw a ReferenceError inside the change dispatch — aborting
 * Select2's close step (panel stuck open) and the cascade
 * before its fetch. The fix defines the helper inline, guarded,
 * in the same script as the cascades; the hackstra-ux.js copy
 * stays canonical. These pins hold that contract: the guarded
 * inline definition exists, precedes the first cascade, the
 * canonical copy survives, and a teacher's marks page renders
 * with the fallback in place.
 *
 * Extended 2026-11-08 (finisher) to the skeleton renderer: the
 * attendance report + low-attendance pages call window.hkSkel
 * from guarded ready() checks, so with a stale hackstra-ux.js
 * the skeleton swap silently never happened — same trap family,
 * same remedy: guarded inline definitions (hkSkel + hkSkelHtml)
 * byte-semantic with the canonical originals, pinned below.
 */
class OwnerCascadeHelperLocalityTest extends TestCase
{
    use DatabaseTransactions;

    protected function file($rel)
    {
        return file_get_contents(base_path($rel));
    }

    public function test_inline_fallback_is_defined_before_the_cascades()
    {
        $js = $this->file('resources/views/partials/js/custom_js.blade.php');

        $this->assertStringContainsString("typeof window.hkSelectLoading !== 'function'", $js);
        $this->assertStringContainsString('window.hkSelectLoading = function (select, on)', $js);

        $fallbackAt = strpos($js, "typeof window.hkSelectLoading !== 'function'");
        $firstCascadeAt = strpos($js, 'function getLGA');
        $marksCascadeAt = strpos($js, 'function getMarkClassSubjects');
        $this->assertNotFalse($firstCascadeAt);
        $this->assertNotFalse($marksCascadeAt);
        $this->assertLessThan($firstCascadeAt, $fallbackAt, 'the fallback must be defined before the first cascade that calls it');
        $this->assertLessThan($marksCascadeAt, $fallbackAt, 'the fallback must precede the marks cascade');

        // The cascades still route through the helper (the
        // loading state itself was not the defect — its address
        // was).
        $this->assertStringContainsString('hkSelectLoading(section, true)', $js);
        $this->assertStringContainsString('hkSelectLoading(subject, true)', $js);
    }

    public function test_canonical_copy_stays_in_hackstra_ux()
    {
        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('window.hkSelectLoading = function', $ux);
    }

    public function test_teacher_marks_page_renders_with_the_fallback()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $resp = $this->actingAs($teacher)->get(route('marks.index'));
        $resp->assertOk();
        $resp->assertSee("typeof window.hkSelectLoading !== 'function'", false);
        $resp->assertSee('function getMarkClassSubjects', false);
    }

    public function test_inline_skeleton_fallback_mirrors_the_canonical_renderer()
    {
        $js = $this->file('resources/views/partials/js/custom_js.blade.php');

        $this->assertStringContainsString("typeof window.hkSkel !== 'function'", $js);
        $this->assertStringContainsString('window.hkSkelHtml = function (type, rows)', $js);
        $this->assertStringContainsString('window.hkSkel = function (region, on, opts)', $js);
        // The swap semantics that matter: stash once, aria-busy,
        // restore only on request.
        $this->assertStringContainsString('region.__hkSkelPrev = region.innerHTML', $js);
        $this->assertStringContainsString("region.setAttribute('aria-busy', 'true')", $js);
        $this->assertStringContainsString('opts.restore && region.__hkSkelPrev !== undefined', $js);

        // The fallback sits in the page-level script — ahead of
        // nothing it depends on and after the cascade fallback it
        // was modelled on.
        $selAt = strpos($js, "typeof window.hkSelectLoading !== 'function'");
        $skelAt = strpos($js, "typeof window.hkSkel !== 'function'");
        $this->assertNotFalse($selAt);
        $this->assertNotFalse($skelAt);
        $this->assertGreaterThan($selAt, $skelAt);
    }

    public function test_canonical_skeleton_copy_stays_in_hackstra_ux()
    {
        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('window.hkSkelHtml = function', $ux);
        $this->assertStringContainsString('window.hkSkel = function', $ux);
    }

    public function test_attendance_report_renders_with_the_skeleton_fallback()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $resp = $this->actingAs($admin)->get(route('attendance.report'));
        $resp->assertOk();
        $resp->assertSee("typeof window.hkSkel !== 'function'", false);
        $resp->assertSee('window.hkSkel(', false);
    }
}
