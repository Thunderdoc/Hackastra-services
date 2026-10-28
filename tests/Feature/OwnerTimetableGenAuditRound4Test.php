<?php

namespace Tests\Feature;

use App\Helpers\Qs;
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
 * Agent D round 3 — Manage-page broken-cell visibility + the
 * inverted-slot production path.
 *
 * Round 2 gave the validator a broken-cell flag, but the Manage
 * page showed no trace of it: an admin had to run validation
 * first to learn a period was broken. This file pins the grid
 * flag, the flag/validator agreement, the edit-to-fix flow, and
 * the slot guards that stop new broken cells being created.
 */
class OwnerTimetableGenAuditRound4Test extends TestCase
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
            'name' => 'R4 Teacher ' . $code,
            'email' => 'r4-' . strtolower($code) . '@test.local',
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
            'name' => 'R4 Record ' . $classId,
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

    private function cell(int $recordId, int $slotId, int $subjectId, string $day, string $from, string $to): TimeTable
    {
        return TimeTable::create([
            'ttr_id' => $recordId,
            'ts_id' => $slotId,
            'subject_id' => $subjectId,
            'day' => $day,
            'day_num' => array_search($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], true) + 1,
            'timestamp_from' => strtotime($day . ' ' . $from),
            'timestamp_to' => strtotime($day . ' ' . $to),
        ]);
    }

    /** The Manage grid flags a broken cell with banner + row + badge. */
    public function test_manage_flags_broken_cell()
    {
        $class = MyClass::create(['name' => 'R4 Flag Class']);
        $t = $this->teacher('FLAG');
        $s = $this->subject('Flag Subject', 'r4-flag-sub', $class->id, $t->id);
        $rec = $this->record($class->id);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $this->cell($rec->id, $slot->id, $s->id, 'Monday', '10:00 AM', '9:00 AM');

        $response = $this->actingAs($this->admin())->get(route('ttr.manage', $rec->id));
        $response->assertOk();
        $response->assertSee('Broken period');
        $response->assertSee('table-danger');
        $response->assertSee('broken period(s) in this record');
    }

    /** The flag and the validator agree, via the one shared definition. */
    public function test_manage_flag_matches_validator_report()
    {
        $class = MyClass::create(['name' => 'R4 Agree Class']);
        $t = $this->teacher('AGREE');
        $s = $this->subject('Agree Subject', 'r4-agree-sub', $class->id, $t->id);
        $rec = $this->record($class->id);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $cell = $this->cell($rec->id, $slot->id, $s->id, 'Tuesday', '9:40 AM', '9:40 AM');

        $this->assertTrue(TimetableValidator::isBrokenCell($cell));
        $errors = TimetableValidator::report($rec)['errors'];
        $this->assertNotEmpty(array_filter($errors, fn($e) => str_contains($e, 'Broken cell')));
        $this->actingAs($this->admin())->get(route('ttr.manage', $rec->id))
            ->assertOk()->assertSee('Broken period');
    }

    /** A clean record shows no broken flag anywhere on Manage. */
    public function test_manage_clean_record_shows_no_broken_flag()
    {
        // Seeded Prep record: 36 valid cells, no broken timestamps.
        $this->actingAs($this->admin())->get(route('ttr.manage', 1))
            ->assertOk()
            ->assertDontSee('Broken period')
            ->assertDontSee('broken period(s)');
    }

    /** Editing a broken cell to a valid slot clears the flag. */
    public function test_broken_cell_edit_clears_flag()
    {
        $class = MyClass::create(['name' => 'R4 Fix Class']);
        $t = $this->teacher('FIX');
        $s = $this->subject('Fix Subject', 'r4-fix-sub', $class->id, $t->id);
        $rec = $this->record($class->id);
        $badSlot = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $goodSlot = $this->slot($rec->id, '10:00 AM', '10:40 AM');
        $cell = $this->cell($rec->id, $badSlot->id, $s->id, 'Monday', '10:00 AM', '9:00 AM');

        $this->actingAs($this->admin())->get(route('ttr.manage', $rec->id))
            ->assertOk()->assertSee('Broken period');

        $upd = $this->actingAs($this->admin())->from(route('ttr.manage', $rec->id))
            ->put(route('tt.update', $cell->id), [
                'ttr_id' => $rec->id,
                'ts_id' => $goodSlot->id,
                'day' => 'Monday',
                'subject_id' => $s->id,
            ]);
        $upd->assertRedirect(route('ttr.manage', $rec->id));

        $fixed = TimeTable::find($cell->id);
        $this->assertGreaterThan((int) $fixed->timestamp_from, (int) $fixed->timestamp_to);
        $this->assertFalse(TimetableValidator::isBrokenCell($fixed));
        $this->actingAs($this->admin())->get(route('ttr.manage', $rec->id))
            ->assertOk()->assertDontSee('Broken period');
    }

    /** Inverted slot creation is refused; no slot is stored. */
    public function test_inverted_slot_store_refused()
    {
        $class = MyClass::create(['name' => 'R4 Slot Class']);
        $rec = $this->record($class->id);

        $response = $this->actingAs($this->admin())->post(route('ts.store'), [
            'ttr_id' => $rec->id,
            'hour_from' => 10, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '00', 'meridian_to' => 'AM',
        ]);
        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertSame(0, TimeSlot::where('ttr_id', $rec->id)->count());
    }

    /** Equal slot creation stays refused (pre-existing behaviour). */
    public function test_equal_slot_store_still_refused()
    {
        $class = MyClass::create(['name' => 'R4 Equal Class']);
        $rec = $this->record($class->id);

        $response = $this->actingAs($this->admin())->post(route('ts.store'), [
            'ttr_id' => $rec->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '00', 'meridian_to' => 'AM',
        ]);
        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertSame(0, TimeSlot::where('ttr_id', $rec->id)->count());
    }

    /** Inverted slot edit is refused and cells using it stay valid. */
    public function test_inverted_slot_update_refused_and_cells_stay_valid()
    {
        $class = MyClass::create(['name' => 'R4 Edit Class']);
        $t = $this->teacher('EDIT');
        $s = $this->subject('Edit Subject', 'r4-edit-sub', $class->id, $t->id);
        $rec = $this->record($class->id);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $cell = $this->cell($rec->id, $slot->id, $s->id, 'Monday', '9:00 AM', '9:40 AM');

        $upd = $this->actingAs($this->admin())->from(route('ttr.manage', $rec->id))
            ->put(route('ts.update', $slot->id), [
                'ttr_id' => $rec->id,
                'hour_from' => 10, 'min_from' => '00', 'meridian_from' => 'AM',
                'hour_to' => 9, 'min_to' => '00', 'meridian_to' => 'AM',
            ]);
        $upd->assertSessionHas('flash_danger');

        $kept = TimeSlot::find($slot->id);
        $this->assertSame('9:00 AM', $kept->time_from);
        $this->assertSame('9:40 AM', $kept->time_to);
        $keptCell = TimeTable::find($cell->id);
        $this->assertFalse(TimetableValidator::isBrokenCell($keptCell));
        $this->assertEmpty(array_filter(
            TimetableValidator::report($rec)['errors'],
            fn($e) => str_contains($e, 'Broken cell')
        ));
    }
}
