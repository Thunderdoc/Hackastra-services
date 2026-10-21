<?php

namespace App\Helpers;

/**
 * Money formatting for Hackstra.
 *
 * Indian digit grouping (lakh/crore): 100000 -> "1,00,000".
 * Amounts in the database are whole rupees in legacy tables, so all
 * formatting here works on integers / numeric strings and never floats
 * money through binary floating point for display totals.
 */
class Money
{
    public static function indianFormat($amount): string
    {
        $negative = false;
        if (is_string($amount)) {
            $amount = preg_replace('/[^0-9.\-]/', '', $amount);
        }
        $value = (float) ($amount ?? 0);
        if ($value < 0) {
            $negative = true;
            $value = abs($value);
        }

        $parts = explode('.', number_format($value, 2, '.', ''));
        $whole = $parts[0];
        $fraction = $parts[1];

        if (strlen($whole) > 3) {
            $lastThree = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest . ',' . $lastThree;
        }

        $result = $whole;
        if ((int) $fraction !== 0) {
            $result .= '.' . rtrim($fraction, '0');
        }

        return ($negative ? '-' : '') . $result;
    }

    public static function inr($amount): string
    {
        $formatted = self::indianFormat($amount);
        if (strpos($formatted, '-') === 0) {
            return '-₹ ' . substr($formatted, 1);
        }

        return '₹ ' . $formatted;
    }
}
