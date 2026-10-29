<?php

namespace App\Support;

use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Models\TimetableSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * T4 — capture and restore timetable grids.
 *
 * Doctrine: replacing a grid or approving one must never be a
 * point of no return. capture() freezes the record's slots and
 * cells (subjects, days, times, rooms) into one JSON payload;
 * restore() rebuilds them as a NEW draft record — the current
 * grid is never overwritten, attendance and substitutions keep
 * pointing at the record they belong to, and the restored grid
 * goes through the normal review and approval flow again.
 */
class GridSnapshot
{
    public static function capture(TimeTableRecord $record, string $label, $userId = null): ?TimetableSnapshot
    {
        $slots = TimeSlot::where('ttr_id', $record->id)->get();
        $cells = TimeTable::where('ttr_id', $record->id)->get();
        if ($cells->isEmpty() && $slots->isEmpty()) {
            return null;
        }
        $payload = [
            'record' => [
                'name' => $record->name,
                'my_class_id' => $record->my_class_id,
                'section_id' => $record->section_id,
                'year' => $record->year,
            ],
            'slots' => $slots->map(function ($s) {
                return [
                    'id' => $s->id,
                    'hour_from' => $s->hour_from,
                    'min_from' => $s->min_from,
                    'meridian_from' => $s->meridian_from,
                    'hour_to' => $s->hour_to,
                    'min_to' => $s->min_to,
                    'meridian_to' => $s->meridian_to,
                    'time_from' => $s->time_from,
                    'time_to' => $s->time_to,
                    'timestamp_from' => $s->timestamp_from,
                    'timestamp_to' => $s->timestamp_to,
                    'full' => $s->full,
                ];
            })->all(),
            'cells' => $cells->map(function ($c) {
                return [
                    'ts_id' => $c->ts_id,
                    'subject_id' => $c->subject_id,
                    'day' => $c->day,
                    'day_num' => $c->day_num,
                    'timestamp_from' => $c->timestamp_from,
                    'timestamp_to' => $c->timestamp_to,
                    'room_id' => $c->room_id,
                ];
            })->all(),
        ];

        return TimetableSnapshot::create([
            'ttr_id' => $record->id,
            'label' => $label,
            'payload' => $payload,
            'created_by' => $userId,
        ]);
    }

    /**
     * Rebuild the snapshot as a new DRAFT record and return it
     * (null when the payload is unusable). Slots are recreated
     * with their original timestamps so cell times line up
     * exactly; cells point at the new slot ids.
     */
    public static function restore(TimetableSnapshot $snapshot, $userId = null): ?TimeTableRecord
    {
        $payload = $snapshot->payload;
        if (empty($payload['record']['my_class_id'])) {
            return null;
        }

        return DB::transaction(function () use ($payload, $snapshot) {
            $record = TimeTableRecord::create([
                'name' => ($payload['record']['name'] ?? 'Timetable') . ' (restored)',
                'my_class_id' => $payload['record']['my_class_id'],
                'section_id' => $payload['record']['section_id'] ?? null,
                'year' => $payload['record']['year'] ?? null,
                'approval_status' => 'draft',
            ]);
            $slotMap = [];
            foreach ($payload['slots'] ?? [] as $s) {
                $slot = TimeSlot::create([
                    'ttr_id' => $record->id,
                    'hour_from' => $s['hour_from'] ?? (int) date('g', (int) $s['timestamp_from']),
                    'min_from' => $s['min_from'] ?? date('i', (int) $s['timestamp_from']),
                    'meridian_from' => $s['meridian_from'] ?? date('A', (int) $s['timestamp_from']),
                    'hour_to' => $s['hour_to'] ?? (int) date('g', (int) $s['timestamp_to']),
                    'min_to' => $s['min_to'] ?? date('i', (int) $s['timestamp_to']),
                    'meridian_to' => $s['meridian_to'] ?? date('A', (int) $s['timestamp_to']),
                    'time_from' => $s['time_from'] ?? date('g:i A', (int) $s['timestamp_from']),
                    'time_to' => $s['time_to'] ?? date('g:i A', (int) $s['timestamp_to']),
                    'timestamp_from' => $s['timestamp_from'],
                    'timestamp_to' => $s['timestamp_to'],
                    'full' => $s['full'] ?? (date('g:i A', (int) $s['timestamp_from']) . ' - ' . date('g:i A', (int) $s['timestamp_to'])),
                ]);
                $slotMap[$s['id']] = $slot->id;
            }
            foreach ($payload['cells'] ?? [] as $c) {
                TimeTable::create([
                    'ttr_id' => $record->id,
                    'ts_id' => $slotMap[$c['ts_id']] ?? null,
                    'subject_id' => $c['subject_id'],
                    'day' => $c['day'],
                    'day_num' => $c['day_num'] ?? null,
                    'timestamp_from' => $c['timestamp_from'],
                    'timestamp_to' => $c['timestamp_to'],
                    'room_id' => $c['room_id'] ?? null,
                ]);
            }
            Audit::log('timetable.snapshot_restored', null,
                'Snapshot ' . $snapshot->id . ' ("' . $snapshot->label . '") restored as record ' . $record->id);

            return $record;
        });
    }
}
