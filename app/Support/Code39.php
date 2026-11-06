<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Code39 barcode encoder (item 14, owner 2026-11-01: "the barcode
 * is REAL"). Standard Code39: every character is 9 elements
 * (5 bars, 4 spaces), exactly 3 of them wide; the message is
 * wrapped in '*' start/stop characters with a one-module gap
 * between characters. pattern() returns the element widths in
 * modules (narrow = 1, wide = 2), starting and ending with a bar.
 * decode() is the exact inverse, kept so the test suite can prove
 * the round trip: pattern -> widths -> the original value.
 *
 * Supported charset is the Code39 standard set: A-Z, 0-9 and
 * - . space $ / + % (lowercase folds to uppercase, as scanners
 * expect). Anything else throws — a card value that cannot be
 * encoded must fail loudly at build time, never render a fake.
 */
class Code39
{
    /** char => 9-element pattern, b = bar, s = space; n/w per element. */
    private const TABLE = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnwnnw',
        '8' => 'wnnwnwnnn', '9' => 'nnwwnwnnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw',
        'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn',
        'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn', 'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww',
        'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn',
        'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw',
        'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    /** Element widths (modules) for the full symbol, '*' wrapped. */
    public static function pattern(string $value): array
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            throw new InvalidArgumentException('Code39 value is empty.');
        }
        $widths = [];
        $chars = '*' . $value . '*';
        $len = strlen($chars);
        for ($i = 0; $i < $len; $i++) {
            $ch = $chars[$i];
            if (!isset(self::TABLE[$ch])) {
                throw new InvalidArgumentException("Code39 cannot encode '{$ch}'.");
            }
            foreach (str_split(self::TABLE[$ch]) as $el) {
                $widths[] = $el === 'w' ? 2 : 1;
            }
            if ($i < $len - 1) {
                $widths[] = 1; // inter-character gap (a narrow space)
            }
        }

        return $widths;
    }

    /** Total width of the symbol in modules, quiet zones excluded. */
    public static function modules(string $value): int
    {
        return array_sum(self::pattern($value));
    }

    /** Inverse of pattern(): widths back to the decoded value. */
    public static function decode(array $widths): string
    {
        $out = '';
        $pos = 0;
        $total = count($widths);
        while ($pos < $total) {
            $chunk = array_slice($widths, $pos, 9);
            if (count($chunk) < 9) {
                throw new InvalidArgumentException('Truncated Code39 symbol.');
            }
            $key = '';
            foreach ($chunk as $w) {
                $key .= $w >= 2 ? 'w' : 'n';
            }
            $ch = array_search($key, self::TABLE, true);
            if ($ch === false) {
                throw new InvalidArgumentException('Unknown Code39 element pattern.');
            }
            $out .= $ch;
            $pos += 9;
            if ($pos < $total) {
                $pos += 1; // inter-character gap
            }
        }
        if (strlen($out) < 2 || $out[0] !== '*' || substr($out, -1) !== '*') {
            throw new InvalidArgumentException('Code39 symbol lacks start/stop characters.');
        }

        return substr($out, 1, -1);
    }

    /**
     * Render the symbol as a PNG data URI via GD (available in the
     * local PHP and both Docker images). Quiet zones of 10 modules
     * on both sides, per spec.
     */
    public static function pngDataUri(string $value, int $height = 64, int $scale = 3): string
    {
        $widths = self::pattern($value);
        $quiet = 10;
        $imgW = (array_sum($widths) + 2 * $quiet) * $scale;
        $img = imagecreatetruecolor($imgW, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 16, 38, 59);
        imagefill($img, 0, 0, $white);

        $x = $quiet * $scale;
        $isBar = true;
        foreach ($widths as $w) {
            $barW = $w * $scale;
            if ($isBar) {
                imagefilledrectangle($img, $x, 0, $x + $barW - 1, $height - 1, $black);
            }
            $x += $barW;
            $isBar = !$isBar;
        }

        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
