<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The one canonical school-week order for DISPLAY lists of weekday
 * names (Monday first, Sunday last).
 *
 * Timetable cells are stored unordered — the record grids read them
 * back ordered by period start time, so taking the days in encounter
 * order renders rows like Friday, Monday, Thursday… (the owner's
 * printed Prep A timetable, 2026-11-07). Every builder that turns a
 * set of cells into a day list for a screen, a print or an API
 * payload must pass the names through sortNames() so the order can
 * never drift back.
 */
class WeekDays
{
    public const NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** Weekday name => position (0 = Monday). */
    public static function order(): array
    {
        return array_flip(self::NAMES);
    }

    /**
     * The given weekday names, de-duplicated and sorted into
     * canonical week order. Names outside the canonical list keep
     * their relative order at the end — a stored day is never
     * silently dropped from a grid.
     */
    public static function sortNames(iterable $days): Collection
    {
        $order = self::order();

        return collect($days)->unique()->values()
            ->sortBy(function ($day) use ($order) {
                return $order[$day] ?? 99;
            })->values();
    }
}
