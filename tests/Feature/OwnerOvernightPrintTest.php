<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Overnight batch stream S5-PRINT (2026-10-29):
 *  11. Tabulation print watermark repeats on every page + header repeats.
 *  12. Timetable index grid: readable columns, no vertical strips.
 *  13. Exam date-sheet class dropdown in natural class order (teacher view).
 *  14. Settings page: layout + signature/stamp text agrees with the image.
 *  15. Admission numbers: free-form custom pattern option.
 * Runs on a snapshot DB; every settings change rolls back.
 */
class OwnerOvernightPrintTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function setSetting(string $type, string $value): void
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    private function clearAdmSettings(): void
    {
        Setting::whereIn('type', ['adm_no_format', 'adm_no_serial_start', 'adm_no_serial_width', 'adm_no_custom_pattern'])->delete();
    }

    private function resetAdmSeq(): void
    {
        $ref = new \ReflectionProperty(Qs::class, 'admNoSeq');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }

    private function code(): string
    {
        return Qs::getAppCode();
    }

    /** First CSS rule block for $selector in $css (not a later media override). */
    private function cssBlock(string $css, string $selector): string
    {
        $pos = strpos($css, $selector);
        $this->assertNotFalse($pos, "CSS rule {$selector} not found");
        $open = strpos($css, '{', $pos);
        $close = strpos($css, '}', $open);

        return substr($css, $open, $close - $open);
    }

    /* ---------------- Item 11: tabulation watermark ---------------- */

    public function test_tabulation_watermark_is_transform_free()
    {
        $css = file_get_contents(public_path('assets/css/print_tabulation.css'));

        $logo = $this->cssBlock($css, '.tab-watermark ');
        $this->assertStringNotContainsString('transform', $logo);
        $this->assertStringContainsString('top: 0', $logo);
        $this->assertStringContainsString('bottom: 0', $logo);
        $this->assertStringContainsString('margin: auto', $logo);

        $mono = $this->cssBlock($css, '.tab-watermark-mono ');
        $this->assertStringNotContainsString('transform', $mono);
        $this->assertStringNotContainsString('rotate', $mono);
        $this->assertStringContainsString('margin: auto', $mono);
    }

    public function test_tabulation_header_repeats_per_page_by_construction()
    {
        $blade = file_get_contents(resource_path('views/pages/support_team/marks/tabulation/print.blade.php'));
        // One table per page, each carrying the full header in thead.
        $this->assertStringContainsString('tabPages', $blade);
        $this->assertStringContainsString('tab-hd-school', $blade);
        $this->assertStringContainsString('page-break-after: always', $blade);
        $this->assertStringContainsString('tab-school', $blade);
    }

    /* ---------------- Item 12: timetable index grid ---------------- */

    public function test_timetable_grid_is_capped_and_readable()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $grid = $this->cssBlock($css, '.ttc-grid{');
        $this->assertStringContainsString('max-width:1400px', $grid);
        // Cards must not stretch to the tallest card's height (the
        // tall empty vertical strips in the owner's screenshot).
        $this->assertStringContainsString('align-items:start', $grid);

        $page = $this->actingAs($this->admin())->get(route('tt.index'))->assertOk()->getContent();
        $this->assertStringContainsString('ttc-grid', $page);
        // Same actions/routes as before the redesign.
        $this->assertStringContainsString('View', $page);
        $this->assertStringContainsString('Print', $page);
        $this->assertStringContainsString('Validate', $page);
    }

    /* ---------------- Item 13: date-sheet dropdown order ---------------- */

    public function test_date_sheet_teacher_dropdown_is_natural_class_order()
    {
        $page = $this->actingAs($this->teacher())->get(route('exam_dates.show'))->assertOk()->getContent();
        $this->assertTrue((bool) preg_match('/<select[^>]*id="ds_class"[^>]*>(.*?)<\/select>/s', $page, $m), 'ds_class select not rendered');
        preg_match_all('/<option[^>]*>([^<]+)<\/option>/', $m[1], $opts);
        $names = array_map('trim', $opts[1]);
        // Seeded teacher scope: Prep, Class 3, 6, 7, 9, 12 — the exact
        // set the owner's screenshot showed as 12 / 3 / 6 / 7 / 9 / Prep.
        $this->assertSame(['Prep', 'Class 3', 'Class 6', 'Class 7', 'Class 9', 'Class 12'], $names);
    }

    /* ---------------- Item 14: settings signature/stamp agreement ---------------- */

    public function test_settings_signature_and_stamp_notes_match_saved_state()
    {
        Setting::whereIn('type', ['principal_signature', 'school_stamp'])->update(['description' => '']);
        $page = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('No signature uploaded', $page);
        $this->assertStringContainsString('No stamp uploaded', $page);
        $this->assertStringNotContainsString('alt="Principal signature"', $page);
        $this->assertStringNotContainsString('alt="School stamp"', $page);
        // Pending-selection state must not contradict the saved-state
        // note: picking a file hides the "No ..." note and shows a
        // "selected, save to apply" note instead (see the blade JS).
        $this->assertStringContainsString('principal_signature_empty_note', $page);
        $this->assertStringContainsString('school_stamp_empty_note', $page);
        $this->assertStringContainsString('principal_signature_pending_note', $page);
        $this->assertStringContainsString('school_stamp_pending_note', $page);
        $this->assertStringContainsString('syncUploadNote', $page);

        $this->setSetting('principal_signature', 'https://example.test/sig.png');
        $this->setSetting('school_stamp', 'https://example.test/stamp.png');
        $page = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('alt="Principal signature"', $page);
        $this->assertStringContainsString('alt="School stamp"', $page);
        $this->assertStringContainsString('Remove this signature', $page);
        $this->assertStringContainsString('Remove this stamp', $page);
        $this->assertStringNotContainsString('No signature uploaded', $page);
        $this->assertStringNotContainsString('No stamp uploaded', $page);
    }

    public function test_settings_report_card_previews_are_capped()
    {
        $page = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('tt-upload-previews', $page);
    }

    /* ---------------- Item 15: custom admission-number pattern ---------------- */

    public function test_custom_pattern_builds_prefix_and_sequences()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'custom');
        $this->setSetting('adm_no_custom_pattern', '{CODE}/{CLASS}/{YEAR}/');
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/ZZ/2095/', Qs::admNoPrefix('ZZ', '2095'));
        $this->assertSame($this->code() . '/ZZ/2095/1000', Qs::generateAdmNo('ZZ', '2095'));
        $this->assertSame($this->code() . '/ZZ/2095/1001', Qs::generateAdmNo('ZZ', '2095'));

        $this->setSetting('adm_no_custom_pattern', 'ADM-{YEAR}-');
        $this->resetAdmSeq();
        $this->assertSame('ADM-2095-1000', Qs::generateAdmNo('ZZ', '2095'));
    }

    public function test_custom_pattern_invalid_falls_back_to_default_never_500s()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'custom');
        $this->resetAdmSeq();

        $this->setSetting('adm_no_custom_pattern', 'HA');
        $this->assertSame($this->code() . '/ZZ/2094/', Qs::admNoPrefix('ZZ', '2094'));

        $this->setSetting('adm_no_custom_pattern', 'HA-{FOO}/');
        $this->assertSame($this->code() . '/ZZ/2094/', Qs::admNoPrefix('ZZ', '2094'));

        $this->setSetting('adm_no_custom_pattern', '');
        $this->assertSame($this->code() . '/ZZ/2094/', Qs::admNoPrefix('ZZ', '2094'));

        $this->assertFalse(Qs::isValidAdmNoCustomPattern('HA'));
        $this->assertFalse(Qs::isValidAdmNoCustomPattern('HA-{FOO}/'));
        $this->assertTrue(Qs::isValidAdmNoCustomPattern('{CODE}/{CLASS}/{YEAR}/'));
        $this->assertTrue(Qs::isValidAdmNoCustomPattern('ADM-{YEAR}-'));
    }

    public function test_custom_pattern_started_series_keeps_counting_and_old_numbers_untouched()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'custom');
        $this->setSetting('adm_no_custom_pattern', '{CODE}/{CLASS}/{YEAR}/');
        $this->resetAdmSeq();
        $first = Qs::generateAdmNo('ZZ', '2093');
        $this->assertSame($this->code() . '/ZZ/2093/1000', $first);

        // A stored row under the prefix pins the series: switching the
        // pattern text for a NEW prefix must not renumber the old one,
        // and re-selecting the old pattern resumes after the last mint.
        StudentRecord::query()->where('id', 1)->update(['adm_no' => $first]);
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/ZZ/2093/1001', Qs::generateAdmNo('ZZ', '2093'));
        $this->assertSame($first, StudentRecord::find(1)->adm_no);
    }

    public function test_settings_save_validates_custom_pattern()
    {
        $payload = [
            'system_name' => Setting::where('type', 'system_name')->first()->description,
            'current_session' => Setting::where('type', 'current_session')->first()->description,
            'address' => Setting::where('type', 'address')->first()->description,
            'adm_no_format' => 'custom',
            'adm_no_custom_pattern' => 'BAD',
        ];

        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), $payload)
            ->assertSessionHasErrors('adm_no_custom_pattern');

        $payload['adm_no_custom_pattern'] = 'HA-{YEAR}-';
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), $payload)
            ->assertRedirect(route('settings'));

        $this->assertSame('HA-{YEAR}-', Setting::where('type', 'adm_no_custom_pattern')->first()->description);
    }

    public function test_settings_save_shows_custom_option_in_form()
    {
        $page = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('value="custom"', $page);
        $this->assertStringContainsString('adm_no_custom_pattern', $page);
    }
}
