<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Certificate;
use App\Models\ExamDate;
use App\Models\Setting;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Agent B round 20 — branding-file behaviour on print blades.
 *
 * Settings facts: the settings form writes the stamp under key
 * `school_stamp` (seeder + SettingController agree); the logo under
 * `logo`; the signature under `principal_signature`. Every print
 * consumer must read those same keys, or an uploaded file silently
 * never prints.
 *
 * F-R20-1 (proven failing-first here): CertificateController::print()
 * built its stamp via settingImage('stamp') — a setting key NO writer
 * ever creates — so an uploaded school stamp NEVER rendered on
 * bonafide/transfer certificates, even though both blades carry a
 * capped stamp slot (max-height 90px). Fixed by reading
 * 'school_stamp' instead; the existence guard is unchanged.
 *
 * All tests run on snapshot DBs only; fixture PNGs are deleted in
 * tearDown and settings changes roll back with the transaction.
 */
class OwnerPrintBrandingRound20Test extends TestCase
{
    use DatabaseTransactions;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        foreach (['r20-logo.png', 'r20-stamp.png', 'r20-sign.png'] as $name) {
            Storage::disk('public')->put('uploads/' . $name, $png);
            $this->files[] = 'uploads/' . $name;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            Storage::disk('public')->delete($f);
        }
        parent::tearDown();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function setSetting(string $type, string $value): void
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    protected function setBranding(): void
    {
        $this->setSetting('logo', '/storage/uploads/r20-logo.png');
        $this->setSetting('school_stamp', '/storage/uploads/r20-stamp.png');
        $this->setSetting('principal_signature', '/storage/uploads/r20-sign.png');
        $this->setSetting('principal_name', 'Rajesh Iyer');
    }

    protected function makeCertificate(string $type): Certificate
    {
        $s = $this->student();
        return Certificate::create([
            'user_id' => $s->id,
            'student_record_id' => 1,
            'type' => $type,
            'serial' => 'R20/' . strtoupper($type) . '/1',
            'student_name' => 'Aarav Sharma',
            'adm_no' => 'HA/PN/2026/91300',
            'class_name' => 'Prep',
            'section_name' => 'A',
            'session' => '2026-2027',
            'reason' => 'Test certificate',
            'issued_by' => $this->admin()->id,
            'issued_at' => now(),
        ]);
    }

    public function test_certificate_bonafide_renders_uploaded_stamp()
    {
        $this->setBranding();
        $cert = $this->makeCertificate('bonafide');
        $response = $this->actingAs($this->admin())
            ->get(route('certificates.print', $cert->id));
        $response->assertStatus(200);
        $response->assertSee('r20-stamp.png', false);
        $response->assertSee('max-height: 90px', false);
        $response->assertSee('r20-logo.png', false);
        $response->assertSee('r20-sign.png', false);
        $response->assertSee('max-width: 320px', false);
    }

    public function test_certificate_transfer_renders_uploaded_stamp()
    {
        $this->setBranding();
        $cert = $this->makeCertificate('transfer');
        $response = $this->actingAs($this->admin())
            ->get(route('certificates.print', $cert->id));
        $response->assertStatus(200);
        $response->assertSee('r20-stamp.png', false);
        $response->assertSee('max-height: 90px', false);
        $response->assertSee('r20-sign.png', false);
    }

    public function test_certificate_missing_stamp_file_renders_no_broken_image()
    {
        // Guard shape (certificate only): settingImage() returns null
        // when the file is not on the public disk, so the blade's @if
        // emits no <img> at all — never a broken-image box.
        $this->setSetting('school_stamp', '/storage/uploads/r20-ghost.png');
        $cert = $this->makeCertificate('bonafide');
        $response = $this->actingAs($this->admin())
            ->get(route('certificates.print', $cert->id));
        $response->assertStatus(200);
        $response->assertDontSee('r20-ghost.png', false);
        $response->assertDontSee('School stamp', false);
    }

    public function test_certificate_without_branding_renders_cleanly()
    {
        // Live path today: settings hold no logo/stamp/signature.
        // The certificate must still render 200 with no branding imgs.
        $cert = $this->makeCertificate('bonafide');
        $response = $this->actingAs($this->admin())
            ->get(route('certificates.print', $cert->id));
        $response->assertStatus(200);
        $response->assertSee('Aarav Sharma', false);
        $response->assertDontSee('r20-stamp.png', false);
        $response->assertDontSee('r20-logo.png', false);
    }

    public function test_annual_print_renders_uploaded_branding()
    {
        // Control: the marksheet path already maps school_stamp
        // correctly (ReportCard::buildAnnual), so this passes both
        // before and after the certificate fix.
        $this->setBranding();
        $response = $this->actingAs($this->admin())
            ->get(route('marks.print_annual', [
                'id' => Qs::hash($this->student()->id), 'year' => '2026-2027',
            ]));
        $response->assertStatus(200);
        $response->assertSee('r20-logo.png', false);
        $response->assertSee('r20-stamp.png', false);
        $response->assertSee('r20-sign.png', false);
    }

    public function test_date_sheet_print_renders_uploaded_stamp_and_signature()
    {
        // Control: the date-sheet path already maps school_stamp
        // correctly (ExamDateSheet::schoolInfo), so this passes both
        // before and after the certificate fix.
        $this->setBranding();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 1, 'subject_id' => 1,
            'exam_date' => '2026-12-01', 'start_time' => '09:00:00',
            'note' => 'Main Hall',
        ]);
        $response = $this->actingAs($this->admin())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 1]));
        $response->assertStatus(200);
        $response->assertSee('r20-stamp.png', false);
        // NOTE: 'ds-stamp' as a bare string also appears in the
        // blade's <style> block; target the <img> element itself.
        $response->assertSee('<img class="ds-stamp"', false);
        $response->assertSee('r20-sign.png', false);
        $response->assertSee('Main Hall', false);
    }

    public function test_date_sheet_print_without_branding_renders_no_stamp_or_signature_img()
    {
        $this->setSetting('school_stamp', '');
        $this->setSetting('principal_signature', '');
        $response = $this->actingAs($this->admin())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 1]));
        $response->assertStatus(200);
        $response->assertDontSee('<img class="ds-stamp"', false);
        $response->assertDontSee('<img class="ds-sign-img"', false);
    }

    public function test_annual_print_without_branding_uses_monogram_not_broken_image()
    {
        // The shared seed DB carries a dead-8993 logo URL (local-only
        // artifact), so "no branding" must be set explicitly here;
        // otherwise $rcLogo is non-empty and the <img> is emitted.
        $this->setSetting('logo', '');
        $this->setSetting('school_stamp', '');
        $this->setSetting('principal_signature', '');
        $response = $this->actingAs($this->admin())
            ->get(route('marks.print_annual', [
                'id' => Qs::hash($this->student()->id), 'year' => '2026-2027',
            ]));
        $response->assertStatus(200);
        $response->assertSee('rc-logo-tile', false);
        // NOTE: 'rc-logo-img' as a bare string also appears in the
        // partial's <style> block, so the no-image assertion must
        // target the <img> element itself, not the class name.
        $response->assertDontSee('<img class="rc-logo-img"', false);
    }
}
