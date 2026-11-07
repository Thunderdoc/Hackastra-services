<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 5 Slice 2 (P9) — Smart Form Validation rollout.
 *
 * Server-side validation stays the only authority; everything
 * pinned here is the hint layer on top of it:
 *  (a) no blade outside the excluded set still carries the
 *      duplicated local top-alert block (the master layout's
 *      summary already renders every error once);
 *  (b) the master layout includes the hk_validate partial and
 *      its script carries the blur/input hint handlers;
 *  (c) displayAjaxErr maps keyed 422 errors onto their fields;
 *  (d) hk_date shows a visible message on an invalid date;
 *  (e) a swept form (books/create) renders per-field slots.
 *
 * Detector for (a), stated honestly: a blade is flagged when the
 * SAME file contains both an $errors->any() guard and an
 * $errors->all() loop — the shape of every duplicated top-alert
 * found by the audit. Excluded, by design:
 *  - layouts/master.blade.php — the canonical summary itself;
 *  - auth/login + auth/passwords/reset — they extend the login
 *    layout, which has NO master summary, so their block is the
 *    only error rendering there, not a duplicate;
 *  - the settings / circulars / assignments / lesson_plans /
 *    marks blades were the concurrent autosave slice's carve-out;
 *    they were verified clean of the pattern, and quizzes/create
 *    (the one carve-out file that carried it) was swept in the
 *    coordinator pass, so only the directory guards remain;
 *  - students/add uses $errors->any() as tab logic only (no
 *    $errors->all() loop), so the detector never flags it.
 */
class OwnerRound5ValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function librarian(): User
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function bladeFiles(): array
    {
        $base = resource_path('views');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -10) === '.blade.php') {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }

    protected function isExcludedFromSweep(string $path): bool
    {
        $rel = str_replace('\\', '/', substr($path, strlen(resource_path('views')) + 1));
        if ($rel === 'layouts/master.blade.php') { return true; }
        if ($rel === 'auth/login.blade.php' || $rel === 'auth/passwords/reset.blade.php') { return true; }
        if (strpos($rel, 'settings') !== false) { return true; }
        if (strpos($rel, 'circulars/_form') !== false) { return true; }
        if (strpos($rel, 'assignments/_form') !== false) { return true; }
        if (strpos($rel, 'lesson_plans/') !== false) { return true; }
        if (strpos($rel, 'marks/edit') !== false) { return true; }
        return false;
    }

    public function test_a_no_blade_outside_the_excluded_set_duplicates_the_top_alert()
    {
        $flagged = [];
        foreach ($this->bladeFiles() as $path) {
            if ($this->isExcludedFromSweep($path)) { continue; }
            $src = file_get_contents($path);
            if (strpos($src, '$errors->any()') !== false && strpos($src, '$errors->all()') !== false) {
                $flagged[] = $path;
            }
        }
        $this->assertSame([], $flagged, 'Duplicated top-alert blocks remain in: ' . implode(', ', $flagged));
    }

    public function test_b_master_layout_includes_the_hk_validate_hint_layer()
    {
        $master = file_get_contents(resource_path('views/layouts/master.blade.php'));
        $this->assertStringContainsString("@include('partials.hk_validate')", $master);

        $partial = resource_path('views/partials/hk_validate.blade.php');
        $this->assertFileExists($partial);
        $src = file_get_contents($partial);
        $this->assertStringContainsString('window.HkValidate', $src);
        $this->assertStringContainsString("addEventListener('blur'", $src);
        $this->assertStringContainsString("addEventListener('input'", $src);
        $this->assertStringContainsString('field-error', $src);
        $this->assertStringContainsString('is-invalid', $src);
        $this->assertStringContainsString('aria-invalid', $src);
        /* The hint layer must never become an authority. */
        $this->assertStringNotContainsString('preventDefault', $src);
    }

    public function test_c_display_ajax_err_maps_keyed_422_errors_onto_fields()
    {
        $src = file_get_contents(resource_path('views/partials/js/custom_js.blade.php'));
        $start = strpos($src, 'function displayAjaxErr');
        $this->assertNotFalse($start);
        /* The mapper delegates slot creation to its sibling helper
           hkAjaxFieldSlot (defined immediately above it), so the
           pinned unit is the pair: slice from the helper's start. */
        $fnPos = strpos($src, 'function hkAjaxFieldSlot');
        $this->assertNotFalse($fnPos, 'hkAjaxFieldSlot is missing');
        $this->assertNotFalse(strpos($src, 'function hkMapAjaxErrorsToFields'), 'hkMapAjaxErrorsToFields is missing');
        $fn = substr($src, $fnPos, strpos($src, 'function displayAjaxErr', $fnPos) - $fnPos);
        $this->assertStringContainsString('field-error', $fn);
        $this->assertStringContainsString('is-invalid', $fn);
        $this->assertStringContainsString('scrollIntoView', $fn);
        $this->assertStringContainsString('[name="', $fn);
        /* displayAjaxErr itself must call the mapper. */
        $disp = substr($src, $start, 700);
        $this->assertStringContainsString('hkMapAjaxErrorsToFields(errors)', $disp);
    }

    public function test_d_hk_date_shows_a_visible_message_for_an_invalid_date()
    {
        $src = file_get_contents(resource_path('views/partials/hk_date.blade.php'));
        $this->assertStringContainsString('Enter a real date as DD/MM/YYYY', $src);
        $this->assertStringContainsString('hkDateShowError', $src);
        $this->assertStringContainsString("role', 'alert'", $src);
        $this->assertStringContainsString("'field-error hkdp-error'", $src);
    }

    public function test_e_swept_books_create_renders_per_field_slots()
    {
        $response = $this->actingAs($this->librarian())->get(route('books.create'));
        $response->assertOk();
        $response->assertSee('field-error', false);
        $response->assertSee('HkValidate', false);
    }

    public function test_e2_a_failed_books_submit_renders_the_error_at_the_field()
    {
        $this->actingAs($this->librarian())
            ->from(route('books.create'))
            ->post(route('books.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $response = $this->actingAs($this->librarian())->get(route('books.create'));
        $response->assertOk();
        $response->assertSee('field-error', false);
        $response->assertSee('The name field is required', false);
    }
}
