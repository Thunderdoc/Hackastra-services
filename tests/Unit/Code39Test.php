<?php

namespace Tests\Unit;

use App\Support\Code39;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Item 14 (owner, 2026-11-01): the ID-card barcode is a REAL
 * Code39 — the encoder's output must decode back to exactly the
 * value that went in (admission numbers and staff codes carry
 * slashes, which the Code39 charset covers).
 */
class Code39Test extends TestCase
{
    public function test_round_trip_for_card_values()
    {
        foreach (['HA/PN/2026/1038', 'HA/EMP/2026/004', '1001', 'A B-C.D', 'X$/+%'] as $value) {
            $this->assertSame($value, Code39::decode(Code39::pattern($value)), $value);
        }
    }

    public function test_lowercase_folds_to_uppercase()
    {
        $this->assertSame('ABC', Code39::decode(Code39::pattern('abc')));
    }

    public function test_every_character_has_exactly_three_wide_elements()
    {
        $widths = Code39::pattern('Z');
        // '*' + 'Z' + '*' = 3 characters x 9 elements + 2 gaps.
        $this->assertCount(29, $widths);
        $z = array_slice($widths, 10, 9);
        $this->assertSame(3, count(array_filter($z, function ($w) { return $w === 2; })));
    }

    public function test_unencodable_characters_fail_loudly()
    {
        $this->expectException(InvalidArgumentException::class);
        Code39::pattern('HA#2026');
    }

    public function test_png_is_a_real_png_data_uri()
    {
        $uri = Code39::pngDataUri('HA/PN/2026/1038');
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $raw = base64_decode(substr($uri, strlen('data:image/png;base64,')));
        $this->assertSame("\x89PNG", substr($raw, 0, 4));
    }
}
