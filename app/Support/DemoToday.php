<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The demo dataset's fixed "today": Thursday 29 October 2026.
 *
 * Demo seeders must NEVER read the wall clock for content dates
 * (school events, circulars, book loans, attendance days,
 * assignment due dates, admission years). A seed relative to the
 * run date produces a DIFFERENT dataset every day it runs — on
 * 2026-10-30 the seeded Parent-Teacher Meeting landed on Mon,
 * 02 Nov 2026, collided with the date every exam test plants, and
 * turned CI red on three unrelated tests, while the same suite
 * stayed green on a snapshot seeded the day before. Anchoring
 * every content date to this one day makes a fresh seed identical
 * on any run date: the dataset the suite, the anchors and the
 * sales demo are all proven against. Pure created_at/updated_at
 * audit stamps still use the real clock — they are not content.
 */
class DemoToday
{
    public const DATE = '2026-10-29';

    public static function now(): Carbon
    {
        return Carbon::parse(self::DATE . ' 12:00:00');
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }
}
