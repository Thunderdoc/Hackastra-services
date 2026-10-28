<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Models\TimeSlot;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 12 — combined error surfaces + slot-sync
 * preservation (rounds 9/10 proved single-error paths only).
 *
 * (a) One streamed section grid holding a broken cell, an
 * out-of-stream cell AND a genuine same-record overlap must
 * report each defect exactly once: broken cells are skipped by
 * the overlap sweep (round 3), the stream error is per cell
 * (round 9), and the overlap sweep breaks after the first
 * overlap per day — but none of that was ever proven TOGETHER
 * on one record, on the Validate page, and against the index
 * pill. A duplicate or a missing error here would mean the
 * sweeps interfere.
 *
 * (b) Slot-sync preservation: updating a time slot whose cells
 * include an out-of-stream cell must still sync that cell
 * (visibility philosophy — sync rewrites timestamps, it must
 * not silently drop or delete the cell, and the stream error
 * must survive the sync).
 *
 * Fixtures are fully fresh (own section, teachers, subjects,
 * record, slots) so no seeded cell, teacher clash, or coverage
 * row can pollute the counts. All data rolls back.
 */
class OwnerTimetableCombinedErrorsRound12Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R12 Teacher ' . $tag,
            'email' => 'r12-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R12' . $tag . uniqid(),
        ]);
    }

    protected function subject(string $name, int $classId, User $teacher, ?string $stream): Subject
    {
        return Subject::create([
            'name' => $name,
            'slug' => strtolower(preg_replace('/[^a-z0-9]+/', '-', $name)) . '-' . uniqid(),
            'my_class_id' => $classId,
            'teacher_id' => $teacher->id,
            'periods_per_week' => 6,
            'stream' => $stream,
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

    protected function cell(int $recordId, int $slotId, int $subjectId, string $day, int $dayNum, string $from, string $to): TimeTable
    {
        return TimeTable::create([
            'ttr_id' => $recordId,
            'ts_id' => $slotId,
            'day' => $day,
            'day_num' => $dayNum,
            'subject_id' => $subjectId,
            'timestamp_from' => strtotime($from),
            'timestamp_to' => strtotime($to),
        ]);
    }

    /**
     * Fresh streamed section (Class 12, pcb) + record + slots +
     * four cells: broken (Mon, inverted), out-of-stream (Tue),
     * overlapping pair (Wed, different slots). Every subject has
     * its own fresh teacher, so no teacher clash can appear.
     * Returns [record, outSubject, slotForSyncTests].
     */
    protected function combinedFixture(): array
    {
        $section = Section::create([
            'name' => 'R12 Sec ' . uniqid(),
            'my_class_id' => 13,
            'teacher_id' => $this->teacher('SEC')->id,
            'stream' => 'pcb',
        ]);
        $record = TimeTableRecord::create([
            'name' => 'R12 Combined ' . uniqid(),
            'my_class_id' => 13,
            'section_id' => $section->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        // NOTE: time_slots carries UNIQUE(timestamp_from,
        // timestamp_to, ttr_id), so the four slots must differ in
        // clock times even though the overlap pair's CELL times
        // overlap. Cell timestamps are what the validator keys
        // off; the broken cell's slot times are irrelevant to it.
        $slot1 = $this->slot($record->id, '10:00 AM', '10:40 AM');
        $slot2 = $this->slot($record->id, '9:40 AM', '10:20 AM');
        $slot3 = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $slot4 = $this->slot($record->id, '9:20 AM', '10:00 AM');

        // Broken: Monday, ends (9:00) at/before it starts (10:00).
        $this->cell($record->id, $slot1->id,
            $this->subject('R12 Common ' . uniqid(), 13, $this->teacher('BRK'), null)->id,
            'Monday', 1, '2026-10-26 10:00:00', '2026-10-26 09:00:00');
        // Out-of-stream: commerce-only subject in a pcb section.
        $out = $this->subject('R12 Commerce ' . uniqid(), 13, $this->teacher('OUT'), 'commerce');
        $this->cell($record->id, $slot2->id, $out->id,
            'Tuesday', 2, '2026-10-27 09:40:00', '2026-10-27 10:20:00');
        // Overlap pair: Wednesday 9:00-9:40 vs 9:20-10:00, different slots.
        $this->cell($record->id, $slot3->id,
            $this->subject('R12 PCB ' . uniqid(), 13, $this->teacher('OV1'), 'pcb')->id,
            'Wednesday', 3, '2026-10-28 09:00:00', '2026-10-28 09:40:00');
        $this->cell($record->id, $slot4->id,
            $this->subject('R12 Common2 ' . uniqid(), 13, $this->teacher('OV2'), null)->id,
            'Wednesday', 3, '2026-10-28 09:20:00', '2026-10-28 10:00:00');

        return [$record, $out, $slot2];
    }

    protected function errorsMatching(array $errors, string $needle): array
    {
        return array_values(array_filter($errors, fn ($e) => str_contains($e, $needle)));
    }

    public function test_combined_record_reports_each_defect_exactly_once()
    {
        [$record, $out] = $this->combinedFixture();
        $report = TimetableValidator::report($record->fresh());
        $errors = $report['errors'];

        $this->assertCount(3, $errors, 'Expected exactly broken + stream + overlap, got: ' . implode(' | ', $errors));
        $this->assertCount(1, $this->errorsMatching($errors, 'Broken cell on Monday'));
        $this->assertCount(1, $this->errorsMatching($errors, 'is not offered to this section'));
        $this->assertCount(1, $this->errorsMatching($errors, 'Class overlap on Wednesday'));
        $this->assertStringContainsString($out->name, implode(' ', $this->errorsMatching($errors, 'is not offered')));
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds($record->year));
    }

    public function test_validate_page_renders_each_combined_error_once()
    {
        [$record] = $this->combinedFixture();

        $html = $this->actingAs($this->admin())
            ->get(route('ttr.validate', $record->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Not validated', $html);
        $this->assertSame(1, substr_count($html, 'Broken cell on Monday'));
        // Asserted without the apostrophe: Blade escapes it to &#039;.
        $this->assertSame(1, substr_count($html, 'is not offered to this section'));
        $this->assertSame(1, substr_count($html, 'Class overlap on Wednesday'));
    }

    public function test_index_withholds_pill_for_combined_dirty_record()
    {
        [$record] = $this->combinedFixture();

        $html = $this->actingAs($this->admin())
            ->get(route('tt.index'))
            ->assertOk()
            ->getContent();

        $needle = '<strong>' . $record->name . '</strong>';
        $this->assertSame(1, substr_count($html, $needle), 'Record name is not unique on index');
        $start = strpos($html, $needle);
        $view = strpos($html, 'records/show/' . $record->id, $start);
        $this->assertNotFalse($view, 'Record View link not rendered on index');
        $card = substr($html, $start, $view - $start);

        $this->assertStringNotContainsString('l81-status-validated', $card);
        $this->assertStringNotContainsString('>Validated<', $card);
    }

    public function test_slot_sync_preserves_out_of_stream_cell()
    {
        [$record, $out, $slot] = $this->combinedFixture();
        // The out-of-stream cell sits on $slot (Tue 9:40-10:20);
        // add an in-stream control cell on the same slot (Mon).
        $control = $this->cell($record->id, $slot->id,
            $this->subject('R12 SyncCtl ' . uniqid(), 13, $this->teacher('CTL'), null)->id,
            'Monday', 1, '2026-10-26 09:40:00', '2026-10-26 10:20:00');

        $outCell = TimeTable::where('ttr_id', $record->id)
            ->where('ts_id', $slot->id)->where('day', 'Tuesday')->firstOrFail();

        // New times must not collide with another slot of this
        // record (UNIQUE from/to/ttr_id) — 11:00-11:40 is free.
        $this->actingAs($this->admin())
            ->put(route('ts.update', $slot->id), [
                'ttr_id' => $record->id,
                'hour_from' => 11, 'min_from' => '00', 'meridian_from' => 'AM',
                'hour_to' => 11, 'min_to' => '40', 'meridian_to' => 'AM',
            ])
            ->assertRedirect(route('ttr.manage', $record->id));

        // Both cells survive with new times, date parts preserved.
        $this->assertDatabaseHas('time_tables', [
            'id' => $outCell->id,
            'timestamp_from' => strtotime('2026-10-27 11:00:00'),
            'timestamp_to' => strtotime('2026-10-27 11:40:00'),
        ]);
        $this->assertDatabaseHas('time_tables', [
            'id' => $control->id,
            'timestamp_from' => strtotime('2026-10-26 11:00:00'),
            'timestamp_to' => strtotime('2026-10-26 11:40:00'),
        ]);
        // And the stream error survives the sync — sync rewrites
        // times, it must not launder the cell into validity.
        $errors = TimetableValidator::report($record->fresh())['errors'];
        $this->assertCount(1, $this->errorsMatching($errors, 'is not offered to this section'));
    }
}
