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
 * Agent D round 10 — round-9 stream errors end-to-end to the UI.
 *
 * Round 9 proved the validator layer: an out-of-stream or
 * cross-class cell placed in a streamed section's grid now
 * errors in recordErrors, and manage() no longer offers such
 * subjects. What round 9 did NOT prove is that the UI surfaces
 * it: the Validate page must render the error text, the tt.index
 * card must withhold the green Validated pill (it derives from
 * cleanRecordIds, i.e. the same recordErrors — shared by
 * construction, but proven here, not assumed), and the Manage
 * page must still list the placed cell (visibility, not silent
 * deletion — placement stays possible by design).
 *
 * Fixtures mirror round 9 (dynamic streamed triple + free slot).
 */
class OwnerTimetableStreamGuardRound10Test extends TestCase
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

    /**
     * The index card segment for one record: from its <strong> name
     * to its own View link (the first records/show/ URL after the
     * name — the pill sits between the two). Callers must pass a
     * record whose name occurs exactly once on the page, so the
     * segment cannot belong to a same-named card.
     */
    protected function indexCardSegment(string $html, TimeTableRecord $record): string
    {
        $needle = '<strong>' . $record->name . '</strong>';
        $this->assertSame(1, substr_count($html, $needle), 'Record name is not unique on index');
        $start = strpos($html, $needle);
        $view = strpos($html, 'records/show/' . $record->id, $start);
        $this->assertNotFalse($view, 'Record View link not rendered on index');
        return substr($html, $start, $view - $start);
    }

    /** A streamed triple whose record is current-session, clean, + uniquely named on the index. */
    protected function uniqueStreamedTriple(string $indexHtml): array
    {
        $session = \App\Helpers\Qs::getCurrentSession();
        $cleanIds = TimetableValidator::cleanRecordIds($session);
        foreach (Section::whereNotNull('stream')->where('stream', '<>', '')->get() as $section) {
            $record = TimeTableRecord::where('section_id', $section->id)->first();
            if (!$record || $record->year !== $session || !in_array($record->id, $cleanIds, true)) {
                continue;
            }
            if (substr_count($indexHtml, '<strong>' . $record->name . '</strong>') !== 1) {
                continue;
            }
            foreach (Subject::where('my_class_id', $section->my_class_id)->get() as $subject) {
                if (!Streams::appliesTo($subject, $section->stream)) {
                    return [$section, $record, $subject];
                }
            }
        }
        $this->fail('No uniquely-named current-session streamed section with an out-of-stream subject');
    }

    public function test_validate_page_renders_stream_error_text()
    {
        [$section, $record, $subject] = $this->streamedTriple();
        $this->placeCell($record, $subject);

        $this->actingAs($this->admin())
            ->get(route('ttr.validate', $record->id))
            ->assertOk()
            ->assertSee('Not validated')
            ->assertSee("is not offered to this section's stream")
            ->assertSee($subject->name);
    }

    public function test_validate_page_renders_cross_class_error_text()
    {
        [$section, $record, $subject] = $this->streamedTriple();
        $foreign = Subject::where('my_class_id', '<>', $record->my_class_id)->firstOrFail();
        $this->placeCell($record, $foreign);

        $this->actingAs($this->admin())
            ->get(route('ttr.validate', $record->id))
            ->assertOk()
            ->assertSee('Not validated')
            ->assertSee('belongs to another class')
            ->assertSee($foreign->name);
    }

    public function test_index_withholds_green_pill_for_dirty_record()
    {
        $admin = $this->admin();
        $before = $this->actingAs($admin)->get(route('tt.index'))->getContent();
        [$section, $record, $subject] = $this->uniqueStreamedTriple($before);
        // The record must start clean, so the withheld pill is
        // attributable to the placed cell and nothing else.
        $this->assertContains($record->id, TimetableValidator::cleanRecordIds($record->year));
        $this->placeCell($record, $subject);
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds($record->year));

        $html = $this->actingAs($admin)->get(route('tt.index'))->getContent();
        $card = $this->indexCardSegment($html, $record->fresh());
        $this->assertStringNotContainsString('l81-status-validated', $card);
        $this->assertStringNotContainsString('>Validated<', $card);
    }

    public function test_index_still_shows_green_pill_for_clean_record()
    {
        // Control: a record the validator itself calls clean, in
        // the current session, uniquely named on the page, must
        // still earn its pill — the round-9 change must not have
        // blanked the pill globally.
        $session = \App\Helpers\Qs::getCurrentSession();
        $html = $this->actingAs($this->admin())->get(route('tt.index'))->getContent();
        $clean = null;
        foreach (TimetableValidator::cleanRecordIds($session) as $id) {
            $candidate = TimeTableRecord::find($id);
            if ($candidate && $candidate->year === $session
                && substr_count($html, '<strong>' . $candidate->name . '</strong>') === 1) {
                $clean = $candidate;
                break;
            }
        }
        $this->assertNotNull($clean, 'No uniquely-named clean current-session record');

        $card = $this->indexCardSegment($html, $clean);
        $this->assertStringContainsString('l81-status-validated', $card);
    }

    public function test_manage_page_still_lists_placed_out_of_stream_cell()
    {
        [$section, $record, $subject] = $this->streamedTriple();
        $this->placeCell($record, $subject);

        // The cell row renders the subject name in a <td>; the
        // add-form options (round-9 filtered) must NOT contain it,
        // so a <td> hit can only come from the listed cell.
        $this->actingAs($this->admin())
            ->get(route('ttr.manage', $record->id))
            ->assertOk()
            ->assertSee('<td>' . $subject->name . '</td>', false)
            ->assertDontSee('value="' . $subject->id . '"', false);
    }
}
