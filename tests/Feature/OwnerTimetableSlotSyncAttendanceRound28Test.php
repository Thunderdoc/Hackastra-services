<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 28 — slot-sync (ts.update) on an attendance-bearing
 * record, the BULK path round 26 never probed against submitted
 * attendance.
 *
 * BRIEF-PREMISE CORRECTION, stated openly: round 26 test 5 ALREADY
 * locked the narrow core — ts.update on an attendance-bearing
 * record re-stamps the cell (date preserved, times follow the slot)
 * and the session still points at the same cell id, while cell
 * delete is refused. What round 26 never proved, and this round
 * locks as genuinely new:
 *  (a) the session's ENTRIES are untouched (same rows, same
 *      statuses) — round 26 attached a session with ZERO entries;
 *  (b) the register still RENDERS for admin after the sync
 *      (attendance.periods.show 200 with the roster names);
 *  (c) the sync re-stamps EVERY cell on the edited slot (all six
 *      days), while every other-slot cell stays byte-identical;
 *  (d) the validator is CLEAN after the sync (report errors []);
 *  (e) slot DELETE on an attendance-bearing slot is refused by
 *      the periods-placed guard (not by an attendance check —
 *      there is none on that path), slot + cells + session intact;
 *  (f) the contrasts: no-attendance sync re-stamps identically,
 *      empty-slot delete succeeds, inverted slot edit is refused
 *      with nothing changed on an attendance-bearing record.
 *
 * Mechanism (TimeTableController::update_time_slot): the slot-row
 * update + the cell sync loop run in ONE transaction; each cell's
 * timestamps are recomposed from its own date part + the slot's
 * new times. Sessions point at CELLS (time_table_id), never at
 * timestamps — that is what keeps a locked register attached
 * through a re-stamp, by construction rather than by check.
 *
 * All fixtures fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableSlotSyncAttendanceRound28Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R28 Teacher ' . $tag,
            'email' => 'r28-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R28' . $tag . uniqid(),
        ]);
    }

    /** Fresh class with six subjects x 6/week, one distinct teacher each. Returns [class, subjectId=>teacherId]. */
    protected function makeSixSubjectClass(string $prefix): array
    {
        $class = MyClass::create(['name' => 'R28 ' . $prefix . ' ' . uniqid()]);
        $teachers = [];
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            $t = $this->teacher($prefix . $i);
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'r28-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $t->id,
                'periods_per_week' => 6,
            ]);
            $teachers[$sub->id] = $t->id;
        }

        return [$class, $teachers];
    }

    protected function generatePayload(array $overrides): array
    {
        return array_merge([
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 6,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 6,
            'scope' => 'one',
            'mode' => 'replace',
        ], $overrides);
    }

    protected function recordFor(int $classId): TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
    }

    /** Generate a full 36-cell grid for a fresh class; returns [class, teachers, record]. */
    protected function generateGrid(string $prefix): array
    {
        [$class, $teachers] = $this->makeSixSubjectClass($prefix);
        $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['class_id' => $class->id, 'mode' => 'replace']))
            ->assertRedirect(route('tt.index'));
        $record = $this->recordFor($class->id);
        $this->assertSame(36, TimeTable::where('ttr_id', $record->id)->count(),
            'fixture grid must be complete before the probe runs');

        return [$class, $teachers, $record];
    }

    /** First (earliest) slot of a record — P1, 9:00–9:40 under the fixture config. */
    protected function firstSlot(int $recordId): TimeSlot
    {
        $slot = TimeSlot::where('ttr_id', $recordId)->orderBy('timestamp_from')->firstOrFail();
        $this->assertSame('9:00 AM', $slot->time_from, 'fixture P1 must start 9:00 AM');
        $this->assertSame('9:40 AM', $slot->time_to, 'fixture P1 must end 9:40 AM');

        return $slot;
    }

    /** Three roster students for a fresh class; returns [studentRecordId => userName].
     *  student_records.section_id is NOT NULL, so the class gets a real section first
     *  (the grid under test stays whole-class; the register roster is class-wide when
     *  neither the session nor the record carries a section). */
    protected function makeRoster(int $classId): array
    {
        $section = Section::create([
            'name' => 'R28 A ' . uniqid(),
            'my_class_id' => $classId,
            'teacher_id' => Subject::where('my_class_id', $classId)->firstOrFail()->teacher_id,
        ]);
        $roster = [];
        foreach (['Asha', 'Bina', 'Chetan'] as $i => $first) {
            $u = User::create([
                'name' => 'R28 Student ' . $first . ' ' . uniqid(),
                'email' => 'r28-stu-' . $i . '-' . uniqid() . '@test.local',
                'password' => bcrypt('student'),
                'user_type' => 'student',
                'code' => 'R28S' . $i . uniqid(),
            ]);
            $sr = StudentRecord::create([
                'session' => Qs::getCurrentSession(),
                'user_id' => $u->id,
                'my_class_id' => $classId,
                'section_id' => $section->id,
                'adm_no' => 'R28/' . uniqid(),
                'year_admitted' => '2026',
            ]);
            $roster[$sr->id] = $u->name;
        }

        return $roster;
    }

    /** Submitted session on a cell, with one entry per roster student (present/absent/late). */
    protected function attachSessionWithEntries(TimeTable $cell, int $classId, array $teachers, array $roster): AttendanceSession
    {
        $session = AttendanceSession::create([
            'date' => '2026-10-26',
            'time_table_id' => $cell->id,
            'my_class_id' => $classId,
            'section_id' => null,
            'subject_id' => $cell->subject_id,
            'teacher_id' => $teachers[$cell->subject_id],
            'topic' => 'R28 sync topic',
            'status' => AttendanceSession::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        $statuses = ['present', 'absent', 'late'];
        foreach (array_keys($roster) as $i => $srId) {
            AttendanceSessionEntry::create([
                'session_id' => $session->id,
                'student_record_id' => $srId,
                'status' => $statuses[$i],
            ]);
        }

        return $session;
    }

    protected function entryMap(int $sessionId): array
    {
        return AttendanceSessionEntry::where('session_id', $sessionId)
            ->orderBy('student_record_id')->get()
            ->mapWithKeys(fn ($e) => [$e->student_record_id => $e->status])->all();
    }

    protected function cellTuples(int $recordId): array
    {
        return TimeTable::where('ttr_id', $recordId)->orderBy('id')->get()
            ->map(fn ($c) => [$c->id, $c->ts_id, $c->day, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
    }

    /** ts.update payload: keep 9:00 AM start, move the end time. */
    protected function slotPut(int $slotId, int $recordId, int $endHour, string $endMin, string $endMeridian)
    {
        return $this->actingAs($this->admin())->put(route('ts.update', $slotId), [
            'ttr_id' => $recordId,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => $endHour, 'min_to' => $endMin, 'meridian_to' => $endMeridian,
        ]);
    }

    public function test_sync_on_attendance_bearing_slot_keeps_session_entries_and_register_and_validates_clean()
    {
        [$class, $teachers, $record] = $this->generateGrid('SYNCATT');
        $slot = $this->firstSlot($record->id);
        $roster = $this->makeRoster($class->id);
        $mondayCell = TimeTable::where('ttr_id', $record->id)
            ->where('ts_id', $slot->id)->where('day', 'Monday')->firstOrFail();
        $session = $this->attachSessionWithEntries($mondayCell, $class->id, $teachers, $roster);

        $slotCellsBefore = TimeTable::where('ttr_id', $record->id)->where('ts_id', $slot->id)
            ->orderBy('id')->get()->map(fn ($c) => [$c->id, date('Y-m-d', $c->timestamp_from)])->all();
        $this->assertCount(6, $slotCellsBefore, 'P1 slot must carry one cell per day');
        $otherCellsBefore = TimeTable::where('ttr_id', $record->id)->where('ts_id', '!=', $slot->id)
            ->orderBy('id')->get()
            ->map(fn ($c) => [$c->id, $c->ts_id, $c->day, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
        $entriesBefore = $this->entryMap($session->id);
        $this->assertSame(['present', 'absent', 'late'], array_values($entriesBefore), 'fixture entries must cover all three statuses');

        // Shorten P1: 9:40 AM end -> 9:30 AM end (no wall-clock overlap with P2, so a clean
        // validator result is the correct expectation, not a masked clash).
        $this->slotPut($slot->id, $record->id, 9, '30', 'AM')
            ->assertRedirect(route('ttr.manage', $record->id));
        $this->assertSame('9:00 AM', $slot->fresh()->time_from);
        $this->assertSame('9:30 AM', $slot->fresh()->time_to);

        // Every P1 cell re-stamped, each keeping its own date part.
        foreach ($slotCellsBefore as [$cellId, $datePart]) {
            $fresh = TimeTable::findOrFail($cellId);
            $this->assertSame($datePart, date('Y-m-d', $fresh->timestamp_from), "cell $cellId must keep its date part");
            $this->assertSame('09:00', date('H:i', $fresh->timestamp_from), "cell $cellId from-time must follow the slot");
            $this->assertSame('09:30', date('H:i', $fresh->timestamp_to), "cell $cellId to-time must follow the slot");
        }
        // Every other-slot cell byte-identical.
        $otherCellsAfter = TimeTable::where('ttr_id', $record->id)->where('ts_id', '!=', $slot->id)
            ->orderBy('id')->get()
            ->map(fn ($c) => [$c->id, $c->ts_id, $c->day, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
        $this->assertSame($otherCellsBefore, $otherCellsAfter, 'cells on other slots must be untouched by the sync');

        // Session + entries untouched: same cell, same rows, same statuses.
        $this->assertSame($mondayCell->id, (int) $session->fresh()->time_table_id, 'session must still point at the same cell');
        $this->assertSame($entriesBefore, $this->entryMap($session->id), 'session entries must be untouched by the sync');

        // Register still renders for admin, with the roster names.
        $show = $this->actingAs($this->admin())->get(route('attendance.periods.show', $session->id));
        $show->assertOk();
        foreach ($roster as $name) {
            $show->assertSee($name);
        }

        // Validator clean after the sync.
        $this->assertSame([], TimetableValidator::report($record->fresh())['errors'], 'validator must be clean after a non-overlapping sync');
    }

    public function test_sync_on_record_without_attendance_restamps_identically()
    {
        [$class, $teachers, $record] = $this->generateGrid('SYNCNOATT');
        $slot = $this->firstSlot($record->id);
        $before = $this->cellTuples($record->id);

        $this->slotPut($slot->id, $record->id, 9, '30', 'AM')
            ->assertRedirect(route('ttr.manage', $record->id));

        $after = $this->cellTuples($record->id);
        foreach ($before as $i => [$id, $tsId, $day, $from, $to, $subject]) {
            if ((int) $tsId === (int) $slot->id) {
                $this->assertSame(date('Y-m-d', $from), date('Y-m-d', $after[$i][3]), "slot cell $id must keep its date part");
                $this->assertSame('09:30', date('H:i', $after[$i][4]), "slot cell $id to-time must follow the slot");
            } else {
                $this->assertSame([$id, $tsId, $day, $from, $to, $subject], $after[$i], "non-slot cell $id must be untouched");
            }
        }
        $this->assertSame([], TimetableValidator::report($record->fresh())['errors']);
    }

    public function test_slot_delete_on_attendance_bearing_slot_refused_and_nothing_changes()
    {
        [$class, $teachers, $record] = $this->generateGrid('SYNCDEL');
        $slot = $this->firstSlot($record->id);
        $roster = $this->makeRoster($class->id);
        $mondayCell = TimeTable::where('ttr_id', $record->id)
            ->where('ts_id', $slot->id)->where('day', 'Monday')->firstOrFail();
        $session = $this->attachSessionWithEntries($mondayCell, $class->id, $teachers, $roster);
        $cellsBefore = $this->cellTuples($record->id);
        $entriesBefore = $this->entryMap($session->id);

        $this->from(route('ttr.manage', $record->id));
        $response = $this->actingAs($this->admin())->delete(route('ts.destroy', $slot->id));
        $response->assertRedirect(route('ttr.manage', $record->id));
        $this->assertSame(
            'This time slot has periods placed in it — delete those periods first, then the slot.',
            session('flash_danger'),
            'slot delete must be refused by the periods-placed guard, not by an attendance check'
        );
        $this->assertNotNull(TimeSlot::find($slot->id), 'refused delete must leave the slot row intact');
        $this->assertSame($cellsBefore, $this->cellTuples($record->id), 'refused delete must leave every cell intact');
        $this->assertSame($mondayCell->id, (int) $session->fresh()->time_table_id);
        $this->assertSame($entriesBefore, $this->entryMap($session->id));
    }

    public function test_slot_delete_on_empty_slot_succeeds()
    {
        [$class, $teachers, $record] = $this->generateGrid('SYNCEMPTY');
        $empty = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 4, 'min_from' => '00', 'meridian_from' => 'PM',
            'hour_to' => 4, 'min_to' => '40', 'meridian_to' => 'PM',
            'time_from' => '4:00 PM', 'time_to' => '4:40 PM',
            'timestamp_from' => strtotime('4:00 PM'), 'timestamp_to' => strtotime('4:40 PM'),
            'full' => '4:00 PM - 4:40 PM',
        ]);
        $this->assertSame(0, TimeTable::where('ts_id', $empty->id)->count(), 'fixture slot must carry no cells');

        $this->from(route('ttr.manage', $record->id));
        $this->actingAs($this->admin())->delete(route('ts.destroy', $empty->id))
            ->assertRedirect(route('ttr.manage', $record->id));
        $this->assertSame('Record Deleted Successfully', session('flash_success'));
        $this->assertNull(TimeSlot::find($empty->id), 'empty slot must actually be deleted');
        $this->assertSame(36, TimeTable::where('ttr_id', $record->id)->count(), 'grid cells must be untouched by the empty-slot delete');
    }

    public function test_inverted_slot_edit_on_attendance_bearing_record_refused_with_nothing_changed()
    {
        [$class, $teachers, $record] = $this->generateGrid('SYNCINV');
        $slot = $this->firstSlot($record->id);
        $roster = $this->makeRoster($class->id);
        $mondayCell = TimeTable::where('ttr_id', $record->id)
            ->where('ts_id', $slot->id)->where('day', 'Monday')->firstOrFail();
        $session = $this->attachSessionWithEntries($mondayCell, $class->id, $teachers, $roster);
        $cellsBefore = $this->cellTuples($record->id);
        $entriesBefore = $this->entryMap($session->id);

        // End == start: the round-3 inverted-times guard must fire before any sync
        // stamps broken timestamps onto a locked register's cells.
        $this->from(route('ttr.manage', $record->id));
        $response = $this->slotPut($slot->id, $record->id, 9, '00', 'AM');
        $response->assertRedirect(route('ttr.manage', $record->id));
        $this->assertSame('The Start Time and End Time Cannot Be The Same', session('flash_danger'));
        $this->assertSame('9:00 AM', $slot->fresh()->time_from);
        $this->assertSame('9:40 AM', $slot->fresh()->time_to);
        $this->assertSame($cellsBefore, $this->cellTuples($record->id), 'refused edit must leave every cell untouched');
        $this->assertSame($mondayCell->id, (int) $session->fresh()->time_table_id);
        $this->assertSame($entriesBefore, $this->entryMap($session->id));
    }
}
