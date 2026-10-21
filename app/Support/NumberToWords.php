<?php

namespace App\Support;

/**
 * Number-to-words for Hackstra receipts and invoices.
 *
 * Uses the Indian numbering system (Thousand, Lakh, Crore) to match
 * App\Helpers\Money's Indian digit grouping, so a fee of 100000 reads
 * "One Lakh" here just as it prints "1,00,000" there.
 *
 * Pure and dependency-free (like App\Support\GradeCalculator) so it can
 * be unit tested without booting the framework.
 */
class NumberToWords
{
    private static $ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight',
        'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen',
        'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private static $tens = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy',
        'Eighty', 'Ninety',
    ];

    /**
     * Convert a whole number to words, Indian system.
     * e.g. 123456 -> "One Lakh Twenty Three Thousand Four Hundred Fifty Six"
     */
    public static function convert($number): string
    {
        $number = (int) $number;
        if ($number === 0) {
            return 'Zero';
        }
        if ($number < 0) {
            return 'Minus ' . self::convert(abs($number));
        }

        $parts = [];

        $crore = intdiv($number, 10000000);
        $rest = $number % 10000000;
        if ($crore > 0) {
            $parts[] = ($crore <= 999 ? self::underThousand($crore) : self::convert($crore)) . ' Crore';
        }

        $lakh = intdiv($rest, 100000);
        $rest %= 100000;
        if ($lakh > 0) {
            $parts[] = self::twoDigits($lakh) . ' Lakh';
        }

        $thousand = intdiv($rest, 1000);
        $rest %= 1000;
        if ($thousand > 0) {
            $parts[] = self::twoDigits($thousand) . ' Thousand';
        }

        if ($rest > 0) {
            $parts[] = self::underThousand($rest);
        }

        return implode(' ', $parts);
    }

    /**
     * Amount in words for receipts.
     * e.g. 100000    -> "Rupees One Lakh Only"
     *      1500.50   -> "Rupees One Thousand Five Hundred and Paise Fifty Only"
     *      0.75      -> "Paise Seventy Five Only"
     *      0         -> "Rupees Zero Only"
     */
    public static function inRupees($amount): string
    {
        $value = (float) ($amount ?? 0);
        $prefix = '';
        if ($value < 0) {
            $prefix = 'Minus ';
            $value = abs($value);
        }

        $rupees = (int) floor($value);
        $paise = (int) round(($value - $rupees) * 100);
        if ($paise === 100) {
            $rupees++;
            $paise = 0;
        }

        if ($paise > 0 && $rupees > 0) {
            return $prefix . 'Rupees ' . self::convert($rupees) . ' and Paise ' . self::convert($paise) . ' Only';
        }
        if ($paise > 0) {
            return $prefix . 'Paise ' . self::convert($paise) . ' Only';
        }

        return $prefix . 'Rupees ' . self::convert($rupees) . ' Only';
    }

    /** Words for 0-99. */
    private static function twoDigits(int $n): string
    {
        if ($n < 20) {
            return self::$ones[$n];
        }
        $word = self::$tens[intdiv($n, 10)];
        if ($n % 10 > 0) {
            $word .= ' ' . self::$ones[$n % 10];
        }
        return $word;
    }

    /** Words for 1-999 ("Four Hundred Fifty Six"). */
    private static function underThousand(int $n): string
    {
        if ($n < 100) {
            return self::twoDigits($n);
        }
        $word = self::$ones[intdiv($n, 100)] . ' Hundred';
        if ($n % 100 > 0) {
            $word .= ' ' . self::twoDigits($n % 100);
        }
        return $word;
    }
}
