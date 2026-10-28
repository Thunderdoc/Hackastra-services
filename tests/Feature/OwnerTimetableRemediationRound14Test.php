<?php

namespace Tests\Feature;

use App\Helpers\Qs;
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
 * Agent D round 14 — remediation loop end-to-end.
 *
 * Rounds 9–13 proved flagging on every surface (validator,
 * Validate page, index pill, Manage lists, slot-sync, week
 * view) but nobody proved the admin can CLEAR the flag: place
 * a dirty cell, watch every surface go red, delete the cell,
 * watch every surface go green again. A loop that cannot close
 * (stale error, pill that needs a regenerate) would leave the
 * admin stuck with a permanently flagged grid — that is what
 * this suite proves does NOT happen.
 *
 * Fixture is fully fresh: a pcb section in Class 12, one
 * in-stream subject and one commerce-only subject, each with
 * its own fresh live teacher (so no teacher clash and no
 * "no teacher assigned" error can pollute the loop), plus a
 * record that starts with exactly one clean in-stream cell.
 * All data rolls back.
 */
class OwnerTimetableRemediationRound14Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R14 Teacher ' . $tag,
            'email' => 'r14-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R14' . $tag . uniqid(),
        ]);
    }

    protected function subject(string $name, User $teacher, ?string $stream): Subject
    {
        return Subject::create([
            'name' => $name,
            'slug' => strtolower(preg_replace('/[^a-z0-9]+/', '-', $name)) . '-' . uniqid(),
            'my_class_id' => 13,
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

    /**
     * Returns [section, record, slotA, slotB, slotC, inSubject,
     * outSubject]. The record starts with exactly one clean
     * in-stream cell (Monday, slotA) — the loop's green baseline.
     */
    protected function loopFixture(): array
    {
        $section = Section::create([
            'name' => 'R14 Sec ' . uniqid(),
            'my_class_id' => 13,
            'teacher_id' => $this->teacher('SEC')->id,
            'stream' => 'pcb',
        ]);
        $record = TimeTableRecord::create([
            'name' => 'R14 Loop ' . uniqid(),
            'my_class_id' => 13,
            'section_id' => $section->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        // NOTE: time_slots UNIQUE(timestamp_from, timestamp_to,
        // ttr_id) — the three slots must differ in clock times.
        $slotA = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $slotB = $this->slot($record->id, '9:40 AM', '10:20 AM');
        $slotC = $this->slot($record->id, '10:35 AM', '11:15 AM');
        $in = $this->subject('R14 PCB ' . uniqid(), $this->teacher('IN'), 'pcb');
        $out = $this->subject('R14 Commerce ' . uniqid(), $this->teacher('OUT'), 'commerce');
        TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slotA->id,
            'day' => 'Monday', 'day_num' => 1, 'subject_id' => $in->id,
            'timestamp_from' => strtotime('2026-10-26 09:00:00'),
            'timestamp_to' => strtotime('2026-10-26 09:40:00'),
        ]);

        return [$section, $record, $slotA, $slotB, $slotC, $in, $out];
    }

    /**
     * Round-10 segment method: from the record's <strong> name
     * to its own View link (the pill sits between the two).
     * Callers must pass a record whose name occurs exactly once
     * on the page, so the segment cannot belong to another card.
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

    protected function assertClean(TimeTableRecord $record): void
    {
        $this->assertContains($record->id, TimetableValidator::cleanRecordIds($record->year));
    }

    protected function assertDirty(TimeTableRecord $record): void
    {
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds($record->year));
    }

    protected function assertPillShown(TimeTableRecord $record): void
    {
        $html = $this->actingAs($this->admin())->get(route('tt.index'))->getContent();
        $card = $this->indexCardSegment($html, $record->fresh());
        $this->assertStringContainsString('l81-status-validated', $card);
    }

    protected function assertPillWithheld(TimeTableRecord $record): void
    {
        $html = $this->actingAs($this->admin())->get(route('tt.index'))->getContent();
        $card = $this->indexCardSegment($html, $record->fresh());
        $this->assertStringNotContainsString('l81-status-validated', $card);
        $this->assertStringNotContainsString('>Validated<', $card);
    }

    public function test_stream_cell_remediation_loop_closes()
    {
        [$section, $record, $slotA, $slotB, $slotC, $in, $out] = $this->loopFixture();
        $admin = $this->admin();

        // (a) Green baseline: clean at the validator, on the
        // Validate page, and on the index card.
        $this->assertClean($record);
        $this->actingAs($admin)->get(route('ttr.validate', $record->id))
            ->assertOk()->assertSee('Validated — this timetable is clash-free.');
        $this->assertPillShown($record);

        // (b) Place the dirty cell through the real Manage
        // endpoint (round 9: the endpoint accepts any existing
        // subject_id — placement stays possible by design).
        $this->actingAs($admin)->post(route('tt.store'), [
            'ttr_id' => $record->id,
            'ts_id' => $slotB->id,
            'day' => 'Tuesday',
            'subject_id' => $out->id,
        ])->assertOk()->assertJson(['ok' => true]);
        $cell = TimeTable::where('ttr_id', $record->id)->where('subject_id', $out->id)->firstOrFail();

        // Every surface goes red: validator, Validate page,
        // index pill, and the Manage grid lists the dirty cell
        // (its <td> row — the round-9 filtered add-form options
        // exclude it, so the hit can only be the listed cell).
        $this->assertDirty($record);
        $this->actingAs($admin)->get(route('ttr.validate', $record->id))
            ->assertOk()->assertSee('Not validated')
            ->assertSee('is not offered to this section')
            ->assertSee($out->name);
        $this->assertPillWithheld($record);
        $this->actingAs($admin)->get(route('ttr.manage', $record->id))
            ->assertOk()->assertSee('<td>' . $out->name . '</td>', false);

        // (c) Delete the cell through the real Manage delete
        // path — no attendance exists for it, so the break-it
        // guard (submitted registers are locked history) cannot
        // interfere with this fresh cell.
        $this->actingAs($admin)->from(route('ttr.manage', $record->id))
            ->delete(route('tt.delete', $cell->id))
            ->assertRedirect(route('ttr.manage', $record->id));
        $this->assertDatabaseMissing('time_tables', ['id' => $cell->id]);

        // Every surface goes green again — no regenerate, no
        // stale error, no pill that needs a nudge.
        $this->assertClean($record);
        $this->actingAs($admin)->get(route('ttr.validate', $record->id))
            ->assertOk()->assertSee('Validated — this timetable is clash-free.');
        $this->assertPillShown($record);
        $this->actingAs($admin)->get(route('ttr.manage', $record->id))
            ->assertOk()->assertDontSee('<td>' . $out->name . '</td>', false);
    }

    public function test_broken_cell_remediation_loop_closes()
    {
        [$section, $record, $slotA, $slotB, $slotC, $in, $out] = $this->loopFixture();
        $admin = $this->admin();

        // The Manage store endpoint cannot create a broken cell
        // (slot times validate end > start), so broken cells
        // arrive the way round 4 found them — stamped by a slot
        // sync or direct data. The delete path is the same
        // tt.delete either way; this test proves the flag clears
        // through it exactly as the stream loop does.
        $cell = TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slotC->id,
            'day' => 'Wednesday', 'day_num' => 3, 'subject_id' => $in->id,
            'timestamp_from' => strtotime('2026-10-28 10:35:00'),
            'timestamp_to' => strtotime('2026-10-28 09:40:00'),
        ]);

        $this->assertDirty($record);
        $this->actingAs($admin)->get(route('ttr.validate', $record->id))
            ->assertOk()->assertSee('Not validated')
            ->assertSee('Broken cell on Wednesday');
        $this->assertPillWithheld($record);

        $this->actingAs($admin)->from(route('ttr.manage', $record->id))
            ->delete(route('tt.delete', $cell->id))
            ->assertRedirect(route('ttr.manage', $record->id));
        $this->assertDatabaseMissing('time_tables', ['id' => $cell->id]);

        $this->assertClean($record);
        $this->actingAs($admin)->get(route('ttr.validate', $record->id))
            ->assertOk()->assertSee('Validated — this timetable is clash-free.');
        $this->assertPillShown($record);
    }
}
