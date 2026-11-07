<?php

namespace App\Models;

use App\User;
use Eloquent;
use Illuminate\Support\Facades\Schema;

/**
 * One slot a teacher asks the timetable generator to favour
 * (owner ask 2026-11-06, audit gap G2). Rows are replaced as a
 * set by the teacher herself on My Slot Preferences — there is
 * deliberately no per-row UI and no other writer: the Admin does
 * not edit a teacher's preferences.
 *
 * A preference is a (weekday name, 1-based period number) pair —
 * the generator's own slot identity (see the migration note).
 * It is a REQUEST the generator tries to honour as a soft
 * tie-break only; it never overrides a clash, a cap or a target.
 */
class TeacherSlotPreference extends Eloquent
{
    /** Most slots one teacher may ask for (owner-facing promise on the page). */
    public const MAX_PER_TEACHER = 10;

    protected $fillable = ['user_id', 'day', 'period'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The form/grid key for one slot: "Monday|3". */
    public static function key(string $day, int $period): string
    {
        return $day . '|' . $period;
    }

    /**
     * A teacher's chosen slots as a set of "Day|period" keys —
     * what the preferences page renders as ticked cells.
     */
    public static function keysFor(int $userId): array
    {
        if (!Schema::hasTable('teacher_slot_preferences')) {
            return [];
        }
        $keys = [];
        foreach (static::where('user_id', $userId)->get() as $row) {
            $keys[static::key($row->day, (int) $row->period)] = true;
        }
        return $keys;
    }

    /**
     * Every teacher's preferences in the shape the generator's
     * placement sort consumes: [teacher_id => [day name =>
     * [period => true]]]. Loaded once per generate run (like the
     * caps maps), never per cell. Empty when nobody has asked for
     * anything — the generator's ordering is then exactly the
     * pre-preference ordering.
     */
    public static function mapForGeneration(): array
    {
        if (!Schema::hasTable('teacher_slot_preferences')) {
            return [];
        }
        $map = [];
        foreach (static::all() as $row) {
            $map[(int) $row->user_id][$row->day][(int) $row->period] = true;
        }
        return $map;
    }
}
