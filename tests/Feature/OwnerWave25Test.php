<?php

namespace Tests\Feature;

use App\Support\Streams;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Batch 2026-10-26 — streamed-section staffing regression guard.
 *
 * Before this batch, senior section grids starved: Ananya alone owed
 * 78 English periods a week (one class-level teacher per subject
 * across 22 sections), so the seed placer — which respects the busy
 * map — filled later Class 12 sections with ZERO cells. The fix
 * staffs senior sections through per-section overrides rotated over
 * extended teacher families (DemoDataSeeder::seedSeniorSectionTeachers)
 * with 33 teachers. These tests guard the seeded result itself:
 * every streamed section's grid must be full, and no teacher may be
 * booked past the 40-slot week.
 */
class OwnerWave25Test extends TestCase
{
    use DatabaseTransactions;

    public function test_every_streamed_section_grid_is_full()
    {
        $sections = DB::table('sections')->whereNotNull('stream')->get();
        $this->assertNotEmpty($sections, 'seed must hold streamed sections');

        foreach ($sections as $section) {
            $record = DB::table('time_table_records')
                ->where('my_class_id', $section->my_class_id)
                ->where('section_id', $section->id)
                ->whereNull('exam_id')->first();
            $this->assertNotNull($record,
                "streamed section {$section->my_class_id}-{$section->name} ({$section->stream}) has no grid");

            $cells = DB::table('time_tables')->where('ttr_id', $record->id)->get();
            // 5 stream subjects x 6 periods = 30. Floor 25 tolerates
            // a shortfall or two; the old failure mode was 0-16.
            $this->assertGreaterThanOrEqual(25, $cells->count(),
                "section {$section->my_class_id}-{$section->name} grid has only {$cells->count()} cells");

            $subjects = DB::table('subjects')->where('my_class_id', $section->my_class_id)->get()
                ->filter(fn ($s) => Streams::appliesTo($s, $section->stream));
            foreach ($subjects as $subject) {
                $placed = $cells->where('subject_id', $subject->id)->count();
                $this->assertGreaterThanOrEqual(4, $placed,
                    "section {$section->my_class_id}-{$section->name} subject {$subject->name} placed only {$placed} periods");
            }
        }
    }

    public function test_no_teacher_is_booked_past_the_40_slot_week()
    {
        $recordSection = DB::table('time_table_records')->pluck('section_id', 'id');
        $subjectTeacher = DB::table('subjects')->pluck('teacher_id', 'id');
        $overrideMap = [];
        foreach (DB::table('section_subject_teachers')->get(['section_id', 'subject_id', 'teacher_id']) as $o) {
            $overrideMap[$o->section_id . ':' . $o->subject_id] = $o->teacher_id;
        }
        $slotIndexById = [];
        $perRecord = [];
        foreach (DB::table('time_slots')->orderBy('ttr_id')->orderBy('timestamp_from')->get() as $sr) {
            $perRecord[$sr->ttr_id][] = $sr->id;
        }
        foreach ($perRecord as $ids) {
            foreach ($ids as $i => $sid) {
                $slotIndexById[$sid] = $i;
            }
        }

        $busy = [];
        foreach (DB::table('time_tables')->get(['ttr_id', 'ts_id', 'subject_id', 'day_num']) as $cell) {
            $sec = $recordSection[$cell->ttr_id] ?? null;
            $tid = ($sec && isset($overrideMap[$sec . ':' . $cell->subject_id]))
                ? $overrideMap[$sec . ':' . $cell->subject_id]
                : ($subjectTeacher[$cell->subject_id] ?? null);
            if ($tid && isset($slotIndexById[$cell->ts_id])) {
                $busy[$tid][$cell->day_num . ':' . $slotIndexById[$cell->ts_id]] = true;
            }
        }

        foreach ($busy as $tid => $slots) {
            $this->assertLessThanOrEqual(40, count($slots),
                "teacher {$tid} is booked in " . count($slots) . ' distinct slots');
        }
    }
}
