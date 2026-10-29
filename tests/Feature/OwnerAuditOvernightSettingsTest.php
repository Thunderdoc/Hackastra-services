<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AUDIT stream AUD-SETTINGS, Audit Round 1 (overnight batch, 2026-10-29).
 * Verification ONLY — this file asserts the batch spec, it fixes nothing.
 * Runs on a snapshot DB; every change rolls back via DatabaseTransactions.
 *
 * Item 14: settings page — signature/stamp text must agree with the image
 *   (set => image + Remove, no "No ... uploaded" text for that field;
 *   unset => correct text, no image, no Remove; fields independent).
 * Item 15: admission numbers — school-defined free-form custom pattern
 *   (adm_no_format=custom + adm_no_custom_pattern, placeholders
 *   {CODE}/{CLASS}/{YEAR}), serial ALWAYS at the end, old numbers
 *   byte-identical after a pattern change, a started series keeps
 *   counting from its last number, invalid pattern falls back safely
 *   without a 500.
 * Item 23: global Back control — one click => Dashboard on all pages;
 *   page-specific "Back to X" links unchanged.
 * Standing guard: settings.update writes ONLY whitelisted keys — a
 *   crafted unknown key is refused whole (no row, co-posted field kept).
 */
class OwnerAuditOvernightSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function setting(string $type): ?string
    {
        $row = Setting::where('type', $type)->first();

        return $row ? $row->description : null;
    }

    private function setSetting(string $type, string $value): void
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    private function clearAdmSettings(): void
    {
        Setting::whereIn('type', ['adm_no_format', 'adm_no_custom_pattern', 'adm_no_serial_start', 'adm_no_serial_width'])->delete();
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

    private function basePayload(): array
    {
        return [
            'system_name' => $this->setting('system_name') ?? 'Hackstra Academy',
            'current_session' => $this->setting('current_session') ?? '2026-2027',
            'address' => $this->setting('address') ?? 'Madurai, Tamil Nadu, India',
        ];
    }

    private function putSettings(array $overrides = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), array_merge($this->basePayload(), $overrides));
    }

    private function settingsHtml(): string
    {
        return $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
    }

    /** The rendered hk-global-back element, or null when the page has none.
     *  NOTE: overnight item 23 made it a plain anchor to the dashboard
     *  (it used to be a history-back button), so match either tag. */
    private function globalBackTag(string $html): ?string
    {
        if (preg_match('/<(?:button|a)[^>]*hk-global-back.*?<\/(?:button|a)>/s', $html, $m)) {
            return $m[0];
        }

        return null;
    }

    /* ---------------- Item 14: signature / stamp text vs image ---------------- */

    public function test_item14_signature_set_stamp_unset_fields_agree_independently()
    {
        $this->setSetting('principal_signature', 'https://example.test/sig.png');
        Setting::where('type', 'school_stamp')->update(['description' => '']);
        $page = $this->settingsHtml();

        // Signature field: image shown, "no upload" text gone, Remove offered.
        $this->assertStringContainsString('alt="Principal signature"', $page);
        $this->assertStringNotContainsString('No signature uploaded', $page);
        $this->assertStringContainsString('Remove this signature', $page);
        // Stamp field, independently: no image, correct text, no Remove.
        $this->assertStringNotContainsString('alt="School stamp"', $page);
        $this->assertStringContainsString('No stamp uploaded', $page);
        $this->assertStringNotContainsString('Remove this stamp', $page);
    }

    public function test_item14_both_unset_no_images_correct_texts()
    {
        Setting::whereIn('type', ['principal_signature', 'school_stamp'])->update(['description' => '']);
        $page = $this->settingsHtml();

        $this->assertStringContainsString('No signature uploaded', $page);
        $this->assertStringContainsString('No stamp uploaded', $page);
        $this->assertStringNotContainsString('alt="Principal signature"', $page);
        $this->assertStringNotContainsString('alt="School stamp"', $page);
        $this->assertStringNotContainsString('Remove this signature', $page);
        $this->assertStringNotContainsString('Remove this stamp', $page);
    }

    public function test_item14_both_set_no_no_upload_text()
    {
        $this->setSetting('principal_signature', 'https://example.test/sig.png');
        $this->setSetting('school_stamp', 'https://example.test/stamp.png');
        $page = $this->settingsHtml();

        $this->assertStringContainsString('alt="Principal signature"', $page);
        $this->assertStringContainsString('alt="School stamp"', $page);
        $this->assertStringContainsString('Remove this signature', $page);
        $this->assertStringContainsString('Remove this stamp', $page);
        $this->assertStringNotContainsString('No signature uploaded', $page);
        $this->assertStringNotContainsString('No stamp uploaded', $page);
    }

    public function test_item14_pending_note_wiring_present_static_only()
    {
        // STATIC check only: the file-picked (not yet saved) preview vs
        // "No ... uploaded" contradiction is fixed in JS (syncUploadNote),
        // which a feature test cannot execute — no browser/CDP in audit.
        // Assert the wiring the JS needs is present; behaviour itself is
        // reported as not-verifiable-by-this-audit, not as passing.
        Setting::whereIn('type', ['principal_signature', 'school_stamp'])->update(['description' => '']);
        $page = $this->settingsHtml();

        $this->assertStringContainsString('syncUploadNote', $page);
        $this->assertStringContainsString('principal_signature_empty_note', $page);
        $this->assertStringContainsString('principal_signature_pending_note', $page);
        $this->assertStringContainsString('school_stamp_empty_note', $page);
        $this->assertStringContainsString('school_stamp_pending_note', $page);
        // Pending notes exist but are hidden until a file is picked.
        $this->assertStringContainsString('principal_signature_pending_note" style="display:none"', $page);
        $this->assertStringContainsString('school_stamp_pending_note" style="display:none"', $page);
    }

    /* ---------------- Item 15: custom admission-number pattern ---------------- */

    public function test_item15_custom_pattern_mints_via_settings_save_serial_at_end()
    {
        $this->clearAdmSettings();
        $this->putSettings([
            'adm_no_format' => 'custom',
            'adm_no_custom_pattern' => '{CODE}/{CLASS}/{YEAR}/',
        ])->assertRedirect(route('settings'));
        $this->assertSame('custom', $this->setting('adm_no_format'));
        $this->assertSame('{CODE}/{CLASS}/{YEAR}/', $this->setting('adm_no_custom_pattern'));

        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/ZZ/2095/1000', Qs::generateAdmNo('ZZ', '2095'));
        $this->assertSame($this->code() . '/ZZ/2095/1001', Qs::generateAdmNo('ZZ', '2095'));

        // Dash-separated pattern: serial still appended last, never glued.
        $this->setSetting('adm_no_custom_pattern', 'ADM-{YEAR}-');
        $this->resetAdmSeq();
        $minted = Qs::generateAdmNo('ZZ', '2095');
        $this->assertSame('ADM-2095-1000', $minted);
        $this->assertMatchesRegularExpression('/\d+$/', $minted);
        $this->assertSame('ADM-2095-', substr($minted, 0, -4));
    }

    public function test_item15_typed_prefix_follows_custom_pattern()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'custom');
        $this->setSetting('adm_no_custom_pattern', '{CODE}/{CLASS}/{YEAR}/');
        $this->assertSame($this->code() . '/ZZ/2095/', Qs::admNoPrefix('ZZ', '2095'));
    }

    public function test_item15_old_numbers_byte_identical_and_started_series_continues()
    {
        $this->clearAdmSettings();
        $old = $this->code() . '/ZZ/2092/1000';
        StudentRecord::query()->where('id', 1)->update(['adm_no' => $old]);

        // Switch pattern through the real settings save.
        $this->putSettings([
            'adm_no_format' => 'custom',
            'adm_no_custom_pattern' => 'ADM-{YEAR}-',
        ])->assertRedirect(route('settings'));

        // Old number byte-identical after the change.
        $this->assertSame($old, StudentRecord::find(1)->adm_no);

        // Fresh series under the NEW prefix starts at the configured start.
        $this->resetAdmSeq();
        $first = Qs::generateAdmNo('ZZ', '2091');
        $this->assertSame('ADM-2091-1000', $first);

        // Pin the minted number as a stored row, raise the start, and
        // re-mint: a started series keeps counting, it never jumps.
        StudentRecord::query()->where('id', 2)->update(['adm_no' => $first]);
        $this->setSetting('adm_no_serial_start', '5000');
        $this->resetAdmSeq();
        $this->assertSame('ADM-2091-1001', Qs::generateAdmNo('ZZ', '2091'));
        $this->assertSame($old, StudentRecord::find(1)->adm_no);
    }

    public function test_item15_fresh_custom_series_honours_start_and_width()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'custom');
        $this->setSetting('adm_no_custom_pattern', 'ADM-{YEAR}-');
        $this->setSetting('adm_no_serial_start', '7');
        $this->setSetting('adm_no_serial_width', '4');
        $this->resetAdmSeq();
        $this->assertSame('ADM-2095-0007', Qs::generateAdmNo('ZZ', '2095'));
    }

    public function test_item15_invalid_pattern_rejected_or_fallback_never_500()
    {
        // (a) HTTP save of an invalid pattern: validation error, no 500,
        // stored value unchanged.
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'custom');
        $this->setSetting('adm_no_custom_pattern', 'HA-{YEAR}-');
        $this->putSettings([
            'adm_no_format' => 'custom',
            'adm_no_custom_pattern' => 'BAD',
        ])->assertSessionHasErrors('adm_no_custom_pattern');
        $this->assertSame('HA-{YEAR}-', $this->setting('adm_no_custom_pattern'));

        // (b) Custom format with an empty pattern, from a clean slate:
        // whether the save validates it away or stores the empty value,
        // minting must land on the default shape, never throw / 500.
        $this->clearAdmSettings();
        $response = $this->putSettings([
            'adm_no_format' => 'custom',
            'adm_no_custom_pattern' => '',
        ]);
        $response->assertRedirect(route('settings'));
        $this->assertNotEquals(500, $response->getStatusCode());
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/ZZ/2094/1000', Qs::generateAdmNo('ZZ', '2094'));

        // (c) Crafted invalid patterns already in the DB fall back to the
        // default prefix — generateAdmNo must not throw.
        $this->setSetting('adm_no_format', 'custom');
        foreach (['HA', 'HA-{FOO}/', '', '{CODE}/{CLASS}/{YEAR}'] as $bad) {
            $this->setSetting('adm_no_custom_pattern', $bad);
            $this->resetAdmSeq();
            $this->assertSame($this->code() . '/ZZ/2094/', Qs::admNoPrefix('ZZ', '2094'), "pattern: {$bad}");
            $this->assertSame($this->code() . '/ZZ/2094/1000', Qs::generateAdmNo('ZZ', '2094'), "pattern: {$bad}");
        }
        $this->assertFalse(Qs::isValidAdmNoCustomPattern('HA'));
        $this->assertFalse(Qs::isValidAdmNoCustomPattern('HA-{FOO}/'));
        $this->assertFalse(Qs::isValidAdmNoCustomPattern('{CODE}/{CLASS}/{YEAR}'));

        // (d) The settings page itself still renders 200 with a crafted
        // invalid pattern stored.
        $this->setSetting('adm_no_custom_pattern', 'HA-{FOO}/');
        $this->actingAs($this->admin())->get(route('settings'))->assertOk();
    }

    /* ---------------- Standing guard: whitelist-only settings writes ---------------- */

    public function test_guard_unknown_key_refused_whole()
    {
        $beforeName = $this->setting('system_name');
        $this->assertNull($this->setting('audit_injected_key'));
        $this->putSettings([
            'system_name' => 'Should Not Save',
            'audit_injected_key' => 'pwned',
        ])->assertRedirect(route('settings'))
          ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertNull($this->setting('audit_injected_key'));
        $this->assertSame($beforeName, $this->setting('system_name'));
    }

    public function test_guard_tt_gen_preset_overwrite_refused()
    {
        $before = $this->setting('tt_gen_primary');
        $this->putSettings(['tt_gen_primary' => 'not-json'])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertSame($before, $this->setting('tt_gen_primary'));
    }

    /* ---------------- Item 23: global Back => Dashboard ---------------- */

    public function test_item23_global_back_points_at_dashboard_on_settings()
    {
        $page = $this->settingsHtml();
        $tag = $this->globalBackTag($page);
        $this->assertNotNull($tag, 'No hk-global-back control on the settings page');
        $this->assertStringContainsString(route('dashboard'), $tag, 'Global back must navigate to the dashboard URL');
        $this->assertStringNotContainsString('history.back', $tag, 'One click must go to Dashboard, not browser history');
    }

    public function test_item23_global_back_on_second_page_and_specific_link_unchanged()
    {
        $page = $this->actingAs($this->admin())->get(route('circulars.create'))->assertOk()->getContent();
        $tag = $this->globalBackTag($page);
        $this->assertNotNull($tag, 'No hk-global-back control on circulars.create');
        $this->assertStringContainsString(route('dashboard'), $tag);
        $this->assertStringNotContainsString('history.back', $tag);
        // Page-specific link untouched: still points at its own index.
        $this->assertStringContainsString('Back to Circulars', $page);
        $this->assertStringContainsString(route('circulars.index'), $page);
    }

    public function test_item23_dashboard_page_global_back_never_history()
    {
        $page = $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->getContent();
        $tag = $this->globalBackTag($page);
        if ($tag === null) {
            // No global back on the dashboard itself is acceptable (it IS
            // the dashboard); what must never happen is a history-back
            // control. Recorded here as a passing lenient branch.
            $this->assertNull($tag);
        } else {
            $this->assertStringContainsString(route('dashboard'), $tag);
            $this->assertStringNotContainsString('history.back', $tag);
        }
    }
}
