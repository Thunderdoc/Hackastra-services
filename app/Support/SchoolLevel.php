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
 *
 * Batch 2026-10-28 (exam-timetable same-sheet bug): the id-order
 * rule alone misclassifies a class created AFTER the seed — a
 * school that adds UKG/Nursery/LKG later gets a high id, so the
 * early-years class counted as Secondary and its exam papers
 * shared sessions with seniors (Class 10's exact slots). A class
 * whose NAME marks it as early years is therefore Primary
 * regardless of id; the id-order rule stays as the fallback for
 * every other name. Seeded classes are unchanged by this:
 * Prep matches the name rule, Class 1–5 stay Primary by id,
 * Class 6–12 stay Secondary by id.
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

    /**
     * Early-years class names: Nursery, LKG, UKG, KG, Prep and
     * their pre-primary variants, as whole words — so "UKG-Test"
     * and "KG-1" match, while "Class 10" or "Preparatory Course"
     * do not (word boundaries keep KG out of UKG's shadow: UKG
     * is listed on its own, and \bkg\b never matches inside it).
     */
    public static function isEarlyYearsName(?string $name): bool
    {
        if (!$name) {
            return false;
        }
        return (bool) preg_match('/\b(nursery|lkg|ukg|kg|prep|playgroup|kindergarten|montessori|pre-?kg|pre-?nursery|pre-?primary|reception)\b/i', $name);
    }

    public static function levelForClassId(int $classId): string
    {
        $class = MyClass::find($classId);
        if ($class && self::isEarlyYearsName($class->name)) {
            return 'primary';
        }
        $ids = MyClass::orderBy('id')->pluck('id')->all();
        $pos = array_search($classId, $ids, true);
        return ($pos !== false && $pos < self::PRIMARY_COUNT) ? 'primary' : 'secondary';
    }

    public static function levelsForClasses($classes): array
    {
        $ids = MyClass::orderBy('id')->pluck('id')->all();
        $levels = [];
        foreach ($classes as $class) {
            if (self::isEarlyYearsName($class->name ?? null)) {
                $levels[(int) $class->id] = 'primary';
                continue;
            }
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
