<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AttendanceSession;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 26 — mode=replace vs submitted attendance.
 *
 * BRIEF-PREMISE CORRECTION, stated openly (round-23/24 style):
 * the brief said this rule was "never test-locked by a D round".
 * That is only half true. Two narrow locks already exist:
 *  - OwnerTimetableGenAuditTest::test_replace_lock_and_skip_keep
 *    (test J): replace on a hand-built 1-cell record flashes
 *    'locked' and the cell COUNT stays 1; skip flashes
 *    'already has one'. It never proves byte-identity, the exact
 *    Kept flash clause, the record/slot rows, or a mixed run.
 *  - OwnerTimetableSlotSyncRound4Test S5: a session stays
 *    attached (same cell id) across a slot edit. It never proves
 *    the slot row + cell re-stamp, nor the delete refusal.
 * This round locks what is genuinely new:
 *  (a) replace on a REAL generated 36-cell grid with one submitted
 *      register: the grid (cells + slots + record id) is
 *      BYTE-IDENTICAL afterward, and the flash carries the exact
 *      Kept clause — not a bare 'locked' substring.
 *  (b) skip on the same grid: exact skip-all flash + byte-identity.
 *  (c) positive control: the same grid with NO sessions
 *      regenerates cell-identical AND reuses the record id
 *      (determinism, round 16 — re-proved here on the plain grid).
 *  (d) scope=all replace: a locked class is kept WHILE a sibling
 *      class regenerates in the SAME run (the locked `continue`
 *      must not poison the run or roll back the sibling).
 *  (e) ts.update on an attendance-bearing record: slot row +
 *      cell re-stamp happen, the session still points at the same
 *      cell id — and the cell DELETE is refused with the exact
 *      locked-history flash (the contrast that makes (e) safe).
 *
 * All fixtures fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableReplaceAttendanceRound26Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R26 Teacher ' . $tag,
            'email' => 'r26-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R26' . $tag . uniqid(),
        ]);
    }

    /** Fresh class with six subjects x 6/week, one distinct teacher each. Returns [class, subjectId=>teacherId]. */
    protected function makeSixSubjectClass(string $prefix): array
    {
        $class = MyClass::create(['name' => 'R26 ' . $prefix . ' ' . uniqid()]);
        $teachers = [];
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            $t = $this->teacher($prefix . $i);
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'r26-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
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

    /** Full cell tuples, ordered — the byte-identity comparison for a grid. */
    protected function gridTuples(int $recordId): array
    {
        return TimeTable::where('ttr_id', $recordId)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => [$c->day_num, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
    }

    protected function gridCellIds(int $recordId): array
    {
        return TimeTable::where('ttr_id', $recordId)->orderBy('id')->pluck('id')->all();
    }

    /** Slot time-of-day tuples (ids change on replace — times must not). */
    protected function slotTuples(int $recordId): array
    {
        return TimeSlot::where('ttr_id', $recordId)
            ->orderBy('timestamp_from')
            ->get()->map(fn ($s) => [$s->time_from, $s->time_to, $s->full])->all();
    }

    protected function attachSession(TimeTable $cell, int $classId, array $teachers): AttendanceSession
    {
        return AttendanceSession::create([
            'date' => '2026-10-26',
            'time_table_id' => $cell->id,
            'my_class_id' => $classId,
            'section_id' => null,
            'subject_id' => $cell->subject_id,
            'teacher_id' => $teachers[$cell->subject_id],
            'topic' => 'R26 lock topic',
            'status' => AttendanceSession::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
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

    public function test_replace_on_attendance_locked_grid_keeps_grid_byte_identical()
    {
        [$class, $teachers, $record] = $this->generateGrid('LOCKED');
        $beforeCells = $this->gridTuples($record->id);
        $beforeSlots = $this->slotTuples($record->id);
        $firstCell = TimeTable::where('ttr_id', $record->id)->orderBy('day_num')->orderBy('timestamp_from')->firstOrFail();
        $session = $this->attachSession($firstCell, $class->id, $teachers);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['class_id' => $class->id, 'mode' => 'replace']));
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';

        // Exact Kept clause (TimeTableController::generate, scope=one
        // branch): 0 generated, 0 placed, locked name named.
        $this->assertSame(
            'Timetables generated for 0 class(es): 0 periods placed. Kept (attendance already submitted, timetable locked this session): ' . $class->name . '.',
            $flash,
            "replace on a locked grid must report exactly the Kept clause; flash: $flash"
        );

        // Byte-identity: same record, same cells, same slot times.
        $this->assertSame($record->id, $this->recordFor($class->id)->id, 'locked record id must not change');
        $this->assertSame($beforeCells, $this->gridTuples($record->id), 'locked grid cells must be byte-identical after replace');
        $this->assertSame($beforeSlots, $this->slotTuples($record->id), 'locked grid slots must be untouched after replace');
        $this->assertSame($firstCell->id, (int) $session->fresh()->time_table_id, 'session must still point at the same cell');
    }

    public function test_skip_on_attendance_locked_grid_keeps_grid_and_reports_skip_all()
    {
        [$class, $teachers, $record] = $this->generateGrid('SKIPLOCK');
        $beforeCells = $this->gridTuples($record->id);
        $firstCell = TimeTable::where('ttr_id', $record->id)->orderBy('day_num')->orderBy('timestamp_from')->firstOrFail();
        $this->attachSession($firstCell, $class->id, $teachers);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['class_id' => $class->id, 'mode' => 'skip']));
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';

        // Exact skip-all flash (the branch fires because generated is
        // empty and skipped is non-empty; a lock cannot coexist here
        // because locks only fire in replace mode).
        $this->assertSame(
            'No new timetables: every class already has one for ' . Qs::getCurrentSession()
            . ' — nothing was generated. Use “Replace theirs with a fresh generated one” to rebuild, or generate for a different session year.'
            . ' Skipped: ' . $class->name . '.',
            $flash,
            "skip on a locked grid must report exactly the skip-all flash; flash: $flash"
        );
        $this->assertSame($beforeCells, $this->gridTuples($record->id), 'skipped grid cells must be byte-identical');
    }

    public function test_replace_without_attendance_regenerates_cell_identical_and_reuses_record()
    {
        [$class, $teachers, $record] = $this->generateGrid('CLEAN');
        $beforeCells = $this->gridTuples($record->id);
        $beforeSlots = $this->slotTuples($record->id);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['class_id' => $class->id, 'mode' => 'replace']));
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';
        $this->assertSame('Timetables generated for 1 class(es): 36 periods placed.', $flash,
            "clean replace must regenerate the full grid; flash: $flash");

        // Determinism (round 16, re-proved on the plain grid): same
        // cells, same slot times, same record id — replace rebuilds
        // in place, it does not refuse and does not drift.
        $this->assertSame($record->id, $this->recordFor($class->id)->id, 'replace must reuse the record row');
        $this->assertSame($beforeCells, $this->gridTuples($record->id), 'clean replace must regenerate cell-identical');
        $this->assertSame($beforeSlots, $this->slotTuples($record->id), 'clean replace must rebuild identical slot times');
    }

    public function test_scope_all_replace_keeps_locked_class_while_sibling_regenerates()
    {
        [$classA, $teachersA, $recordA] = $this->generateGrid('LOCKEDA');
        [$classB, $teachersB, $recordB] = $this->generateGrid('FREEB');
        $beforeCellsA = $this->gridTuples($recordA->id);
        $beforeIdsA = $this->gridCellIds($recordA->id);
        $beforeIdsB = $this->gridCellIds($recordB->id);
        $firstCellA = TimeTable::where('ttr_id', $recordA->id)->orderBy('day_num')->orderBy('timestamp_from')->firstOrFail();
        $this->attachSession($firstCellA, $classA->id, $teachersA);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['scope' => 'all', 'mode' => 'replace']));
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';

        // Kept clause names A and not B (segment-anchored: the list
        // order is target order, so A need not be first).
        $this->assertMatchesRegularExpression(
            '/Kept \(attendance already submitted, timetable locked this session\): ([^.]+)\./',
            $flash, "scope=all replace flash must carry a Kept clause; flash: $flash"
        );
        preg_match('/Kept \(attendance already submitted, timetable locked this session\): ([^.]+)\./', $flash, $m);
        $this->assertStringContainsString($classA->name, $m[1], 'Kept clause must name the locked class');
        $this->assertStringNotContainsString($classB->name, $m[1], 'Kept clause must not name the unlocked sibling');

        // A untouched (same cell ids AND tuples); B rebuilt (same
        // count, all-new cell ids — delete + recreate, not a keep).
        $this->assertSame($beforeIdsA, $this->gridCellIds($recordA->id), 'locked class cell ids must be untouched in a mixed run');
        $this->assertSame($beforeCellsA, $this->gridTuples($recordA->id), 'locked class cells must be byte-identical in a mixed run');
        $this->assertSame(36, TimeTable::where('ttr_id', $recordB->id)->count(), 'sibling must still hold a full grid');
        $this->assertSame([], array_intersect($beforeIdsB, $this->gridCellIds($recordB->id)),
            'sibling cells must be recreated (new ids), proving it regenerated rather than being kept');
    }

    public function test_slot_update_on_attendance_bearing_record_restamps_cells_and_keeps_session_while_cell_delete_refused()
    {
        [$class, $teachers, $record] = $this->generateGrid('SLOTLOCK');
        $cell = TimeTable::where('ttr_id', $record->id)->orderBy('day_num')->orderBy('timestamp_from')->firstOrFail();
        $slot = TimeSlot::findOrFail($cell->ts_id);
        $session = $this->attachSession($cell, $class->id, $teachers);
        $datePart = date('Y-m-d', $cell->timestamp_from);

        // ts.edit renders for the attendance-bearing slot.
        $this->actingAs($this->admin())->get(route('ts.edit', $slot->id))->assertOk();

        // ts.update: 9:40 AM end -> 10:00 AM end (from stays 9:00 AM).
        // No attendance check exists on this path by design: sessions
        // point at cells, not timestamps, so the re-stamp is safe.
        $response = $this->actingAs($this->admin())->put(route('ts.update', $slot->id), [
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 10, 'min_to' => '00', 'meridian_to' => 'AM',
        ]);
        $response->assertRedirect(route('ttr.manage', $record->id));
        $this->assertSame('9:00 AM', $slot->fresh()->time_from, 'slot row from-time must update');
        $this->assertSame('10:00 AM', $slot->fresh()->time_to, 'slot row to-time must update');
        $freshCell = $cell->fresh();
        $this->assertSame($datePart, date('Y-m-d', $freshCell->timestamp_from), 'cell date part must be preserved by the sync');
        $this->assertSame('09:00', date('H:i', $freshCell->timestamp_from), 'cell from-time must follow the slot');
        $this->assertSame('10:00', date('H:i', $freshCell->timestamp_to), 'cell to-time must follow the slot');
        $this->assertSame($cell->id, (int) $session->fresh()->time_table_id, 'session must still point at the same cell after the slot edit');

        // Contrast: deleting that same cell IS refused — history is
        // not deletable (TimeTableController::delete guard).
        $this->from(route('ttr.manage', $record->id));
        $response = $this->actingAs($this->admin())->delete(route('tt.delete', $cell->id));
        $response->assertRedirect(route('ttr.manage', $record->id));
        $this->assertSame(
            'This period cannot be deleted — attendance has already been submitted for it. Submitted attendance is locked history.',
            session('flash_danger'),
            'cell delete on an attendance-bearing period must be refused with the locked-history flash'
        );
        $this->assertNotNull(TimeTable::find($cell->id), 'refused delete must leave the cell intact');
    }
}
