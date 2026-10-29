<?php

namespace App\Support;

use App\Models\Room;
use App\Models\StudentRecord;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use Illuminate\Support\Carbon;

/**
 * T3 — room placement (master prompt, owner order 2026-10-29).
 * One doctrine, shared by the generator's post-placement pass
 * and any manual room assignment:
 *
 *   - A room hosts ONE period at a time: same room + same
 *     weekday + overlapping slot = a clash, always refused.
 *     Touching slots (one ends as the next begins) do not clash
 *     — the same rule substitutions use.
 *   - A needs_lab subject goes ONLY in a lab. If no lab is free
 *     the cell stays room-less and is REPORTED — it is never
 *     silently parked in a classroom.
 *   - A regular subject takes any free room, preferring the
 *     smallest room that seats the class. If only a too-small
 *     room is free it is still assigned (a small room beats no
 *     room) and REPORTED as over capacity so the Admin decides.
 *   - Deterministic: rooms are considered in (capacity, id)
 *     order, cells in (day, slot) order — same input, same
 *     assignment, every run.
 */
class RoomAssigner
{
    protected static function toMinutes($time): ?int
    {
        if (!$time) {
            return null;
        }
        // time_tables stores UNIX timestamps in timestamp_from/to
        // (built with strtotime in the app timezone); slot-style
        // 'H:i' strings are accepted too. date() is used for the
        // timestamp branch on purpose: Carbon::createFromTimestamp
        // reads the value as UTC, which would shift every window
        // by the timezone offset and hide real clashes.
        if (is_numeric($time) && (int) $time > 86400) {
            return ((int) date('H', (int) $time)) * 60 + (int) date('i', (int) $time);
        }
        try {
            $c = Carbon::parse('2000-01-01 ' . $time);
        } catch (\Throwable $e) {
            return null;
        }

        return ((int) $c->format('H')) * 60 + (int) $c->format('i');
    }

    protected static function overlaps(int $aFrom, int $aTo, int $bFrom, int $bTo): bool
    {
        return $aFrom < $bTo && $bFrom < $aTo;
    }

    /**
     * Busy intervals per room for one weekday, from every cell
     * that already carries a room. Shape:
     * [room_id => [ ['from'=>m,'to'=>m,'tt_id'=>id], ... ]]
     */
    public static function busyByRoom(string $day, ?int $ignoreTtrId = null): array
    {
        $busy = [];
        $cells = TimeTable::where('day', $day)->whereNotNull('room_id')
            ->when($ignoreTtrId, function ($q) use ($ignoreTtrId) {
                $q->where('ttr_id', '!=', $ignoreTtrId);
            })->get(['id', 'room_id', 'timestamp_from', 'timestamp_to']);
        foreach ($cells as $cell) {
            $from = static::toMinutes($cell->timestamp_from);
            $to = static::toMinutes($cell->timestamp_to);
            if ($from === null || $to === null) {
                continue;
            }
            $busy[(int) $cell->room_id][] = ['from' => $from, 'to' => $to, 'tt_id' => (int) $cell->id];
        }

        return $busy;
    }

    /** The id of a cell already using $roomId in that window, or null. */
    public static function clashFor(int $roomId, string $day, $from, $to, ?int $ignoreTtId = null): ?int
    {
        $f = static::toMinutes($from);
        $t = static::toMinutes($to);
        if ($f === null || $t === null) {
            return null;
        }
        $busy = static::busyByRoom($day);
        foreach ($busy[$roomId] ?? [] as $iv) {
            if ($ignoreTtId !== null && $iv['tt_id'] === $ignoreTtId) {
                continue;
            }
            if (static::overlaps($f, $t, $iv['from'], $iv['to'])) {
                return $iv['tt_id'];
            }
        }

        return null;
    }

