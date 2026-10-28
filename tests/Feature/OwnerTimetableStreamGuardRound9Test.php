<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Models\TimeSlot;
use App\Support\Streams;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 9 — stream guard on the Manage path.
 *
 * The generator respects Streams::appliesTo (a streamed section
 * grid covers only common + own-stream subjects), but the manual
 * Manage path did not: manage() passed ALL of the class's
 * subjects to the add/edit forms with no stream filter, the
 * store/update endpoints accepted any existing subject_id, and
 * the validator stayed silent about out-of-stream placed cells
 * (its coverage loop SKIPS out-of-stream subjects, and
 * recordErrors had no out-of-stream or cross-class error) — so
 * such a grid could even earn the green Validated pill.
 * Seeded grids are clean (0 out-of-stream, 0 cross-class cells),
 * so flagging these can never disturb existing data.
 *
 * Fixtures are resolved dynamically: a streamed section with a
 * section record, a same-class subject whose tags exclude the
 * section's stream, and a subject from another class entirely.
 */
class OwnerTimetableStreamGuardRound9Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** [section, record, out-of-stream subject of the same class]. */
    protected function streamedTriple(): array
    {
        foreach (Section::whereNotNull('stream')->where('stream', '<>', '')->get() as $section) {
            $record = TimeTableRecord::where('section_id', $section->id)->first();
            if (!$record) {
                continue;
            }
            foreach (Subject::where('my_class_id', $section->my_class_id)->get() as $subject) {
                if (!Streams::appliesTo($subject, $section->stream)) {
                    return [$section, $record, $subject];
                }
            }
        }
        $this->fail('No seeded streamed section with an out-of-stream same-class subject');
    }

    /** A (day, time slot) combo with no cell yet in the record. */
    protected function freeSlot(TimeTableRecord $record): array
    {
        // Seeded section grids are full, so the first slot is taken
        // on every weekday — scan every slot × day for a free combo.
        foreach (TimeSlot::where('ttr_id', $record->id)->get() as $slot) {
            foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
                $taken = TimeTable::where('ttr_id', $record->id)
                    ->where('ts_id', $slot->id)->where('day', $day)->exists();
                if (!$taken) {
                    return [$day, $slot];
                }
            }
        }
        $this->fail('No free (day, slot) combo in the record');
    }

    protected function placeCell(TimeTableRecord $record, Subject $subject): TimeTable
    {
        [$day, $slot] = $this->freeSlot($record);
        return TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'day' => $day,
            'subject_id' => $subject->id,
            'timestamp_from' => strtotime($day . ' ' . $slot->time_from),
            'timestamp_to' => strtotime($day . ' ' . $slot->time_to),
        ]);
    }

    public function test_manage_store_accepts_out_of_stream_subject()
    {
        // Setup proof, not the fix target: the endpoint takes any
        // existing subject_id (TTRequest only checks existence).
        [$section, $record, $subject] = $this->streamedTriple();
        [$day, $slot] = $this->freeSlot($record);

        $this->actingAs($this->admin())
            ->post(route('tt.store'), [
                'ttr_id' => $record->id,
                'ts_id' => $slot->id,
                'day' => $day,
                'subject_id' => $subject->id,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('time_tables', [
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'day' => $day,
            'subject_id' => $subject->id,
        ]);
    }

    public function test_validator_flags_out_of_stream_cell()
    {
        [$section, $record, $subject] = $this->streamedTriple();
        $this->placeCell($record, $subject);

        $report = TimetableValidator::report($record->fresh());

        $this->assertNotEmpty($report['errors']);
        $this->assertStringContainsString($subject->name, implode(' ', $report['errors']));
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds($record->year));
    }

    public function test_validator_flags_cross_class_cell()
    {
        [$section, $record, $subject] = $this->streamedTriple();
        $foreign = Subject::where('my_class_id', '<>', $record->my_class_id)->firstOrFail();
        $this->placeCell($record, $foreign);

        $report = TimetableValidator::report($record->fresh());

        $this->assertNotEmpty($report['errors']);
        $this->assertStringContainsString($foreign->name, implode(' ', $report['errors']));
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds($record->year));
    }

    public function test_validator_still_clean_for_in_stream_cell()
    {
        [$section, $record, $subject] = $this->streamedTriple();
        $inStream = Subject::where('my_class_id', $section->my_class_id)->get()
            ->first(fn ($s) => Streams::appliesTo($s, $section->stream));
        $this->assertNotNull($inStream, 'No in-stream subject for the section');

        // The record's seeded cells are clean; adding one more
        // in-stream cell on a free slot must not flag the record
        // for stream reasons (other errors, e.g. a clash with the
        // subject's teacher elsewhere, are out of this test's
        // scope — assert only that no stream error appears).
        $this->placeCell($record, $inStream);
        $report = TimetableValidator::report($record->fresh());
        foreach ($report['errors'] as $error) {
            $this->assertStringNotContainsString('not offered', $error);
            $this->assertStringNotContainsString('belongs to another class', $error);
        }
    }

    public function test_manage_page_offers_only_stream_subjects()
    {
        [$section, $record, $subject] = $this->streamedTriple();

        $response = $this->actingAs($this->admin())
            ->get(route('ttr.manage', $record->id));
        $response->assertOk();
        // The out-of-stream subject must not be offered as an
        // option on this section's Manage page...
        $response->assertDontSee('value="' . $subject->id . '"', false);
        // ...while an in-stream subject of the class still is.
        $inStream = Subject::where('my_class_id', $section->my_class_id)->get()
            ->first(fn ($s) => Streams::appliesTo($s, $section->stream));
        $response->assertSee('value="' . $inStream->id . '"', false);
    }
}
