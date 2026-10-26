<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 30 (mobile): the standalone fee-receipt document had no
 * viewport meta and its 4-column info table clipped long values
 * (admission numbers) on phone screens — the receipt's own
 * overflow:hidden cut the text off instead of scrolling. The
 * document now declares a viewport and lets info cells wrap
 * anywhere, so nothing is clipped on a 360px screen; the print
 * and PDF renderings are untouched (same blade, same @page).
 */
class OwnerWave30Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function paymentRecordId()
    {
        return DB::table('payment_records')->orderBy('id')->value('id');
    }

    public function test_receipt_page_is_mobile_ready()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('payments.receipts', Qs::hash($this->paymentRecordId())));
        $response->assertOk();
        $response->assertSee('name="viewport"', false);
        $response->assertSee('overflow-wrap: anywhere', false);
    }

    public function test_receipt_pdf_still_downloads()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('payments.pdf_receipts', Qs::hash($this->paymentRecordId())));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
