<?php

namespace App\Support;

use App\Models\SchoolDayType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * T2 — the day-type engine (master prompt, owner order
 * 2026-10-29). One question, answered in one place: what kind of
 * day is this date for THIS school?
 *
 *   normal     — a regular teaching day (the default: no row).
 *   half_day   — teaching, but only the first N periods run
 *                (N = the row's half_day_periods; null = the day
 *                still counts as teaching with no window cut).
 *   exam_day   — the exam schedule runs; no regular periods.
 *   event_only — school is open for an event; no regular periods.
 *   holiday    — school closed.
 *   closure    — emergency closure.
 *
 * Doctrine: a non-teaching day never expects NEW period
 * registers and never gets substitution cover arranged; history
 * (submitted registers, existing rows) is never hidden or
 * touched. Half-day windows intersect with partial-leave windows
 * wherever both apply.
 */
class DayType
{
    public static function types(): array
    {
        return ['half_day', 'exam_day', 'event_only', 'holiday', 'closure'];
    }

    public static function labels(): array
    {
        return [
            'normal' => 'Normal day',
            'half_day' => 'Half day',
            'exam_day' => 'Exam day',
            'event_only' => 'Event only',
            'holiday' => 'Holiday',
            'closure' => 'Emergency closure',
        ];
    }

    public static function rowFor($date): ?SchoolDayType
    {
        if (!Schema::hasTable('school_day_types')) {
            return null;
        }
        $d = $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString();

        return SchoolDayType::whereDate('date', $d)->first();
    }

    public static function forDate($date): string
    {
        $row = static::rowFor($date);

        return $row ? (string) $row->type : 'normal';
    }

    public static function label($date): string
    {
        $labels = static::labels();

        return $labels[static::forDate($date)] ?? 'Normal day';
    }

    /** Teaching days expect registers and cover: normal + half. */
    public static function isTeachingDay($date): bool
    {
        return in_array(static::forDate($date), ['normal', 'half_day'], true);
    }

    /**
     * The running-period window for a half day: [1, N], or null
     * when the date carries no window (normal day, non-teaching
     * day, or a half day with no period count set).
     */
    public static function windowFor($date): ?array
    {
        $row = static::rowFor($date);
        if (!$row || $row->type !== 'half_day' || !$row->half_day_periods) {
            return null;
        }

        return [1, (int) $row->half_day_periods];
    }
}
