<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Certificate;
use App\Models\Circular;
use App\Models\Setting;
use App\Support\ExamDateSheet;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent G round 5 — School Profile propagation chain (P15).
 * All runs on a snapshot DB; every settings change rolls back via
 * DatabaseTransactions. No write ever touches the shared DB.
 *
 * Profile fields under probe (all have a real field on the settings
 * form, School Profile section): system_title ("School Acronym"),
 * phone, system_email, address. system_name + logo were proven in
 * round 1 and are NOT re-proven here except where a surface needs
 * them alongside the contact fields.
 *
 * Reader map (grep, app + views + routes):
 * - system_title -> Qs::getAppCode(): master-layout title suffix,
 *   login wordmark, app.blade brand, every print watermark, and the
 *   ID prefixes for NEW admission numbers / staff ids. Circular
 *   references are DERIVED from it (Circular::reference()), so a
 *   rename retroactively changes every displayed circular ref, while
 *   stored adm_no values keep their old prefix.
 * - address -> admit card, certificate prints, invoice, report card +
 *   annual, date-sheet schoolInfo + print header, monthly report
 *   letterhead, collection PDF partial, circular document, payslip
 *   (school_address), Readiness school line.
 * - phone -> admit card, certificate prints, invoice, date-sheet
 *   schoolInfo + print header, monthly letterhead, payslip contact,
 *   privacy policy + terms pages (HomeController contact_phone).
 * - system_email -> admit card, invoice, date-sheet schoolInfo +
 *   print header, monthly letterhead, payslip contact, Ask-page help
 *   tile (mailto), PlatformMessage from (fallback info@hackstra.app).
 * - alt_email -> ZERO readers outside the settings form/controller
 *   (round-1 verdict, re-confirmed by grep this round): saves, but
 *   renders nowhere. Harmless legacy, not a test assertion (a test
 *   cannot grep the codebase); reported as a code-read finding.
 * Boundary locked here: the student-list print carries name + logo
 * only — the contact fields do NOT leak onto every print surface.
 */
class OwnerSettingsWorkflowRound5Test extends TestCase
{
    use DatabaseTransactions;

    private const ADDR = 'G5 Test Street 123';
    private const PHONE = '9998887777';
    private const EMAIL = 'g5office@example.school';
    private const CODE = 'G5CODE';

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function setting(string $type): ?string
    {
        $row = Setting::where('type', $type)->first();

        return $row ? $row->description : null;
    }

