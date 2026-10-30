<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: the Bonafide and Transfer certificate
 * PDFs must print beautifully ("mast"). Both prints now render
 * as a framed certificate sheet — navy + gold double frame,
 * watermark, dynamic school branding from Settings, seal and
 * signature blocks, and a verification footer — instead of the
 * old plain page. These pins hold the sheet structure and the
 * certificate wording so a later edit cannot quietly flatten
 * them again.
 */
class OwnerCertificatePrintSheetTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function issue(string $type, array $extra = []): Certificate
    {
        $sr = StudentRecord::where('grad', 0)->with(['user', 'my_class', 'section'])->first();
        $this->actingAs($this->admin());
        $this->post(route('certificates.store'), array_merge([
            'student_record_id' => $sr->id,
            'type' => $type,
        ], $extra));
        return Certificate::where('student_record_id', $sr->id)->where('type', $type)->latest('id')->firstOrFail();
    }

    public function test_bonafide_print_is_a_framed_certificate_sheet()
    {
        $cert = $this->issue('bonafide');
        $html = $this->actingAs($this->admin())
            ->get(route('certificates.print', $cert->id))->assertOk()->getContent();

        $this->assertStringContainsString('cert-sheet', $html);
        $this->assertStringContainsString('cert-wm', $html);
        $this->assertStringContainsString('BONAFIDE CERTIFICATE', $html);
        $this->assertStringContainsString('bonafide student of this school', $html);
        $this->assertStringContainsString('(School Seal)', $html);
        $this->assertStringContainsString('Verify at the school office', $html);
        $this->assertStringContainsString($cert->serial, $html);
        // Branding is the Settings school name, never a hardcode.
        $this->assertStringContainsString('Hackstra Academy', $html);
    }

    public function test_transfer_print_is_a_framed_certificate_sheet_with_reason()
    {
        $cert = $this->issue('transfer', ['reason' => 'Parent transferred']);
        $html = $this->actingAs($this->admin())
            ->get(route('certificates.print', $cert->id))->assertOk()->getContent();

        $this->assertStringContainsString('cert-sheet', $html);
        $this->assertStringContainsString('cert-wm', $html);
        $this->assertStringContainsString('TRANSFER CERTIFICATE', $html);
        $this->assertStringContainsString('Reason for leaving', $html);
        $this->assertStringContainsString('Parent transferred', $html);
        $this->assertStringContainsString('Verify at the school office', $html);
    }
}
