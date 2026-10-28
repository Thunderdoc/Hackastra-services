<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 5 — TT-02 slot-edit sync transaction.
 *
 * Round 4 proved the sync loop rewrites every cell using an edited
 * slot, but the loop was untransactional: a mid-loop failure left a
 * partially synced record (slot row updated, some cells new times,
 * the rest old times). The parent decision for this round wraps the
 * slot-row update + the cell sync loop in one DB transaction, so a
 * failure rolls everything back and answers back + flash_danger
 * instead of a 500.
 *
 * The rollback test is guard-proven: it FAILS on the unmodified
 * controller (the throw propagates as a 500 / partial sync) and
 * PASSES once the transaction lands.
 */
class OwnerTimetableSlotSyncRound5Test extends TestCase
{
    use DatabaseTransactions;

    private function year(): string
    {
        return Qs::getCurrentSession();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'Sync5 Teacher ' . $code,
            'email' => 'sync5-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    private function subject(string $name, string $slug, int $classId, $teacherId): Subject
    {
        return Subject::create([
            'name' => $name,
            'slug' => $slug,
            'my_class_id' => $classId,
            'teacher_id' => $teacherId,
            'periods_per_week' => 6,
        ]);
    }

    private function record(int $classId): TimeTableRecord
    {
        return TimeTableRecord::create([
            'name' => 'Sync5 Record ' . $classId . '-' . uniqid(),
            'my_class_id' => $classId,
            'section_id' => null,
            'exam_id' => null,
            'year' => $this->year(),
        ]);
    }

    private function slot(int $recordId, string $from, string $to): TimeSlot
    {
        return TimeSlot::create([
            'ttr_id' => $recordId,
            'hour_from' => (int) date('g', strtotime($from)),
            'min_from' => date('i', strtotime($from)),
            'meridian_from' => date('A', strtotime($from)),
            'hour_to' => (int) date('g', strtotime($to)),
            'min_to' => date('i', strtotime($to)),
            'meridian_to' => date('A', strtotime($to)),
            'time_from' => $from,
            'time_to' => $to,
            'timestamp_from' => strtotime($from),
            'timestamp_to' => strtotime($to),
            'full' => $from . ' - ' . $to,
        ]);
    }

    private function cell(int $recordId, int $slotId, int $subjectId, string $day, $from, $to): TimeTable
    {
        return TimeTable::create([
            'ttr_id' => $recordId,
            'ts_id' => $slotId,
            'subject_id' => $subjectId,
            'day' => $day,
            'day_num' => date('N', strtotime($from)),
            'timestamp_from' => $from,
            'timestamp_to' => $to,
        ]);
    }

    private function slotPut(int $slotId, int $recordId, string $from, string $to)
    {
        return $this->actingAs($this->admin())->put(route('ts.update', $slotId), [
            'ttr_id' => $recordId,
            'hour_from' => (int) date('g', strtotime($from)),
            'min_from' => date('i', strtotime($from)),
            'meridian_from' => date('A', strtotime($from)),
            'hour_to' => (int) date('g', strtotime($to)),
            'min_to' => date('i', strtotime($to)),
            'meridian_to' => date('A', strtotime($to)),
        ]);
    }

    /** Two cells on the edited slot (different days) + one control cell on another slot. */
    private function fixture(): array
    {
        $teacher = $this->teacher('R5');
        $subject = $this->subject('Sync5 Subject', 'sync5-subject', 1, $teacher->id);
        $rec = $this->record(1);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $other = $this->slot($rec->id, '9:40 AM', '10:20 AM');
        // Cells carry unix timestamps; the date part is what the sync preserves.
        $mon = strtotime('2026-10-26 09:00:00');
        $tue = strtotime('2026-10-27 09:00:00');
        $cellA = $this->cell($rec->id, $slot->id, $subject->id, 'Monday', $mon, $mon + 2400);
        $cellB = $this->cell($rec->id, $slot->id, $subject->id, 'Tuesday', $tue, $tue + 2400);
        $control = $this->cell($rec->id, $other->id, $subject->id, 'Monday', strtotime('2026-10-26 09:40:00'), strtotime('2026-10-26 10:20:00'));

        return compact('rec', 'slot', 'other', 'cellA', 'cellB', 'control');
    }

    /** A mid-loop failure must leave the slot row AND every cell exactly as before. */
    public function test_sync_rolls_back_all_cells_on_mid_loop_failure()
    {
        $f = $this->fixture();

        $saves = 0;
        TimeTable::saving(function () use (&$saves) {
            $saves++;
            if ($saves >= 2) {
                throw new \RuntimeException('sync5 forced mid-loop failure');
            }
        });

        try {
            $res = $this->slotPut($f['slot']->id, $f['rec']->id, '9:00 AM', '10:00 AM');
        } finally {
            // No TimeTable listeners exist in the app, so flushing is safe
            // and the listener cannot leak into other tests.
            TimeTable::flushEventListeners();
        }

        $res->assertRedirect();
        $res->assertSessionHas('flash_danger', 'Time slot update failed — nothing was changed. Please try again.');

        // Slot row rolled back (not the new times).
        $this->assertSame('9:00 AM', TimeSlot::find($f['slot']->id)->time_from);
        $this->assertSame('9:40 AM', TimeSlot::find($f['slot']->id)->time_to);
        // Neither synced cell moved; the first cell's save is rolled back too.
        $this->assertSame(2400, (int) TimeTable::find($f['cellA']->id)->timestamp_to - (int) TimeTable::find($f['cellA']->id)->timestamp_from);
        $this->assertSame(strtotime('2026-10-26 09:00:00'), (int) TimeTable::find($f['cellA']->id)->timestamp_from);
        $this->assertSame(strtotime('2026-10-27 09:00:00'), (int) TimeTable::find($f['cellB']->id)->timestamp_from);
        // Control cell untouched.
        $this->assertSame(strtotime('2026-10-26 09:40:00'), (int) TimeTable::find($f['control']->id)->timestamp_from);
    }

    /** Success path unchanged: slot row + all its cells resync, other slot's cell untouched. */
    public function test_sync_success_still_updates_slot_and_all_cells()
    {
        $f = $this->fixture();

        $res = $this->slotPut($f['slot']->id, $f['rec']->id, '9:00 AM', '10:00 AM');
        $res->assertRedirect(route('ttr.manage', $f['rec']->id));
        $res->assertSessionHas('flash_success');

        $this->assertSame('10:00 AM', TimeSlot::find($f['slot']->id)->time_to);
        $this->assertSame(strtotime('2026-10-26 10:00:00'), (int) TimeTable::find($f['cellA']->id)->timestamp_to);
        $this->assertSame(strtotime('2026-10-27 10:00:00'), (int) TimeTable::find($f['cellB']->id)->timestamp_to);
        $this->assertSame(strtotime('2026-10-26 09:40:00'), (int) TimeTable::find($f['control']->id)->timestamp_from);
    }
}
