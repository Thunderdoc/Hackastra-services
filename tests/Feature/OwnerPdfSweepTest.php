<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Certificate;
use App\Models\Circular;
use App\Models\ExamDate;
use App\Models\Mark;
use App\Models\StudentRecord;
use App\Support\ExamDateSheet;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * PDF sweep 2026-11-06 — regression pins for the defects the sweep
 * found by rendering every PDF surface through the app renderer:
 *
 *  1. Settings brand images stored as PUBLIC WEBROOT paths (the
 *     demo school's stamp + principal signature live in
 *     public/demo-assets, not /storage) were silently dropped by
 *     every pdfImageDataUri copy that only resolved /storage —
 *     annual report cards, date sheets and circulars printed with
 *     no signature and no seal. The fixed PDFs embed the images
 *     (raster image objects in the byte stream; the broken ones
 *     had exactly zero).
 *  2. CertificateController::settingImage had the same blind spot
 *     (storage disk only), so certificates printed seal-less.
 *  3. The student attendance PDF stamped its footer after
 *     $pdf->output() instead of render(), which drew the footer
 *     text as mojibake glyphs. reportPdf()'s render-first order
 *     is the proven pattern; both footer-stamping methods in
 *     AttendanceController now use it.
 *  4. The admit card's school name (an h3 on a white card) was
 *     washed to near-white on screen by the dark theme's generic
 *     .content h3 rule; the blade now carries a scoped rule that
 *     out-specifies it.
 */
class OwnerPdfSweepTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_pdf_image_resolver_inlines_public_webroot_assets()
    {
        $sig = ExamDateSheet::pdfImageDataUri('/demo-assets/principal-signature.png');
        $this->assertStringStartsWith('data:image/png;base64,', $sig);

        $stamp = ExamDateSheet::pdfImageDataUri('/demo-assets/school-stamp.png');
        $this->assertStringStartsWith('data:image/png;base64,', $stamp);

        // A webroot path that does not exist stays untouched —
        // never a fabricated data URI.
        $missing = ExamDateSheet::pdfImageDataUri('/demo-assets/does-not-exist.png');
        $this->assertSame('/demo-assets/does-not-exist.png', $missing);
    }

    public function test_annual_report_pdf_embeds_signature_and_stamp()
    {
        $studentId = Mark::where('exam_id', 1)->value('student_id');
        $this->assertNotEmpty($studentId);

        $res = $this->actingAs($this->admin())
            ->get(route('marks.pdf_annual', [Qs::hash($studentId), '2026-2027']))
            ->assertOk();
        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($bytes, '/Subtype /Image'),
            'annual report PDF must embed the Settings signature/stamp images'
        );
    }

    public function test_date_sheet_pdf_embeds_principal_signature()
    {
        foreach ([7, 8] as $i => $subjectId) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => 2,
                'subject_id' => $subjectId,
                'exam_date' => '2026-11-1' . (6 + $i),
                'start_time' => '09:00',
                'note' => 'Main Hall',
            ]);
        }

        $res = $this->actingAs($this->admin())
            ->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => 2]))
            ->assertOk();
        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($bytes, '/Subtype /Image'),
            'date sheet PDF must embed the principal signature image'
        );
    }

    public function test_circular_pdf_embeds_seal_and_signature()
    {
        $circular = Circular::firstOrFail();

        $res = $this->actingAs($this->admin())
            ->get(route('circulars.pdf', $circular->id))
            ->assertOk();
        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($bytes, '/Subtype /Image'),
            'circular PDF must embed the school seal and principal signature'
        );
    }

    public function test_certificate_print_carries_seal_and_signature_images()
    {
        $sr = StudentRecord::where('grad', 0)->firstOrFail();
        $this->actingAs($this->admin())->post(route('certificates.store'), [
            'student_record_id' => $sr->id,
            'type' => 'bonafide',
        ]);
        $cert = Certificate::where('student_record_id', $sr->id)
            ->where('type', 'bonafide')->latest('id')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get(route('certificates.print', $cert->id))->assertOk()->getContent();

        $this->assertStringContainsString((string) Qs::getSetting('school_stamp'), $html);
        $this->assertStringContainsString((string) Qs::getSetting('principal_signature'), $html);
    }

    public function test_attendance_footer_is_stamped_after_render_not_output()
    {
        $src = file_get_contents(app_path('Http/Controllers/SupportTeam/AttendanceController.php'));
        // The mojibake mechanism: page_text registered after
        // output() draws with a polluted font state. Neither
        // footer-stamping method may call output() any more.
        $this->assertStringNotContainsString('$pdf->output();', $src);
        $this->assertStringContainsString('$pdf->render();', $src);

        $student = StudentRecord::where('grad', 0)->firstOrFail();
        $res = $this->actingAs($this->admin())
            ->get(route('attendance.student_report.pdf', $student->user_id))
            ->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_admit_print_school_name_survives_the_dark_theme()
    {
        $blade = file_get_contents(resource_path('views/pages/support_team/exams/admit_print.blade.php'));
        $this->assertStringContainsString(
            'html.hk-dark .content .admit-card .admit-head-main h3',
            $blade
        );

        $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, 2]) . '?section_id=4')
            ->assertOk();
    }
}
