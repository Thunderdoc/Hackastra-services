<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AttendanceSession;
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
 * Agent D round 4 — TT-02 slot-edit sync audit.
 *
 * Placed cells snapshot their slot's time-of-day into their own
 * timestamps, and the validator keys off CELL timestamps — so
 * editing a slot must resync every cell using it, must never
 * stamp broken timestamps, and must never touch other records'
 * cells. Pre-existing broken cells (from before the round-3
 * inverted-slot guard) must stay visible via the Manage flag,
 * not silently re-broken — and unparseable timestamps must be
 * left alone, never rewritten to a 1970 stamp.
 */
class OwnerTimetableSlotSyncRound4Test extends TestCase
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
            'name' => 'Sync Teacher ' . $code,
            'email' => 'sync-' . strtolower($code) . '@test.local',
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
            'name' => 'Sync Record ' . $classId . '-' . uniqid(),
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

    private function cell(int $recordId, int $slotId, int $subjectId, string $day, $dayNum, $from, $to): TimeTable
    {
        return TimeTable::create([
            'ttr_id' => $recordId,
            'ts_id' => $slotId,
            'subject_id' => $subjectId,
            'day' => $day,
            'day_num' => $dayNum,
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

    /** S1: a slot edit resyncs every cell using it, preserving each cell's own date. */
    public function test_slot_edit_syncs_all_cells_and_preserves_dates()
    {
        $t = $this->teacher('SYNC1');
        $class = MyClass::create(['name' => 'Sync Class 1']);
        $s = $this->subject('Sync Subject 1', 'sync-sub-1', $class->id, $t->id);
        $rec = $this->record($class->id);
        $p1 = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $p2 = $this->slot($rec->id, '9:40 AM', '10:20 AM');
        $mon = $this->cell($rec->id, $p1->id, $s->id, 'Monday', 1, strtotime('Monday 9:00 AM'), strtotime('Monday 9:40 AM'));
        $tue = $this->cell($rec->id, $p1->id, $s->id, 'Tuesday', 2, strtotime('Tuesday 9:00 AM'), strtotime('Tuesday 9:40 AM'));
        $other = $this->cell($rec->id, $p2->id, $s->id, 'Monday', 1, strtotime('Monday 9:40 AM'), strtotime('Monday 10:20 AM'));

        $response = $this->slotPut($p1->id, $rec->id, '9:00 AM', '10:00 AM');
        $response->assertRedirect(route('ttr.manage', $rec->id));

        $mon = $mon->fresh();
        $this->assertSame('09:00', date('H:i', (int) $mon->timestamp_from));
        $this->assertSame('10:00', date('H:i', (int) $mon->timestamp_to));
        $this->assertSame(date('Y-m-d', strtotime('Monday 9:00 AM')), date('Y-m-d', (int) $mon->timestamp_from));
        $tue = $tue->fresh();
        $this->assertSame('09:00', date('H:i', (int) $tue->timestamp_from));
        $this->assertSame('10:00', date('H:i', (int) $tue->timestamp_to));
        $this->assertSame(date('Y-m-d', strtotime('Tuesday 9:00 AM')), date('Y-m-d', (int) $tue->timestamp_from));
        $other = $other->fresh();
        $this->assertSame('09:40', date('H:i', (int) $other->timestamp_from));
        $this->assertSame('10:20', date('H:i', (int) $other->timestamp_to));
        $this->assertSame('9:00 AM', $p1->fresh()->time_from);
        $this->assertSame('10:00 AM', $p1->fresh()->time_to);
    }

    /** S2: a pre-existing broken cell stays flagged when an unrelated slot is edited. */
    public function test_preexisting_broken_cell_stays_flagged_after_unrelated_slot_edit()
    {
        $t = $this->teacher('SYNC2');
        $class = MyClass::create(['name' => 'Sync Class 2']);
        $s = $this->subject('Sync Subject 2', 'sync-sub-2', $class->id, $t->id);
        $rec = $this->record($class->id);
        $p1 = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $p2 = $this->slot($rec->id, '9:40 AM', '10:20 AM');
        $this->cell($rec->id, $p1->id, $s->id, 'Monday', 1, strtotime('Monday 10:00 AM'), strtotime('Monday 9:40 AM'));

        $this->actingAs($this->admin())->get(route('ttr.manage', $rec->id))
            ->assertOk()->assertSee('Broken period')->assertSee('broken period(s)');
        $this->assertNotEmpty(array_filter(
            TimetableValidator::report($rec)['errors'],
            fn ($e) => stripos($e, 'Broken cell') !== false
        ));

        $this->slotPut($p2->id, $rec->id, '9:40 AM', '10:30 AM')
            ->assertRedirect(route('ttr.manage', $rec->id));
        $this->actingAs($this->admin())->get(route('ttr.manage', $rec->id))
            ->assertOk()->assertSee('Broken period')->assertSee('broken period(s)');
    }

    /** S3: a slot edit that creates an overlap is caught by the validator (never silent). */
    public function test_slot_edit_creating_overlap_is_flagged_by_validator()
    {
        $t = $this->teacher('SYNC3');
        $class = MyClass::create(['name' => 'Sync Class 3']);
        $s = $this->subject('Sync Subject 3', 'sync-sub-3', $class->id, $t->id);
        $rec = $this->record($class->id);
        $p1 = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $p2 = $this->slot($rec->id, '9:40 AM', '10:20 AM');
        $this->cell($rec->id, $p1->id, $s->id, 'Monday', 1, strtotime('Monday 9:00 AM'), strtotime('Monday 9:40 AM'));
        $this->cell($rec->id, $p2->id, $s->id, 'Monday', 1, strtotime('Monday 9:40 AM'), strtotime('Monday 10:20 AM'));

        $this->assertEmpty(array_filter(
            TimetableValidator::report($rec)['errors'],
            fn ($e) => stripos($e, 'overlap') !== false
        ));
        $this->slotPut($p1->id, $rec->id, '9:00 AM', '10:00 AM')
            ->assertRedirect(route('ttr.manage', $rec->id));
        $this->assertNotEmpty(array_filter(
            TimetableValidator::report($rec)['errors'],
            fn ($e) => stripos($e, 'overlap') !== false
        ));
    }

    /** S4: unparseable timestamps are left alone, never rewritten to a 1970 stamp. */
    public function test_unparseable_cell_timestamps_are_not_rewritten_by_slot_edit()
    {
        $t = $this->teacher('SYNC4');
        $class = MyClass::create(['name' => 'Sync Class 4']);
        $s = $this->subject('Sync Subject 4', 'sync-sub-4', $class->id, $t->id);
        $rec = $this->record($class->id);
        $p1 = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $cell = $this->cell($rec->id, $p1->id, $s->id, 'Monday', 1, '', '');

        $this->slotPut($p1->id, $rec->id, '9:00 AM', '10:00 AM')
            ->assertRedirect(route('ttr.manage', $rec->id));
        $cell = $cell->fresh();
        $this->assertSame('', $cell->timestamp_from);
        $this->assertSame('', $cell->timestamp_to);
    }

    /** S5: submitted attendance stays attached to its period across a slot edit. */
    public function test_attendance_session_stays_attached_across_slot_edit()
    {
        $t = $this->teacher('SYNC5');
        $class = MyClass::create(['name' => 'Sync Class 5']);
        $s = $this->subject('Sync Subject 5', 'sync-sub-5', $class->id, $t->id);
        $rec = $this->record($class->id);
        $p1 = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $cell = $this->cell($rec->id, $p1->id, $s->id, 'Monday', 1, strtotime('Monday 9:00 AM'), strtotime('Monday 9:40 AM'));
        $session = AttendanceSession::create([
            'date' => date('Y-m-d'),
            'time_table_id' => $cell->id,
            'my_class_id' => $class->id,
            'subject_id' => $s->id,
            'teacher_id' => $t->id,
            'topic' => 'Sync topic',
        ]);

        $this->slotPut($p1->id, $rec->id, '9:00 AM', '10:00 AM')
            ->assertRedirect(route('ttr.manage', $rec->id));
        $this->assertSame($cell->id, (int) $session->fresh()->time_table_id);
    }

    /** S6: another record's cells are untouched by this record's slot edit. */
    public function test_other_record_cells_untouched_by_slot_edit()
    {
        $t = $this->teacher('SYNC6');
        $class = MyClass::create(['name' => 'Sync Class 6']);
        $s = $this->subject('Sync Subject 6', 'sync-sub-6', $class->id, $t->id);
        $rec = $this->record($class->id);
        $p1 = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $this->cell($rec->id, $p1->id, $s->id, 'Monday', 1, strtotime('Monday 9:00 AM'), strtotime('Monday 9:40 AM'));

        $class2 = MyClass::create(['name' => 'Sync Class 6b']);
        $s2 = $this->subject('Sync Subject 6b', 'sync-sub-6b', $class2->id, $t->id);
        $rec2 = $this->record($class2->id);
        $q1 = $this->slot($rec2->id, '9:00 AM', '9:40 AM');
        $cell2 = $this->cell($rec2->id, $q1->id, $s2->id, 'Monday', 1, strtotime('Monday 9:00 AM'), strtotime('Monday 9:40 AM'));

        $this->slotPut($p1->id, $rec->id, '9:00 AM', '10:00 AM')
            ->assertRedirect(route('ttr.manage', $rec->id));
        $cell2 = $cell2->fresh();
        $this->assertSame('09:40', date('H:i', (int) $cell2->timestamp_to));
    }

    /** S7 (round-3 guard lock): an inverted slot edit is refused and changes nothing. */
    public function test_inverted_slot_edit_refused_and_changes_nothing()
    {
        $t = $this->teacher('SYNC7');
        $class = MyClass::create(['name' => 'Sync Class 7']);
        $s = $this->subject('Sync Subject 7', 'sync-sub-7', $class->id, $t->id);
        $rec = $this->record($class->id);
        $p1 = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $cell = $this->cell($rec->id, $p1->id, $s->id, 'Monday', 1, strtotime('Monday 9:00 AM'), strtotime('Monday 9:40 AM'));

        $response = $this->slotPut($p1->id, $rec->id, '10:00 AM', '9:00 AM');
        $this->assertNotNull($response->getSession()->get('flash_danger'));
        $this->assertSame('9:00 AM', $p1->fresh()->time_from);
        $this->assertSame('9:40 AM', $p1->fresh()->time_to);
        $cell = $cell->fresh();
        $this->assertSame('09:40', date('H:i', (int) $cell->timestamp_to));
    }
}
