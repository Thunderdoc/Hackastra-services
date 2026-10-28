<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Certificate;
use App\Models\ClassType;
use App\Models\RagDocument;
use App\Models\Setting;
use App\Support\ExamDateSheet;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Agent G round 1 — school settings surface + branding propagation.
 * All runs on a snapshot DB; every settings change rolls back via
 * DatabaseTransactions. No write ever touches the shared DB.
 *
 * Hole-proof test (documents CURRENT behaviour, not desired behaviour):
 * - test_admin_writes_ai_keys_via_main_form (F-G1-1): the DH card is
 *   admin-disabled, but the main school form's AI section posts the
 *   same ai_* keys to settings.update, which writes them — the
 *   "platform-owned" claim is false in practice. Owner call, not fixed.
 * Fixed in this round (F-G1-3 whitelist, SettingController):
 * - test_admin_arbitrary_key_refused / test_admin_tt_gen_overwrite_refused /
 *   test_admin_ai_embedding_model_crafted_write_refused — v1 proved the
 *   hole (rows created/overwritten); post-fix they assert refusal.
 */
class OwnerSettingsWorkflowRound1Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function sa()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
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

    /** Required fields for a full school-form save (current values). */
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

    // ---------- Role shapes ----------

    public function test_admin_settings_page_ok_without_platform_card()
    {
        $html = $this->settingsHtml();
        $this->assertStringContainsString('Manage System Settings', $html);
        $this->assertStringContainsString('Knowledge Base', $html);
        $this->assertStringNotContainsString('Google Search Console Verification', $html);
        $this->assertStringContainsString('AI configuration is managed by the platform', $html);
    }

    public function test_sa_ai_update_endpoint_forbidden()
    {
        $this->actingAs($this->sa())->put(route('settings.ai_update'), [
            'ai_enabled' => 1, 'ai_provider' => 'gemini',
        ])->assertForbidden();
    }

    public function test_sa_knowledge_store_forbidden()
    {
        $this->actingAs($this->sa())->post(route('settings.knowledge.store'), [
            'title' => 'G1 SA doc', 'body' => 'should never store',
        ])->assertForbidden();
        $this->assertDatabaseMissing('rag_documents', ['title' => 'G1 SA doc']);
    }

    public function test_admin_ai_update_endpoint_denied_but_main_form_writes()
    {
        $before = $this->setting('ai_enabled');
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.ai_update'), [
                'ai_enabled' => 1, 'ai_provider' => 'gemini',
            ])->assertRedirect(route('settings'))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertSame($before, $this->setting('ai_enabled'));
    }

    // ---------- Main-form saves ----------

    public function test_admin_save_persists_and_lands_back_on_settings()
    {
        $this->putSettings(['system_name' => 'G1 Academy Save'])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_success', 'Record Updated Successfully');
        $this->assertSame('G1 Academy Save', $this->setting('system_name'));
    }

    public function test_admin_writes_ai_keys_via_main_form()
    {
        // F-G1-1 (hole-proof): DH card is admin-disabled, but the main
        // form's AI section posts the same keys to settings.update.
        $this->putSettings([
            'ai_enabled' => 1, 'ai_provider' => 'gemini',
            'ai_model' => 'g1-model', 'ai_api_key' => 'G1-PROBE-KEY-123',
        ])->assertRedirect(route('settings'));
        $this->assertSame('1', (string) $this->setting('ai_enabled'));
        $this->assertSame('g1-model', $this->setting('ai_model'));
        $this->assertSame('G1-PROBE-KEY-123', $this->setting('ai_api_key'));
    }

    public function test_admin_arbitrary_key_refused()
    {
        // F-G1-3 (fixed): unknown keys are refused whole, no row made.
        $this->assertNull($this->setting('g1_injected_key'));
        $this->putSettings(['g1_injected_key' => 'pwned'])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertNull($this->setting('g1_injected_key'));
    }

    public function test_admin_tt_gen_overwrite_refused()
    {
        // F-G1-3 (fixed): generator presets cannot be overwritten
        // through the settings endpoint.
        $before = $this->setting('tt_gen_primary');
        $this->putSettings(['tt_gen_primary' => 'not-json'])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertSame($before, $this->setting('tt_gen_primary'));
    }

    public function test_admin_ai_embedding_model_crafted_write_refused()
    {
        // F-G1-3 (fixed): ai_embedding_model is DH-only; a crafted
        // main-form save carrying it is refused whole.
        $before = $this->setting('ai_embedding_model');
        $this->putSettings(['ai_embedding_model' => 'g1-embed'])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertSame($before, $this->setting('ai_embedding_model'));
    }

    public function test_admin_platform_key_refused()
    {
        $before = $this->setting('google_site_verification');
        $this->putSettings(['google_site_verification' => 'ABC123xyz'])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertSame($before, $this->setting('google_site_verification'));
    }

    // ---------- Secrets: write-only ----------

    public function test_secrets_never_rendered_back_but_key_id_is()
    {
        $this->setSetting('razorpay_key_id', 'rzp_test_G1KEYID');
        $this->setSetting('razorpay_key_secret', 'G1-RAZOR-SECRET-XYZ');
        $this->setSetting('razorpay_webhook_secret', 'G1-WEBHOOK-SECRET-XYZ');
        $this->setSetting('ai_api_key', 'G1-GEMINI-SECRET-XYZ');
        $html = $this->settingsHtml();
        $this->assertStringContainsString('rzp_test_G1KEYID', $html);
        $this->assertStringNotContainsString('G1-RAZOR-SECRET-XYZ', $html);
        $this->assertStringNotContainsString('G1-WEBHOOK-SECRET-XYZ', $html);
        $this->assertStringNotContainsString('G1-GEMINI-SECRET-XYZ', $html);
    }

    public function test_blank_secret_post_keeps_saved_values()
    {
        $this->setSetting('razorpay_key_id', 'rzp_test_G1KEEP');
        $this->setSetting('razorpay_key_secret', 'G1-KEEP-SECRET');
        $this->setSetting('razorpay_webhook_secret', 'G1-KEEP-WEBHOOK');
        $this->setSetting('ai_api_key', 'G1-KEEP-AI');
        $this->putSettings([
            'razorpay_key_id' => '', 'razorpay_key_secret' => '',
            'razorpay_webhook_secret' => '', 'ai_api_key' => '',
        ])->assertRedirect(route('settings'));
        $this->assertSame('rzp_test_G1KEEP', $this->setting('razorpay_key_id'));
        $this->assertSame('G1-KEEP-SECRET', $this->setting('razorpay_key_secret'));
        $this->assertSame('G1-KEEP-WEBHOOK', $this->setting('razorpay_webhook_secret'));
        $this->assertSame('G1-KEEP-AI', $this->setting('ai_api_key'));
    }

    // ---------- Validation ----------

    public function test_validation_system_name_too_short()
    {
        $before = $this->setting('system_name');
        $this->putSettings(['system_name' => 'ab'])
            ->assertSessionHasErrors('system_name');
        $this->assertSame($before, $this->setting('system_name'));
    }

    public function test_validation_bad_email()
    {
        $this->putSettings(['system_email' => 'not-an-email'])
            ->assertSessionHasErrors('system_email');
    }

    public function test_validation_negative_fine_and_day_cap()
    {
        $this->putSettings(['library_fine_per_day' => -5])
            ->assertSessionHasErrors('library_fine_per_day');
        $this->putSettings(['teacher_max_periods_day' => 99])
            ->assertSessionHasErrors('teacher_max_periods_day');
    }

    public function test_next_term_fees_text_saved_verbatim_unvalidated()
    {
        // Observation, not a rule failure: no validation rule exists
        // for next_term_fees_* — any string persists and the report
        // card prints it verbatim after the rupee sign.
        $code = strtolower(ClassType::orderBy('name')->first()->code);
        $key = 'next_term_fees_' . $code;
        $this->putSettings([$key => 'abc'])->assertRedirect(route('settings'));
        $this->assertSame('abc', $this->setting($key));
    }

    public function test_term_ends_saves()
    {
        // Save side only: term_ends has NO reader anywhere (grep) —
        // the date persists but no page renders it. Dead setting.
        $this->putSettings(['term_ends' => '12-31-2026'])->assertRedirect(route('settings'));
        $this->assertSame('12-31-2026', $this->setting('term_ends'));
    }

    // ---------- Blade hygiene (correct-behaviour assertions: FAIL pre-fix) ----------

    public function test_term_begins_placeholder_not_copied_from_term_ends()
    {
        $html = $this->settingsHtml();
        $this->assertSame(1, substr_count($html, 'placeholder="Date Term Ends"'));
    }

    public function test_ai_field_ids_are_unique()
    {
        $html = $this->settingsHtml();
        foreach (['ai_enabled', 'ai_provider', 'ai_model', 'ai_api_key'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), "id=$id must appear once");
        }
    }

    public function test_term_begins_garbage_renders_1970_on_report_card()
    {
        // Code-read + proven: term_begins has no validation rule, and
        // the report-card comments block formats it with strtotime()
        // (comments.blade.php:21, included by report_card.blade.php).
        // strtotime('abc') is false, so date() prints 1 Jan 1970.
        // Validating the format (or keeping free text) is an owner call.
        $this->setSetting('term_begins', 'abc');
        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())->get("/marks/print/{$hash}/1/2026-2027");
        $res->assertOk();
        $res->assertSee('1st January, 1970', false);
    }

    // ---------- Logo upload path ----------

    public function test_logo_upload_stores_setting()
    {
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), array_merge($this->basePayload(), [
                'logo' => UploadedFile::fake()->image('g1logo.png', 100, 100),
            ]))->assertRedirect(route('settings'));
        $logo = $this->setting('logo');
        $this->assertStringContainsString('logo.', (string) $logo);
        // Hygiene: remove the stored probe file; the DB row rolls back.
        $path = parse_url((string) $logo, PHP_URL_PATH);
        if ($path && str_starts_with($path, '/storage/')) {
            Storage::disk('public')->delete(substr($path, strlen('/storage/')));
        }
    }

    // ---------- Branding propagation ----------

    private function setG1Branding(): void
    {
        $this->setSetting('system_name', 'G1 Test Academy');
        $this->setSetting('principal_name', 'G1 Principal');
        $this->setSetting('logo', '/storage/uploads/g1logo.png');
        $this->setSetting('school_stamp', '/storage/uploads/g1stamp.png');
        $this->setSetting('principal_signature', '/storage/uploads/g1sig.png');
    }

    public function test_marksheet_print_shows_new_branding()
    {
        $this->setG1Branding();
        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())->get("/marks/print/{$hash}/1/2026-2027");
        $res->assertOk();
        $res->assertSee('G1 Test Academy', false);
        $res->assertSee('/storage/uploads/g1logo.png', false);
        $res->assertSee('G1 Principal', false);
    }

    public function test_annual_print_shows_new_branding()
    {
        $this->setG1Branding();
        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())->get("/marks/print_annual/{$hash}/2026-2027");
        $res->assertOk();
        $res->assertSee('G1 Test Academy', false);
    }

    public function test_tabulation_print_shows_new_branding()
    {
        $this->setG1Branding();
        $res = $this->actingAs($this->admin())->get('/marks/tabulation/print/1/11/48');
        $res->assertOk();
        $res->assertSee('G1 Test Academy', false);
    }

    public function test_student_list_print_shows_new_logo()
    {
        $this->setG1Branding();
        $res = $this->actingAs($this->admin())->get('/students/list/1?print=1');
        $res->assertOk();
        $res->assertSee('/storage/uploads/g1logo.png', false);
        $res->assertSee('G1 Test Academy', false);
    }

    public function test_invoice_shows_new_branding()
    {
        $this->setG1Branding();
        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())->get("/payments/invoice/{$hash}/2026-2027");
        $res->assertOk();
        $res->assertSee('G1 Test Academy', false);
    }

    public function test_admit_card_shows_new_branding()
    {
        $this->setG1Branding();
        $res = $this->actingAs($this->admin())->get('/my-admit-cards/1/57');
        $res->assertOk();
        $res->assertSee('G1 Test Academy', false);
        $res->assertSee('/storage/uploads/g1sig.png', false);
    }

    public function test_certificate_print_shows_new_branding()
    {
        $this->setG1Branding();
        $cert = Certificate::create([
            'user_id' => 57, 'student_record_id' => 1, 'type' => 'bonafide',
            'serial' => 'G1/2026/0001', 'student_name' => 'Aarav Sharma',
            'adm_no' => 'HA/PN/2026/91300', 'class_name' => 'Prep',
            'section_name' => 'A', 'session' => '2026-2027',
            'reason' => 'G1 probe', 'issued_by' => 2,
            'issued_at' => now(),
        ]);
        $res = $this->actingAs($this->admin())->get("/certificates/{$cert->id}/print");
        $res->assertOk();
        $res->assertSee('G1 Test Academy', false);
        $res->assertSee('G1 Principal', false);
    }

    public function test_school_info_arrays_match_new_branding()
    {
        $this->setG1Branding();
        $info = ExamDateSheet::schoolInfo();
        $this->assertSame('G1 Test Academy', $info['name']);
        $this->assertSame('/storage/uploads/g1logo.png', $info['logo']);
        $this->assertSame('/storage/uploads/g1stamp.png', $info['stamp']);
        $this->assertSame('/storage/uploads/g1sig.png', $info['principal_signature']);
        $this->assertSame('G1 Principal', $info['principal_name']);
    }

    // ---------- Knowledge Base ----------

    public function test_admin_kb_destroy_policy_doc()
    {
        $doc = RagDocument::create([
            'title' => 'G1 KB probe', 'source_type' => 'policy_text',
            'source_id' => null, 'body' => 'G1 probe text',
            'is_public' => false, 'created_by' => $this->admin()->id,
        ]);
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->delete(route('settings.knowledge.destroy', $doc->id))
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_success', 'Knowledge document deleted and removed from the index.');
        $this->assertDatabaseMissing('rag_documents', ['id' => $doc->id]);
    }
}
