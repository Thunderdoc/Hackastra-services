<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Setting;

/**
 * Primary vs Secondary school-day levels (batch upgrade
 * 2026-10-25, owner order: one scope=all run builds both levels
 * from 9:00, each with its own day length).
 *
 * Level rule: the first 6 classes by id are Primary (Prep–Class 5
 * in the standard 13-class ladder); everything after is Secondary.
 * Both levels share the 9:00 start, 40-minute periods, a short
 * break after period 2 and lunch after period 4 — they differ
 * only in day length: Primary ends ~2:00 PM (6 periods),
 * Secondary ends 3:20 PM (8 periods). Every number is editable
 * on the generate form, and the last-used values are remembered
 * in the settings table (tt_gen_primary / tt_gen_secondary).
 */
class SchoolLevel
{
    public const PRIMARY_COUNT = 6;

    public static function presets(): array
    {
        $shared = [
            'start_time' => '09:00', 'period_minutes' => 40,
            'lunch_after' => 4, 'lunch_minutes' => 45,
            'break_after' => 2, 'break_minutes' => 15, 'days' => 5,
        ];
        return [
            'primary' => array_merge($shared, ['periods_per_day' => 6]),
            'secondary' => array_merge($shared, ['periods_per_day' => 8]),
        ];
    }

    public static function levelForClassId(int $classId): string
    {
        $ids = MyClass::orderBy('id')->pluck('id')->all();
        $pos = array_search($classId, $ids, true);
        return ($pos !== false && $pos < self::PRIMARY_COUNT) ? 'primary' : 'secondary';
    }

    public static function levelsForClasses($classes): array
    {
        $ids = MyClass::orderBy('id')->pluck('id')->all();
        $levels = [];
        foreach ($classes as $class) {
            $pos = array_search((int) $class->id, $ids, true);
            $levels[(int) $class->id] = ($pos !== false && $pos < self::PRIMARY_COUNT) ? 'primary' : 'secondary';
        }
        return $levels;
    }

    public static function loadConfig(string $level): array
    {
        $raw = Qs::getSetting('tt_gen_' . $level);
        $saved = $raw ? json_decode($raw, true) : null;
        return array_merge(self::presets()[$level], is_array($saved) ? $saved : []);
    }

    public static function saveConfig(string $level, array $cfg): void
    {
        $keys = ['start_time', 'period_minutes', 'periods_per_day', 'lunch_after', 'lunch_minutes', 'break_after', 'break_minutes', 'days'];
        $filtered = array_intersect_key($cfg, array_flip($keys));
        Setting::updateOrCreate(['type' => 'tt_gen_' . $level], ['description' => json_encode($filtered)]);
    }
}
