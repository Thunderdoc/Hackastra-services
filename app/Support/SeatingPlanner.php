<?php

namespace App\Support;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\Room;
use App\Models\SeatingPlan;
use App\Models\SeatingSeat;
use App\Models\StudentRecord;
use Illuminate\Support\Facades\DB;

/**
 * F3 — Exam Seating planner (design §§3–7).
 *
 * Deterministic: same exam_dates + same rosters + same rooms + same
 * options produce a byte-identical plan on every run. Every query
 * feeding the algorithm carries an explicit total order; roster
 * order is adm_no NATURAL order (strnatcmp — numeric-only admission
 * numbers sort 999 < 1001), tie-break StudentRecord.id, blank adm
 * numbers last by id (§1.4).
 *
 * Capacity is HARD here — the one deliberate inversion of
 * RoomAssigner doctrine (§5.3): a physical seat cannot overflow.
 * On shortfall the planner returns a named, counted refusal and
 * writes ZERO rows. Rooms are otherwise considered in the
 * RoomAssigner (capacity, id) order: a sitting that fits in one
 * hall takes the smallest hall that fits it (fewest halls, big
 * halls preserved); a sitting that fits nowhere whole is split
 * filling halls largest-first so the split uses as few halls as
 * possible. Mixed mode (§5.4, opt-in) interleaves sittings
 * round-robin into one stream filled largest-first.
 *
 * A slot = all exam_dates of one exam sharing (exam_date,
 * start_time normalised to HH:MM via substr 0,5) — the
 * ExamDateController::manage / Invigilation definition (§1.2).
 * The roster per sitting is the WHOLE class (no section filter —
 * sittings are class-level), grad = 0, filtered per student by
 * Streams::appliesTo(subject, section stream). NO fee gate
 * anywhere: a seating plan is an office document (§1.4).
 *
 * ExamDuty integration is READ-ONLY (§1.3/§6.3): duties are never
 * written or deleted here. Hall-sheet invigilator label (§6.3): a
 * hall names the invigilator(s) of the sittings that actually have
 * seats in that hall — distinct teacher names in sitting order;
 * a sitting split across halls names its invigilator on each of
 * its halls' sheets; no duty assigned prints '—', never a guess.
 */
class SeatingPlanner
{
    /** Normalise a stored start_time to the HH:MM slot component. */
    public static function timeKey($startTime): string
    {
        return $startTime ? substr((string) $startTime, 0, 5) : '';
    }

    /** All slots of an exam, in (date, time) order, with their sittings. */
    public static function slots($examId): array
    {
        $sittings = ExamDate::with(['my_class', 'subject'])
            ->where('exam_id', $examId)
            ->orderBy('exam_date')->orderBy('start_time')->orderBy('my_class_id')->orderBy('subject_id')->orderBy('id')
            ->get();

        $slots = [];
        foreach ($sittings as $sitting) {
            $key = $sitting->exam_date . '|' . self::timeKey($sitting->start_time);
            if (!isset($slots[$key])) {
                $slots[$key] = [
                    'key' => $key,
                    'exam_date' => $sitting->exam_date,
                    'start_time' => $sitting->start_time,
                    'time_key' => self::timeKey($sitting->start_time),
                    'sittings' => [],
                ];
            }
            $slots[$key]['sittings'][] = $sitting;
        }

        return array_values($slots);
    }