    /**
     * Assign rooms to every cell of one record. Existing room
     * choices on the record are recomputed from scratch against
     * the rest of the school (the record's own cells never clash
     * with each other in the preload — they join the busy map as
     * they are assigned).
     *
     * Returns ['assigned'=>int, 'unassigned'=>string[],
     *          'over_capacity'=>string[]] with subject names.
     */
    public static function assignRecord(TimeTableRecord $record): array
    {
        $summary = ['assigned' => 0, 'unassigned' => [], 'over_capacity' => []];
        $rooms = Room::orderBy('capacity')->orderBy('id')->get();
        if ($rooms->isEmpty()) {
            return $summary;
        }

        $cells = TimeTable::with(['subject', 'tt_record'])
            ->where('ttr_id', $record->id)->whereNotNull('subject_id')
            ->get()
            ->sortBy(function ($c) {
                return [$c->day_num ?? 0, static::toMinutes($c->timestamp_from) ?? 0];
            })->values();
        if ($cells->isEmpty()) {
            return $summary;
        }

        $busyByDay = [];
        foreach ($cells->pluck('day')->unique() as $day) {
            $busyByDay[$day] = static::busyByRoom($day, (int) $record->id);
        }

        $strengthCache = [];
        $strengthFor = function (TimeTable $cell) use (&$strengthCache) {
            $rec = $cell->tt_record;
            $key = $rec && $rec->section_id ? 's' . $rec->section_id : 'c' . ($rec ? $rec->my_class_id : 0);
            if (!isset($strengthCache[$key])) {
                $q = StudentRecord::where('grad', 0);
                if ($rec && $rec->section_id) {
                    $q->where('section_id', $rec->section_id);
                } elseif ($rec) {
                    $q->where('my_class_id', $rec->my_class_id);
                }
                $strengthCache[$key] = $q->count();
            }

            return $strengthCache[$key];
        };

        foreach ($cells as $cell) {
            $from = static::toMinutes($cell->timestamp_from);
            $to = static::toMinutes($cell->timestamp_to);
            if ($from === null || $to === null) {
                continue;
            }
            $needsLab = $cell->subject && $cell->subject->needs_lab;
            $strength = $strengthFor($cell);
            $busy = &$busyByDay[$cell->day];

            $pick = null;
            $pickOver = false;
            foreach ([true, false] as $fitPass) {
                foreach ($rooms as $room) {
                    if ($needsLab && $room->kind !== 'lab') {
                        continue; // labs are a hard rule, not a preference
                    }
                    $fits = (int) $room->capacity >= $strength;
                    if ($fitPass && !$fits) {
                        continue;
                    }
                    if (!$fitPass && $fits) {
                        continue; // fitting rooms were all tried in pass 1
                    }
                    // Capacity is SOFT for every kind (a small
                    // room beats no room, and the summary reports
                    // it); the lab KIND is the only hard rule.
                    $clash = false;
                    foreach ($busy[(int) $room->id] ?? [] as $iv) {
                        if (static::overlaps($from, $to, $iv['from'], $iv['to'])) {
                            $clash = true;
                            break;
                        }
                    }
                    if ($clash) {
                        continue;
                    }
                    $pick = $room;
                    $pickOver = !$fits;
                    break 2;
                }
            }

            if (!$pick) {
                $summary['unassigned'][] = optional($cell->subject)->name ?: 'A period';
                if ($cell->room_id) {
                    $cell->room_id = null;
                    $cell->save();
                }
                continue;
            }

            if ((int) $cell->room_id !== (int) $pick->id) {
                $cell->room_id = $pick->id;
                $cell->save();
            }
            $busy[(int) $pick->id][] = ['from' => $from, 'to' => $to, 'tt_id' => (int) $cell->id];
            $summary['assigned']++;
            if ($pickOver) {
                $summary['over_capacity'][] = (optional($cell->subject)->name ?: 'A period')
                    . ' (' . $strength . ' students, room seats ' . (int) $pick->capacity . ')';
            }
        }

        $summary['unassigned'] = array_values(array_unique($summary['unassigned']));
        $summary['over_capacity'] = array_values(array_unique($summary['over_capacity']));

        return $summary;
    }
}
