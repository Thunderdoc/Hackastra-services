<?php

namespace Tests\Unit;

use App\Support\WhatsApp\WhatsAppPhone;
use Tests\TestCase;

/**
 * F1 (Round 4, design §12.1) — WhatsAppPhone normalisation cases.
 * Extends the app TestCase because normalize() reads the default
 * country code from config/whatsapp.php.
 */
class WhatsAppPhoneTest extends TestCase
{
    public function test_seeded_format_normalises()
    {
        $this->assertSame('919811000006', WhatsAppPhone::normalize('+91 98110 00006'));
    }

    public function test_bare_ten_digits_get_country_code()
    {
        $this->assertSame('919811000006', WhatsAppPhone::normalize('9811000006'));
    }

    public function test_leading_zero_is_dropped()
    {
        $this->assertSame('919811000006', WhatsAppPhone::normalize('09811000006'));
    }

    public function test_placeholder_and_garbage_are_null()
    {
        $this->assertNull(WhatsAppPhone::normalize('0123456789'));
        $this->assertNull(WhatsAppPhone::normalize('123'));
        $this->assertNull(WhatsAppPhone::normalize(''));
        $this->assertNull(WhatsAppPhone::normalize(null));
        $this->assertNull(WhatsAppPhone::normalize('not a phone'));
    }

    public function test_international_without_plus_is_kept()
    {
        $this->assertSame('919811000006', WhatsAppPhone::normalize('91 98110 00006'));
        $this->assertSame('14155552671', WhatsAppPhone::normalize('+1 415 555 2671'));
    }
}
