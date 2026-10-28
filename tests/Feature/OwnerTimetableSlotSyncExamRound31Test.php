<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 31 — slot-sync (ts.update) × exam grids, the
 * interaction round 28 never probed: exam-date cells carry day
 * NULL and an exam_date, but Manage store composes their
 * timestamps from exam_date + SLOT times and links them to the
 * slot via ts_id — exactly like day cells. So the sync loop in
 * TimeTableController::update_time_slot
 * (TimeTable::where('ts_id', $ts_id)) DOES reach exam cells on
 * the edited slot.
 *
 * BRIEF-PREMISE CORRECTION, stated openly: the brief expected
 * exam cells to stay byte-identical under a slot edit. The
 * evidence says otherwise — and the evidence is the coherent
 * shape, not a defect. A cell's time IS its slot's time (Manage
 * store composed it from the slot in the first place), so when
 * the slot's times change, the exam sitting follows the slot
 * exactly like a day period does: exam_date preserved, subject
 * preserved, nothing deleted, no 500. Freezing exam cells while
 * day cells on the SAME slot follow would be the incoherent
 * shape, and it would contradict the round-5/28 sync contract
 * ("recompose each cell's timestamps from its own date part +
 * the slot's new times", no day/exam distinction in the loop).
 * What this round locks instead:
 *  (a) same-slot exam cells re-time coherently (date preserved);
 *  (b) other-slot exam cells stay byte-identical;
 *  (c) a record holding ONLY exam cells syncs the same way;
 *  (d) the periods-placed delete guard COUNTS exam cells (a slot
 *      carrying only exam sittings is refused, like any placed
 *      slot) while a truly empty slot deletes;
 *  (e) the validator stays clean through the sync.
 *
 * Cells are built through the REAL tt.store path (not direct-DB
 * rows), except the record/slot rows themselves. All fixtures
 * fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableSlotSyncExamRound31Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R31 Teacher ' . $tag,
            'email' => 'r31-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R31' . $tag . uniqid(),
        ]);
    }

    protected function subject(int $classId, int $teacherId, string $name, string $tag): Subject
    {
        return Subject::create([
            'name' => $name,
            'slug' => 'r31-' . strtolower($tag) . '-' . uniqid(),
            'my_class_id' => $classId,
            'teacher_id' => $teacherId,
            'periods_per_week' => 6,
        ]);
    }

    /** Fresh class + teacher + scoped-own exam + exam record. */
    protected function examRecord(string $prefix): array
    {
        $class = MyClass::create(['name' => 'R31 ' . $prefix . ' ' . uniqid()]);
        $teacher = $this->teacher($prefix);
        $exam = Exam::create([
            'name' => 'R31 Exam ' . $prefix . ' ' . uniqid(),
            'term' => 1,
            'year' => '2026-2027',
            'my_class_id' => $class->id,
        ]);
        $record = TimeTableRecord::create([
            'name' => 'R31 ' . $prefix . ' ' . uniqid(),
            'my_class_id' => $class->id,
            'section_id' => null,
            'exam_id' => $exam->id,
            'year' => '2026-2027',
        ]);

        return [$class, $teacher, $record];
    }

    protected function slot(int $recordId, string $from, string $to): TimeSlot
    {
        [$fh, $fm, $fmer] = $this->splitTime($from);
        [$th, $tm, $tmer] = $this->splitTime($to);

        return TimeSlot::create([
            'ttr_id' => $recordId,
            'hour_from' => $fh, 'min_from' => $fm, 'meridian_from' => $fmer,
            'hour_to' => $th, 'min_to' => $tm, 'meridian_to' => $tmer,
            'time_from' => $from, 'time_to' => $to,
            'timestamp_from' => strtotime($from), 'timestamp_to' => strtotime($to),
            'full' => $from . ' - ' . $to,
        ]);
    }

    protected function splitTime(string $t): array
    {
        [$hm, $mer] = explode(' ', $t);
        [$h, $m] = explode(':', $hm);

        return [(int) $h, $m, $mer];
    }

    /** Real Manage cell store (tt.store, JSON ok). */
    protected function storeCell(int $recordId, int $slotId, array $datePart): TimeTable
    {
        $payload = array_merge([
            'ttr_id' => $recordId,
            'ts_id' => $slotId,
        ], $datePart);
        // subject_id is merged by the caller via $datePart.
        $this->actingAs($this->admin())
            ->post(route('tt.store'), $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        return TimeTable::where('ttr_id', $recordId)->orderBy('id', 'desc')->firstOrFail();
    }

    /** ts.update payload: keep 9:00 AM start, move the end time. */
    protected function slotPut(int $slotId, int $recordId)
    {
        return $this->actingAs($this->admin())->put(route('ts.update', $slotId), [
            'ttr_id' => $recordId,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '30', 'meridian_to' => 'AM',
        ]);
    }

    protected function cellTuple(TimeTable $cell): array
    {
        return [$cell->id, $cell->ts_id, $cell->exam_date, $cell->day, $cell->timestamp_from, $cell->timestamp_to, $cell->subject_id];
    }

    public function test_mixed_record_same_slot_exam_cell_retimes_coherently_other_slot_cell_untouched()
    {
        [$class, $teacher, $record] = $this->examRecord('MIXED');
        $daySubj = $this->subject($class->id, $teacher->id, 'R31 Day Subject', 'MDAY');
        $examSubj = $this->subject($class->id, $teacher->id, 'R31 Exam Subject', 'MEXAM');
        $otherSubj = $this->subject($class->id, $teacher->id, 'R31 Other Subject', 'MOTHER');
        $s1 = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $s2 = $this->slot($record->id, '1:00 PM', '1:40 PM');

        // Real Manage path: day cell on S1, exam cell on S1, exam cell on S2.
        $dayCell = $this->storeCell($record->id, $s1->id, ['day' => 'Monday', 'subject_id' => $daySubj->id]);
        $examCell = $this->storeCell($record->id, $s1->id, ['exam_date' => '2026-11-04', 'subject_id' => $examSubj->id]);
        $otherCell = $this->storeCell($record->id, $s2->id, ['exam_date' => '2026-11-05', 'subject_id' => $otherSubj->id]);
        $this->assertSame(3, TimeTable::where('ttr_id', $record->id)->count());
        $this->assertNull($examCell->day, 'exam cell must carry day NULL');
        $this->assertSame('2026-11-04', $examCell->exam_date);

        $beforeDay = $this->cellTuple($dayCell);
        $beforeOther = $this->cellTuple($otherCell);

        $this->slotPut($s1->id, $record->id)
            ->assertRedirect(route('ttr.manage', $record->id))
            ->assertSessionHas('flash_success', 'Record Updated Successfully');

        // Day cell: date part preserved, times follow the slot.
        $dayCell->refresh();
        $this->assertSame(date('Y-m-d', $beforeDay[4]), date('Y-m-d', $dayCell->timestamp_from));
        $this->assertSame(strtotime(date('Y-m-d', $beforeDay[4]) . ' 09:00:00'), (int) $dayCell->timestamp_from);
        $this->assertSame(strtotime(date('Y-m-d', $beforeDay[4]) . ' 09:30:00'), (int) $dayCell->timestamp_to);

        // Same-slot exam cell: exam_date + subject preserved, times follow the slot.
        // This is the coherent sync shape, NOT a corruption: the cell's time IS S1's time.
        $examCell->refresh();
        $this->assertSame('2026-11-04', $examCell->exam_date, 'exam date must survive the sync');
        $this->assertSame($examSubj->id, $examCell->subject_id);
        $this->assertNull($examCell->day);
        $this->assertSame(strtotime('2026-11-04 09:00:00'), (int) $examCell->timestamp_from);
        $this->assertSame(strtotime('2026-11-04 09:30:00'), (int) $examCell->timestamp_to);

        // Other-slot exam cell: byte-identical.
        $otherCell->refresh();
        $this->assertSame($beforeOther, $this->cellTuple($otherCell));

        // Nothing deleted, slot row itself updated.
        $this->assertSame(3, TimeTable::where('ttr_id', $record->id)->count());
        $this->assertSame('9:30 AM', $s1->fresh()->time_to);
    }

    public function test_only_exam_cells_record_syncs_the_same_way()
    {
        [$class, $teacher, $record] = $this->examRecord('ONLYEXAM');
        $examSubj = $this->subject($class->id, $teacher->id, 'R31 Only Exam Subject', 'OEXAM');
        $slot = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $cell = $this->storeCell($record->id, $slot->id, ['exam_date' => '2026-12-01', 'subject_id' => $examSubj->id]);

        $this->slotPut($slot->id, $record->id)
            ->assertRedirect(route('ttr.manage', $record->id))
            ->assertSessionHas('flash_success', 'Record Updated Successfully');

        $cell->refresh();
        $this->assertSame('2026-12-01', $cell->exam_date, 'exam date must survive the sync');
        $this->assertSame($examSubj->id, $cell->subject_id);
        $this->assertSame(strtotime('2026-12-01 09:00:00'), (int) $cell->timestamp_from);
        $this->assertSame(strtotime('2026-12-01 09:30:00'), (int) $cell->timestamp_to);
    }

    public function test_slot_delete_counts_exam_cells_but_empty_slot_deletes()
    {
        [$class, $teacher, $record] = $this->examRecord('DEL');
        $examSubj = $this->subject($class->id, $teacher->id, 'R31 Delete Exam Subject', 'DEXAM');
        $placed = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $empty = $this->slot($record->id, '10:00 AM', '10:40 AM');
        $cell = $this->storeCell($record->id, $placed->id, ['exam_date' => '2026-12-02', 'subject_id' => $examSubj->id]);

        // Slot carrying ONLY an exam sitting: refused by the periods-placed guard.
        $this->from(route('ttr.manage', $record->id));
        $this->actingAs($this->admin())->delete(route('ts.destroy', $placed->id))
            ->assertRedirect(route('ttr.manage', $record->id))
            ->assertSessionHas('flash_danger', 'This time slot has periods placed in it — delete those periods first, then the slot.');
        $this->assertNotNull(TimeSlot::find($placed->id), 'placed slot must survive the refused delete');
        $this->assertNotNull(TimeTable::find($cell->id), 'exam cell must survive the refused delete');

        // Truly empty slot on the same exam record: deletes.
        $this->from(route('ttr.manage', $record->id));
        $this->actingAs($this->admin())->delete(route('ts.destroy', $empty->id))
            ->assertRedirect(route('ttr.manage', $record->id))
            ->assertSessionHas('flash_success', 'Record Deleted Successfully');
        $this->assertNull(TimeSlot::find($empty->id));
    }

    public function test_validator_stays_clean_through_sync_on_exam_record()
    {
        [$class, $teacher, $record] = $this->examRecord('VALID');
        $daySubj = $this->subject($class->id, $teacher->id, 'R31 Valid Day Subject', 'VDAY');
        $examSubj = $this->subject($class->id, $teacher->id, 'R31 Valid Exam Subject', 'VEXAM');
        $slot = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $this->storeCell($record->id, $slot->id, ['day' => 'Monday', 'subject_id' => $daySubj->id]);
        $this->storeCell($record->id, $slot->id, ['exam_date' => '2026-12-03', 'subject_id' => $examSubj->id]);

        $this->assertSame([], TimetableValidator::report($record)['errors'], 'fixture must validate clean before the sync');

        $this->slotPut($slot->id, $record->id)->assertRedirect(route('ttr.manage', $record->id));

        $this->assertSame([], TimetableValidator::report($record->fresh())['errors'], 'validator must stay clean after the sync');
    }
}
