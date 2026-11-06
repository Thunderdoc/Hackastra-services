<?php

namespace Database\Seeders;

use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\DemoToday;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Database\Seeder;

/**
 * Demo cover story (2026-11-01): the My Extra Classes page must not be
 * a void in the demo. One colleague is on approved leave in the demo
 * week (DemoToday-anchored, like every other content date) and Ananya —
 * the demo teacher — is the confirmed substitute for three of his
 * periods. Cells are chosen through the same effective-teacher rule
 * the substitution system itself uses, and only where Ananya is
 * genuinely free. Idempotent: a second run adds nothing.
 */
class DemoCoverSeeder extends Seeder
{
    public function run()
    {
        $ananya = User::where('email', 'teacher@hackstra.app')->first();
        $admin = User::where('email', 'admin@hackstra.app')->first();
        if (!$ananya || !$admin) {
            return;
        }
        if (Substitution::where('substitute_teacher_id', $ananya->id)->exists()) {
            return;
        }

        // Ananya's own week is Mon 8 / Tue 8 / Wed 8 / Thu 8 / Fri 5 —
        // every Thursday slot is one she teaches herself, so the only
        // day a colleague's period can ever land on her free time is
        // Friday. The story is a one-day Friday leave, three covers.
        $days = [
            'Friday' => DemoToday::today()->addDay()->toDateString(),     // 30 Oct 2026
        ];
        $want = ['Friday' => 3];

        $cells = TimeTable::with(['tt_record', 'subject'])
            ->whereIn('day', array_keys($days))
            ->orderBy('timestamp_from')
            ->get();

        $effective = [];
        foreach ($cells as $cell) {
            $sectionId = optional($cell->tt_record)->section_id;
            $effective[$cell->id] = SectionTeacher::teacherIdFor($sectionId, $cell->subject);
        }

        $ananyaBusy = [];
        foreach ($cells as $cell) {
            if ($effective[$cell->id] === (int) $ananya->id) {
                $ananyaBusy[$cell->day . '|' . $cell->timestamp_from] = true;
            }
        }

        // Candidate colleagues: effective teachers (not Ananya) with
        // coverable cells on both days, lowest user id first so the
        // chosen colleague is stable across runs.
        $byTeacherDay = [];
        foreach ($cells as $cell) {
            $tid = $effective[$cell->id];
            if (!$tid || $tid === (int) $ananya->id) {
                continue;
            }
            if (isset($ananyaBusy[$cell->day . '|' . $cell->timestamp_from])) {
                continue;
            }
            $byTeacherDay[$tid][$cell->day][] = $cell;
        }
        ksort($byTeacherDay);

        $chosen = null;
        $picks = [];
        foreach ($byTeacherDay as $tid => $perDay) {
            $ok = true;
            $picked = [];
            $usedSlots = $ananyaBusy;
            foreach ($want as $day => $count) {
                $picked[$day] = [];
                foreach ($perDay[$day] ?? [] as $cell) {
                    if (count($picked[$day]) >= $count) {
                        break;
                    }
                    $key = $day . '|' . $cell->timestamp_from;
                    if (isset($usedSlots[$key])) {
                        continue;
                    }
                    $picked[$day][] = $cell;
                    $usedSlots[$key] = true;
                }
                if (count($picked[$day]) < $count) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $chosen = $tid;
                $picks = $picked;
                break;
            }
        }
        if (!$chosen) {
            return;
        }

        $leave = LeaveRequest::create([
            'user_id' => $chosen,
            'start_date' => $days['Friday'],
            'end_date' => $days['Friday'],
            'reason' => 'Family function in hometown',
            'leave_type' => 'casual',
            'status' => 'approved',
            'decided_by' => $admin->id,
            'decided_at' => DemoToday::today()->subDays(3),
            'decision_note' => 'Approved — cover arranged by the system.',
        ]);

        foreach ($picks as $day => $dayCells) {
            foreach ($dayCells as $cell) {
                Substitution::create([
                    'time_table_id' => $cell->id,
                    'date' => $days[$day],
                    'absent_teacher_id' => $chosen,
                    'substitute_teacher_id' => $ananya->id,
                    'created_by' => $admin->id,
                    'reason' => 'Cover for approved leave #' . $leave->id,
                    'status' => 'confirmed',
                ]);
            }
        }
    }
}