    /** Change the whole profile through the REAL settings save. */
    private function saveG5Profile(): void
    {
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), [
                'system_name' => $this->setting('system_name') ?? 'Hackstra Academy',
                'current_session' => $this->setting('current_session') ?? '2026-2027',
                'system_title' => self::CODE,
                'phone' => self::PHONE,
                'system_email' => self::EMAIL,
                'address' => self::ADDR,
            ])->assertRedirect(route('settings'));
    }

    // ---------- Real save ----------

    public function test_real_settings_save_changes_profile_fields()
    {
        $this->saveG5Profile();
        $this->assertSame(self::ADDR, $this->setting('address'));
        $this->assertSame(self::PHONE, $this->setting('phone'));
        $this->assertSame(self::EMAIL, $this->setting('system_email'));
        $this->assertSame(self::CODE, $this->setting('system_title'));
        // Untouched by this save: the school name itself stays put.
        $this->assertSame('Hackstra Academy', $this->setting('system_name'));
    }

    // ---------- system_title (acronym) ----------

    public function test_app_code_and_master_title_show_new_acronym()
    {
        $this->saveG5Profile();
        $this->assertSame(self::CODE, Qs::getAppCode());
        $html = $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()->getContent();
        $this->assertStringContainsString(self::CODE, $html);
    }

    public function test_login_page_wordmark_shows_new_acronym()
    {
        $this->saveG5Profile();
        // Test-craft (two legs, both real behaviour): saveG5Profile()
        // leaves the admin authenticated, and /login redirects
        // authenticated users — log out first. And the wordmark is
        // the NO-LOGO branch of the login brandmark (login.blade.php:
        // logo img when a logo is set, wordmark otherwise) — the
        // seeded DB carries a logo value, so blank it to reach the
        // wordmark branch. With a logo set, the acronym still
        // propagates via the master-layout title suffix (test above).
        Setting::updateOrCreate(['type' => 'logo'], ['description' => '']);
        auth()->logout();
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString(self::CODE, $html);
    }

    public function test_circular_reference_derives_from_new_acronym()
    {
        // References are computed, not stored: a rename retroactively
        // changes every displayed circular ref (contrast stored adm_no
        // values, which keep the prefix they were minted with).
        $this->saveG5Profile();
        $ref = Circular::findOrFail(1)->reference();
        $this->assertStringStartsWith(self::CODE . '/CIR/2026-2027/', $ref);
    }

    // ---------- address / phone / email on rendered documents ----------

    public function test_invoice_shows_new_address_phone_email()
    {
        $this->saveG5Profile();
        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())
            ->get("/payments/invoice/{$hash}/2026-2027");
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
        $res->assertSee('Tel: ' . self::PHONE, false);
        $res->assertSee(self::EMAIL, false);
    }

    public function test_marksheet_print_shows_new_address()
    {
        $this->saveG5Profile();
        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())
            ->get("/marks/print/{$hash}/1/2026-2027");
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
    }

    public function test_annual_print_shows_new_address()
    {
        $this->saveG5Profile();
        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())
            ->get("/marks/print_annual/{$hash}/2026-2027");
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
    }

    public function test_date_sheet_school_info_carries_new_profile()
    {
        $this->saveG5Profile();
        $info = ExamDateSheet::schoolInfo();
        $this->assertSame(self::ADDR, $info['address']);
        $this->assertSame(self::PHONE, $info['phone']);
        $this->assertSame(self::EMAIL, $info['email']);
    }

    public function test_admit_card_shows_new_address_phone_email()
    {
        $this->saveG5Profile();
        $res = $this->actingAs($this->admin())->get('/my-admit-cards/1/57');
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
        $res->assertSee('Tel: ' . self::PHONE, false);
        $res->assertSee(self::EMAIL, false);
    }

    public function test_certificate_print_shows_address_phone_but_not_email()
    {
        $this->saveG5Profile();
        $cert = Certificate::create([
            'user_id' => 57, 'student_record_id' => 1, 'type' => 'bonafide',
            'serial' => 'G5/2026/0001', 'student_name' => 'Aarav Sharma',
            'adm_no' => 'HA/PN/2026/91300', 'class_name' => 'Prep',
            'section_name' => 'A', 'session' => '2026-2027',
            'reason' => 'G5 probe', 'issued_by' => 2,
            'issued_at' => now(),
        ]);
        $res = $this->actingAs($this->admin())
            ->get("/certificates/{$cert->id}/print");
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
        $res->assertSee('Phone: ' . self::PHONE, false);
        // The certificate letterhead carries address + phone only;
        // the school email is not printed on certificates.
        $res->assertDontSee(self::EMAIL);
    }

    public function test_circular_print_shows_new_address()
    {
        $this->saveG5Profile();
        $res = $this->actingAs($this->admin())->get('/circulars/1/print');
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
    }

    public function test_monthly_report_print_shows_new_address_phone_email()
    {
        $this->saveG5Profile();
        $res = $this->actingAs($this->admin())
            ->get(route('attendance.report.print', ['class_id' => 1]));
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
        $res->assertSee('Tel: ' . self::PHONE, false);
        $res->assertSee('Email: ' . self::EMAIL, false);
    }

    public function test_payslip_screen_shows_new_address_phone_email()
    {
        // Payroll 1 belongs to teacher@ (user 4); self-service payslip.
        $this->saveG5Profile();
        $res = $this->actingAs($this->teacher())->get('/my-payslips/1');
        $res->assertOk();
        $res->assertSee(self::ADDR, false);
        $res->assertSee('Tel: ' . self::PHONE, false);
        $res->assertSee('Email: ' . self::EMAIL, false);
    }

    public function test_ask_page_help_tile_shows_new_email()
    {
        $this->saveG5Profile();
        $res = $this->actingAs($this->admin())->get(route('ask.index'));
        $res->assertOk();
        $res->assertSee('mailto:' . self::EMAIL, false);
        $res->assertSee('Email the school office at ' . self::EMAIL, false);
    }

    public function test_privacy_and_terms_show_new_phone()
    {
        $this->saveG5Profile();
        $this->get(route('privacy_policy'))->assertOk()
            ->assertSee(self::PHONE, false);
        $this->get(route('terms_of_use'))->assertOk()
            ->assertSee(self::PHONE, false);
    }

    // ---------- Boundary: not every print carries contact fields ----------

    public function test_student_list_print_carries_no_contact_fields()
    {
        $this->saveG5Profile();
        $res = $this->actingAs($this->admin())->get('/students/list/1?print=1');
        $res->assertOk();
        $res->assertDontSee(self::ADDR);
        $res->assertDontSee(self::PHONE);
        $res->assertDontSee(self::EMAIL);
    }
}