    /** The slot (with sittings) for one exam + date + HH:MM key, or null. */
    public static function slotFor($examId, $examDate, $timeKey): ?array
    {
        foreach (self::slots($examId) as $slot) {
            if ($slot['exam_date'] === $examDate && $slot['time_key'] === (string) $timeKey) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Roster of one sitting in pinned seat order (§1.4). Each entry:
     * record_id, user name, adm_no, class/section ids + names.
     */
    public static function rosterForSitting(ExamDate $sitting): array
    {
        $records = StudentRecord::with(['user', 'section', 'my_class'])
            ->where('my_class_id', $sitting->my_class_id)
            ->where('grad', 0)
            ->get();

        $subject = $sitting->subject;
        $rows = [];
        foreach ($records as $record) {
            $stream = $record->section ? ($record->section->stream ?? null) : null;
            if (!Streams::appliesTo($subject, $stream)) {
                continue; // e.g. a PCM student never sits a PCB-only paper
            }
            $rows[] = [
                'student_record_id' => (int) $record->id,
                'student_name' => $record->user ? $record->user->name : '',
                'adm_no' => $record->adm_no !== null && trim((string) $record->adm_no) !== '' ? (string) $record->adm_no : null,
                'my_class_id' => (int) $record->my_class_id,
                'section_id' => $record->section_id ? (int) $record->section_id : null,
                'class_name' => $record->my_class ? $record->my_class->name : '',
                'section_name' => $record->section ? $record->section->name : null,
            ];
        }

        usort($rows, function ($a, $b) {
            $aBlank = $a['adm_no'] === null;
            $bBlank = $b['adm_no'] === null;
            if ($aBlank !== $bBlank) {
                return $aBlank ? 1 : -1; // blank adm numbers sort last
            }
            if (!$aBlank) {
                $cmp = strnatcmp($a['adm_no'], $b['adm_no']);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return $a['student_record_id'] <=> $b['student_record_id'];
        });

        return $rows;
    }

    /** Rosters for every sitting of a slot, keyed by exam_date_id, in sitting order. */
    public static function rostersForSlot(array $slot): array
    {
        $rosters = [];
        foreach ($slot['sittings'] as $sitting) {
            $rosters[(int) $sitting->id] = self::rosterForSitting($sitting);
        }

        return $rosters;
    }

    /**
     * §7.2 staleness fingerprint: sha256 over the canonical inputs —
     * slot identity + mode, every sitting (id, class, subject +
     * stream tags), every rostered student (record id, adm no, name,
     * class, section) in seat order, and every hall used (id, name,
     * capacity) in (capacity, id) order. Any input change — a date
     * moved, a student added, a hall resized — changes the hash.
     */
    public static function sourceHash($examId, $examDate, $timeKey, $mode, array $slot, array $rosters, array $rooms): string
    {
        $parts = ['v1', (string) $examId, (string) $examDate, (string) $timeKey, (string) $mode];
        foreach ($slot['sittings'] as $sitting) {
            $parts[] = 'sitting:' . $sitting->id . ':' . $sitting->my_class_id . ':' . $sitting->subject_id
                . ':' . implode(',', Streams::tags($sitting->subject));
            foreach ($rosters[(int) $sitting->id] ?? [] as $row) {
                $parts[] = 'seat:' . $row['student_record_id'] . ':' . ($row['adm_no'] ?? '')
                    . ':' . $row['student_name'] . ':' . $row['class_name'] . ':' . ($row['section_name'] ?? '');
            }
        }
        $roomRows = collect($rooms)->sortBy(function ($room) {
            return [(int) $room->capacity, (int) $room->id];
        })->values();
        foreach ($roomRows as $room) {
            $parts[] = 'room:' . $room->id . ':' . $room->name . ':' . (int) $room->capacity;
        }

        return hash('sha256', implode('|', $parts));
    }

    /** Recompute the fingerprint for a stored plan from LIVE inputs. */
    public static function currentHashForPlan(SeatingPlan $plan): ?string
    {
        $slot = self::slotFor($plan->exam_id, $plan->exam_date, self::timeKey($plan->start_time));
        if (!$slot) {
            return null; // the slot itself is gone — stale by definition
        }
        $rosters = self::rostersForSlot($slot);
        $roomIds = SeatingSeat::where('plan_id', $plan->id)->pluck('room_id')->unique()->all();
        $rooms = Room::whereIn('id', $roomIds)->get()->all();

        return self::sourceHash($plan->exam_id, $plan->exam_date, self::timeKey($plan->start_time), $plan->mode, $slot, $rosters, $rooms);
    }

    public static function isStale(SeatingPlan $plan): bool
    {
        $current = self::currentHashForPlan($plan);

        return $current === null || !hash_equals($plan->source_hash, $current);
    }

    /**
     * Pure allocation: returns seat rows (without plan_id) or a
     * refusal. $groups = list of ['exam_date_id'=>, 'subject_name'=>,
     * 'students'=> roster rows] in sitting order; $rooms = Room
     * models. Nothing is written here.
     */
    public static function allocate(array $groups, array $rooms, string $mode): array
    {
        $total = 0;
        foreach ($groups as $group) {
            $total += count($group['students']);
        }
        $capacity = 0;
        foreach ($rooms as $room) {
            $capacity += (int) $room->capacity;
        }
        if ($total > $capacity) {
            return ['ok' => false, 'error' => 'capacity',
                'message' => "Not enough seats: {$total} students need seats but the selected halls provide {$capacity} (short by " . ($total - $capacity) . '). Add another hall or choose halls with more seats — no plan was written.'];
        }
        if ($total === 0) {
            return ['ok' => false, 'error' => 'empty',
                'message' => 'No students sit in this slot (rosters are empty after stream filtering). No plan was written.'];
        }

        // Remaining seats per room, keyed by room id.
        $remaining = [];
        foreach ($rooms as $room) {
            $remaining[(int) $room->id] = (int) $room->capacity;
        }
        $roomById = [];
        foreach ($rooms as $room) {
            $roomById[(int) $room->id] = $room;
        }
        // (capacity, id) ascending — the RoomAssigner consideration order.
        $asc = array_values($rooms);
        usort($asc, function ($a, $b) {
            return [(int) $a->capacity, (int) $a->id] <=> [(int) $b->capacity, (int) $b->id];
        });
        // Largest-first fill order for splits / the mixed stream.
        $desc = array_reverse($asc);
        usort($desc, function ($a, $b) {
            $cmp = (int) $b->capacity <=> (int) $a->capacity;

            return $cmp !== 0 ? $cmp : ((int) $a->id <=> (int) $b->id);
        });

        $seatNo = []; // per-room running seat counter
        $rows = [];
        $place = function ($group, $student, $room) use (&$rows, &$remaining, &$seatNo) {
            $roomId = (int) $room->id;
            $remaining[$roomId]--;
            $seatNo[$roomId] = ($seatNo[$roomId] ?? 0) + 1;
            $rows[] = [
                'room_id' => $roomId,
                'exam_date_id' => (int) $group['exam_date_id'],
                'student_record_id' => $student['student_record_id'],
                'my_class_id' => $student['my_class_id'],
                'section_id' => $student['section_id'],
                'student_name' => $student['student_name'],
                'adm_no' => $student['adm_no'],
                'class_name' => $student['class_name'],
                'section_name' => $student['section_name'],
                'subject_name' => $group['subject_name'],
                'room_name' => $room->name,
                'seat_no' => $seatNo[$roomId],
            ];
        };

        if ($mode === SeatingPlan::MODE_MIXED) {
            // Round-robin interleave across sittings, then fill
            // halls largest-first, each fully before the next.
            $stream = [];
            $max = 0;
            foreach ($groups as $group) {
                $max = max($max, count($group['students']));
            }
            for ($i = 0; $i < $max; $i++) {
                foreach ($groups as $group) {
                    if (isset($group['students'][$i])) {
                        $stream[] = [$group, $group['students'][$i]];
                    }
                }
            }
            foreach ($desc as $room) {
                foreach ($stream as $idx => $pair) {
                    if ($remaining[(int) $room->id] <= 0) {
                        break;
                    }
                    $place($pair[0], $pair[1], $room);
                    unset($stream[$idx]);
                }
                if (empty($stream)) {
                    break;
                }
            }
        } else {
            foreach ($groups as $group) {
                $students = $group['students'];
                $count = count($students);
                if ($count === 0) {
                    continue;
                }
                // Best fit: smallest hall (in (capacity, id)
                // consideration order) whose remaining seats hold
                // the whole sitting — one hall per sitting.
                $fit = null;
                foreach ($asc as $room) {
                    if ($remaining[(int) $room->id] >= $count) {
                        $fit = $room;
                        break;
                    }
                }
                if ($fit) {
                    foreach ($students as $student) {
                        $place($group, $student, $fit);
                    }
                    continue;
                }
                // Split: fill halls largest-first, each fully
                // before the next — as few halls as possible.
                foreach ($desc as $room) {
                    foreach ($students as $idx => $student) {
                        if ($remaining[(int) $room->id] <= 0) {
                            break;
                        }
                        $place($group, $student, $room);
                        unset($students[$idx]);
                    }
                    if (empty($students)) {
                        break;
                    }
                }
            }
        }

        return ['ok' => true, 'rows' => $rows, 'total_students' => $total];
    }

    /**
     * The existing plan for one slot (exam + date + time key),
     * or null when the slot has never been generated.
     */
    public static function planForSlot($examId, $examDate, $timeKey): ?SeatingPlan
    {
        $key = $examDate . '|' . ($timeKey ?: '');
        foreach (SeatingPlan::where('exam_id', $examId)->where('exam_date', $examDate)->get() as $plan) {
            if ($plan->slotKey() === $key) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * ONE-TIME GENERATION lock (owner standing rule 2026-11-08):
     * a seating plan is generated once, reviewed, sent to the
     * Principal and published — after it is SUBMITTED or
     * PUBLISHED it can no longer be regenerated over. The only
     * way back is the Principal's return
     * (ExamSeatingController::returnPlan), which is audited.
     * Returns the refusal message for a locked plan, or null
     * while the slot's plan is still a draft (or not generated).
     * Wording mirrors ExamDateController::sheetLockMessage so
     * the refusal reads identically on both surfaces.
     */
    public static function planLockMessage($examId, $examDate, $timeKey): ?string
    {
        $plan = self::planForSlot($examId, $examDate, $timeKey);
        if (!$plan) {
            return null;
        }
        if ($plan->status === SeatingPlan::SUBMITTED) {
            return 'This seating plan has been sent to the Principal for approval — it can\'t be changed or regenerated now. The Principal can return it for correction from Exam Seating Approvals.';
        }
        if ($plan->status === SeatingPlan::PUBLISHED) {
            return 'This seating plan has been published by the Principal — it can\'t be changed or regenerated. Ask the Principal to return it for correction from Exam Seating Approvals.';
        }

        return null;
    }

    /**
     * Generate (or regenerate) the plan for one slot. Returns
     * [bool $ok, string $message, ?SeatingPlan $plan]. Blocking
     * states write ZERO rows: no rooms at all, no usable halls,
     * capacity shortfall, empty roster.
     * Regeneration (§7.2): replaces the seat snapshot in one
     * transaction and returns the plan to DRAFT, clearing the
     * submitted/approved stamps — a changed plan must be
     * re-approved, exactly like an edited date sheet.
     * ONE-TIME GENERATION (owner standing rule 2026-11-08): that
     * regeneration is a DRAFT-stage freedom only — a SUBMITTED
     * or PUBLISHED plan refuses here (planLockMessage) and its
     * seats are never touched; the Principal's return is the
     * only way one opens again.
     */
    public static function generate($examId, $examDate, $timeKey, array $roomIds, $mode, $userId): array
    {
        $mode = $mode === SeatingPlan::MODE_MIXED ? SeatingPlan::MODE_MIXED : SeatingPlan::MODE_SEPARATE;

        if (Room::count() === 0) {
            return [false, 'No rooms are defined yet. Add the exam halls on the Rooms page first — a seating plan cannot be generated into nowhere.', null];
        }
        $slot = self::slotFor($examId, $examDate, $timeKey);
        if (!$slot) {
            return [false, 'That sitting slot is not on the date sheet for this exam any more. Pick a slot from the list.', null];
        }
        if ($lock = self::planLockMessage($examId, $examDate, $timeKey)) {
            return [false, $lock, null];
        }

        $rooms = Room::whereIn('id', $roomIds)->where('capacity', '>', 0)->get()->all();
        if (empty($rooms)) {
            return [false, 'Choose at least one hall with seats. Rooms with 0 capacity cannot seat anyone.', null];
        }

        $rosters = self::rostersForSlot($slot);
        $groups = [];
        foreach ($slot['sittings'] as $sitting) {
            $groups[] = [
                'exam_date_id' => (int) $sitting->id,
                'subject_name' => $sitting->subject ? $sitting->subject->name : '',
                'students' => $rosters[(int) $sitting->id],
            ];
        }

        $result = self::allocate($groups, $rooms, $mode);
        if (!$result['ok']) {
            return [false, $result['message'], null];
        }

        $usedRoomIds = array_values(array_unique(array_column($result['rows'], 'room_id')));
        $usedRooms = array_values(array_filter($rooms, function ($room) use ($usedRoomIds) {
            return in_array((int) $room->id, $usedRoomIds, true);
        }));
        $totalSeats = 0;
        foreach ($usedRooms as $room) {
            $totalSeats += (int) $room->capacity;
        }
        $hash = self::sourceHash($examId, $examDate, $timeKey, $mode, $slot, $rosters, $usedRooms);
        // Stored start_time: the slot's first sitting value, so the
        // (exam, date, start_time) unique key matches the slot.
        $storedTime = $slot['sittings'][0]->start_time;

        $plan = DB::transaction(function () use ($examId, $examDate, $storedTime, $mode, $result, $totalSeats, $hash, $userId) {
            $plan = SeatingPlan::where('exam_id', $examId)
                ->where('exam_date', $examDate)
                ->where(function ($q) use ($storedTime) {
                    if ($storedTime === null) {
                        $q->whereNull('start_time');
                    } else {
                        $q->where('start_time', $storedTime);
                    }
                })->first();
            if (!$plan) {
                $plan = new SeatingPlan(['exam_id' => $examId, 'exam_date' => $examDate, 'start_time' => $storedTime]);
            }
            $plan->status = SeatingPlan::DRAFT;
            $plan->mode = $mode;
            $plan->total_students = $result['total_students'];
            $plan->total_seats = $totalSeats;
            $plan->source_hash = $hash;
            $plan->generated_by = $userId;
            $plan->generated_at = now();
            $plan->submitted_by = null;
            $plan->submitted_at = null;
            $plan->approved_by = null;
            $plan->approved_at = null;
            $plan->save();

            SeatingSeat::where('plan_id', $plan->id)->delete();
            $now = now();
            foreach (array_chunk($result['rows'], 200) as $chunk) {
                $insert = [];
                foreach ($chunk as $row) {
                    $insert[] = $row + ['plan_id' => $plan->id, 'created_at' => $now, 'updated_at' => $now];
                }
                SeatingSeat::insert($insert);
            }

            return $plan;
        });

        return [true, 'Seating plan generated: ' . $result['total_students'] . ' students in ' . count($usedRooms) . ' hall(s).', $plan];
    }

    /**
     * Halls of a plan: one entry per room used, seats in seat_no
     * order, with the §6.3 invigilator label (READ-ONLY on duties).
     */
    public static function hallsForPlan(SeatingPlan $plan): array
    {
        $seats = SeatingSeat::where('plan_id', $plan->id)
            ->orderBy('room_id')->orderBy('seat_no')->get();
        $rooms = Room::whereIn('id', $seats->pluck('room_id')->unique()->all())->get()->keyBy('id');

        $halls = [];
        foreach ($seats->groupBy('room_id') as $roomId => $roomSeats) {
            $sittingIds = $roomSeats->pluck('exam_date_id')->unique()->all();
            $duties = ExamDuty::with('teacher')
                ->whereIn('exam_date_id', $sittingIds)
                ->get()
                ->sortBy('exam_date_id')->values();
            $names = [];
            foreach ($duties as $duty) {
                $name = $duty->teacher ? $duty->teacher->name : null;
                if ($name && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            $room = $rooms->get($roomId);
            $halls[] = [
                'room_id' => (int) $roomId,
                'room_name' => $roomSeats->first()->room_name,
                'capacity' => $room ? (int) $room->capacity : $roomSeats->count(),
                'seats' => $roomSeats->values(),
                'invigilators' => $names,
                'invigilator_label' => empty($names) ? '—' : implode(', ', $names),
            ];
        }
        // Deterministic hall order: (room_name, room_id).
        usort($halls, function ($a, $b) {
            $cmp = strcmp($a['room_name'], $b['room_name']);

            return $cmp !== 0 ? $cmp : ($a['room_id'] <=> $b['room_id']);
        });

        return $halls;
    }
}
