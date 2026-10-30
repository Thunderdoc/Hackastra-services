<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 21 — two validator seams never probed:
 *
 * (a) CROSS-CLASS IS NEVER EXEMPT. The whole-class exemption
 * covers STREAM only: TimetableValidator::recordErrors checks
 * class membership first and only then (elseif) the stream, so
 * a foreign-class subject on a whole-class grid must still be
 * flagged — exactly one "belongs to another class" error, pill
 * withheld. If the validator stayed green, the whole-class
 * exemption would over-reach. Proven through the real tt.store
 * path (TTRequest validates subject_id only as exists — no
 * class check — so the store accepts the foreign subject and
 * validation is the only line of defence).
 *
 * (b) SLOT-SYNC HEALS A BROKEN CELL. A broken cell (to <= from,
 * direct data — Manage store refuses it) on a record whose slot
 * times are then updated to valid times via update_time_slot:
 * the sync loop recomposes every cell's timestamps from its own
 * date part + the slot's new times, so the cell should come out
 * un-broken and the validator clean + pill returned, with NO
 * delete needed. Round 14 proved delete remediates; this proves
 * whether sync also remediates.
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableCrossClassSyncRound21Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R21 Teacher ' . $tag,
            'email' => 'r21-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R21' . $tag . uniqid(),
        ]);
    }

    protected function slot(int $recordId, string $from, string $to): TimeSlot
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

    /** Fresh whole-class record (section null) + its own subject + a foreign-class subject. */
    protected function fixture(?string $sectionStream): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'R21 Class ' . $u]);
        $teacher = $this->teacher($u);
        $own = Subject::create([
            'name' => 'R21Own' . $u, 'slug' => 'r21-own-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $foreignClass = MyClass::create(['name' => 'R21 Foreign ' . $u]);
        $foreignTeacher = $this->teacher('F' . $u);
        $foreign = Subject::create([
            'name' => 'R21Foreign' . $u, 'slug' => 'r21-foreign-' . $u,
            'my_class_id' => $foreignClass->id, 'teacher_id' => $foreignTeacher->id,
            'periods_per_week' => 6, 'stream' => 'pcm',
        ]);
        $section = null;
        if ($sectionStream !== null) {
            $section = Section::create([
                'name' => 'R21Sec' . $u, 'my_class_id' => $class->id,
                'active' => 1, 'teacher_id' => $teacher->id, 'stream' => $sectionStream,
            ]);
        }
        $record = TimeTableRecord::create([
            'name' => 'R21 Record ' . $u, 'my_class_id' => $class->id,
            'section_id' => $section ? $section->id : null,
            'exam_id' => null, 'year' => Qs::getCurrentSession(),
        ]);
        $slot1 = $this->slot($record->id, '9:00 AM', '9:40 AM');

        return [$record, $own, $foreign, $teacher, $slot1];
    }

    /** (a) Whole-class grid: a foreign-class subject sitting in the
     *  grid is flagged, pill withheld. The cell is seeded directly
     *  since the cascade audit (2026-10-30): TTRequest now REFUSES
     *  a cross-class cell post — the validator stays as the safety
     *  net for bad rows however they arise (legacy/imported data). */
    public function test_whole_class_grid_flags_cross_class_subject()
    {
        [$record, $own, $foreign, $teacher, $slot1] = $this->fixture(null);

        TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'subject_id' => $foreign->id,
            'timestamp_from' => strtotime('Monday ' . $slot1->time_from),
            'timestamp_to' => strtotime('Monday ' . $slot1->time_to),
        ]);
        $this->assertDatabaseHas('time_tables', [
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'subject_id' => $foreign->id,
        ]);

        $report = TimetableValidator::report($record->fresh());
        $this->assertCount(1, $report['errors'], 'Exactly one error: the cross-class flag, nothing else');
        $this->assertSame(
            $foreign->name . ' on Monday belongs to another class — it cannot sit in this grid.',
            $report['errors'][0]
        );
        $this->assertNotContains(
            $record->id,
            TimetableValidator::cleanRecordIds(Qs::getCurrentSession()),
            'Cross-class record must lose its green Validated pill'
        );
    }

    /** Control: on a section grid the cross-class error is reported ONCE, never doubled with a stream error. */
    public function test_section_grid_reports_cross_class_once_not_doubled_with_stream()
    {
        [$record, $own, $foreign, $teacher, $slot1] = $this->fixture('pcb');

        // Seeded directly — tt.store refuses cross-class posts now
        // (TTRequest pair guard, cascade audit 2026-10-30).
        TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'subject_id' => $foreign->id,
            'timestamp_from' => strtotime('Monday ' . $slot1->time_from),
            'timestamp_to' => strtotime('Monday ' . $slot1->time_to),
        ]);

        $report = TimetableValidator::report($record->fresh());
        $this->assertCount(1, $report['errors'], 'Cross-class takes precedence via elseif — no stream error alongside it');
        $this->assertStringContainsString('belongs to another class', $report['errors'][0]);
        $this->assertNotContains(
            $record->id,
            TimetableValidator::cleanRecordIds(Qs::getCurrentSession())
        );
    }

    /** (b) Slot-sync heals a broken cell: no delete needed, validator clean + pill returned. */
    public function test_slot_sync_heals_broken_cell()
    {
        [$record, $own, $foreign, $teacher, $slot1] = $this->fixture(null);

        // Broken cell by direct data (Manage store refuses end <= start):
        // Monday 2026-11-02, 10:20 AM -> 9:40 AM (ends before it starts).
        $cell = TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'day_num' => 1, 'subject_id' => $own->id,
            'timestamp_from' => strtotime('2026-11-02 10:20 AM'),
            'timestamp_to' => strtotime('2026-11-02 9:40 AM'),
        ]);
        $this->assertTrue(TimetableValidator::isBrokenCell($cell->fresh()));

        $report = TimetableValidator::report($record->fresh());
        $this->assertCount(1, $report['errors'], 'Exactly one error before sync: the broken cell');
        $this->assertSame(
            'Broken cell on Monday (10:20 AM - 9:40 AM): the period ends at or before it starts — fix or delete it on the Manage page.',
            $report['errors'][0]
        );
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()));

        // Update the slot to valid times via the real endpoint: sync
        // recomposes the cell from its own date part + the new times.
        $this->actingAs($this->admin())->put(route('ts.update', $slot1->id), [
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
        ])->assertRedirect(route('ttr.manage', $record->id));

        $healed = $cell->fresh();
        $this->assertFalse(TimetableValidator::isBrokenCell($healed), 'Sync must un-break the cell');
        $this->assertSame(strtotime('2026-11-02 9:00 AM'), (int) $healed->timestamp_from);
        $this->assertSame(strtotime('2026-11-02 9:40 AM'), (int) $healed->timestamp_to);

        $report = TimetableValidator::report($record->fresh());
        $this->assertSame([], $report['errors'], 'Validator clean after sync, no delete needed');
        $this->assertContains(
            $record->id,
            TimetableValidator::cleanRecordIds(Qs::getCurrentSession()),
            'Healed record regains its green Validated pill'
        );
    }
}
