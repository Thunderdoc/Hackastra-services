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
 * Agent D round 20 — Manage/validator consistency on the
 * whole-class vs section-grid boundary (the one stream-guard
 * seam never probed from the Manage side).
 *
 * Settled design (rounds 9/10): the validator errors on
 * out-of-stream cells in STREAMED section grids, and whole-class
 * grids (section_id NULL) SKIP the stream check; the Manage
 * add-form is filtered via Streams::subjectsForSection, which
 * returns every subject for an untagged section or a null
 * section. Placement itself stays possible everywhere (an admin
 * act); only validation differs. This suite proves the two
 * boundaries AGREE — the add-form must not offer what the
 * validator would flag on a section grid, and must not hide
 * what the validator permits on a whole-class grid.
 *
 * Plus one observation, locked not fixed: the generator's
 * DAILY teacher cap (max_teacher_periods_per_day) is a
 * generation-time constraint only. Manual Manage placement
 * does not consult it — an admin placing two cells for one
 * teacher on one day succeeds silently. That may be by design
 * (manual placement is an explicit admin act); it is reported
 * as an observation, and no cap check is invented here.
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableManageStreamBoundaryRound20Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R20 Teacher ' . $tag,
            'email' => 'r20-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R20' . $tag . uniqid(),
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

    /** Fresh class + common subject + pcm-tagged subject + record. Section null = whole-class, else section grid. */
    protected function fixture(?string $sectionStream): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'R20 Class ' . $u]);
        $teacher = $this->teacher($u);
        $common = Subject::create([
            'name' => 'R20Common' . $u, 'slug' => 'r20-common-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $tagged = Subject::create([
            'name' => 'R20Pcm' . $u, 'slug' => 'r20-pcm-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6, 'stream' => 'pcm',
        ]);
        $section = null;
        if ($sectionStream !== null) {
            $section = Section::create([
                'name' => 'R20Sec' . $u, 'my_class_id' => $class->id,
                'active' => 1, 'teacher_id' => $teacher->id, 'stream' => $sectionStream,
            ]);
        }
        $record = TimeTableRecord::create([
            'name' => 'R20 Record ' . $u, 'my_class_id' => $class->id,
            'section_id' => $section ? $section->id : null,
            'exam_id' => null, 'year' => Qs::getCurrentSession(),
        ]);
        $slot1 = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $slot2 = $this->slot($record->id, '9:40 AM', '10:20 AM');

        return [$record, $common, $tagged, $teacher, $slot1, $slot2];
    }

    /** Manage add-form for a WHOLE-CLASS grid lists the stream-tagged subject too. */
    public function test_whole_class_manage_form_lists_stream_tagged_subject()
    {
        [$record, $common, $tagged] = $this->fixture(null);

        $html = $this->actingAs($this->admin())
            ->get(route('ttr.manage', $record->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString($common->name, $html);
        $this->assertStringContainsString($tagged->name, $html);
    }

    /** Manage add-form for a STREAMED (pcb) section grid EXCLUDES the pcm-only subject. */
    public function test_section_manage_form_excludes_out_of_stream_subject()
    {
        [$record, $common, $tagged] = $this->fixture('pcb');

        $html = $this->actingAs($this->admin())
            ->get(route('ttr.manage', $record->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString($common->name, $html);
        $this->assertStringNotContainsString($tagged->name, $html);
    }

    /** Whole-class grid: Manage store places the tagged subject AND the validator stays green. */
    public function test_whole_class_manage_store_places_tagged_subject_and_validator_stays_green()
    {
        [$record, $common, $tagged, $teacher, $slot1] = $this->fixture(null);

        $resp = $this->actingAs($this->admin())->post(route('tt.store'), [
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'subject_id' => $tagged->id,
        ]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('time_tables', [
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'subject_id' => $tagged->id,
        ]);

        $report = TimetableValidator::report($record->fresh());
        $this->assertSame([], $report['errors'], 'Whole-class grid must not stream-flag a placed subject');
        $this->assertContains(
            $record->id,
            TimetableValidator::cleanRecordIds(Qs::getCurrentSession()),
            'Whole-class record with a tagged cell must keep its green Validated pill'
        );
    }

    /** Control: section grid via the SAME store path places the cell but the validator flags it. */
    public function test_section_manage_store_places_but_validator_flags_out_of_stream()
    {
        [$record, $common, $tagged, $teacher, $slot1] = $this->fixture('pcb');

        $resp = $this->actingAs($this->admin())->post(route('tt.store'), [
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'subject_id' => $tagged->id,
        ]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('time_tables', [
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'subject_id' => $tagged->id,
        ]);

        $report = TimetableValidator::report($record->fresh());
        $streamErrors = array_values(array_filter($report['errors'], fn ($e) => str_contains($e, "is not offered to this section's stream")));
        $this->assertCount(1, $streamErrors, 'Section grid must carry exactly one stream error for the placed cell');
        $this->assertNotContains(
            $record->id,
            TimetableValidator::cleanRecordIds(Qs::getCurrentSession()),
            'Flagged section record must lose its green Validated pill'
        );
    }

    /**
     * OBSERVATION (locked, not fixed): Manage store does NOT
     * consult the generator's daily teacher cap. One teacher,
     * two Monday cells via the store path: both succeed, no
     * error, no day-cap warning anywhere in the report. The
     * weekly-cap warning (default 40/week) is a different,
     * report-level rule and stays silent at 2 periods.
     */
    public function test_manage_store_ignores_generator_daily_cap_observation()
    {
        [$record, $common, $tagged, $teacher, $slot1, $slot2] = $this->fixture(null);

        foreach ([[$slot1, $common], [$slot2, $tagged]] as [$slot, $subject]) {
            $this->actingAs($this->admin())->post(route('tt.store'), [
                'ttr_id' => $record->id, 'ts_id' => $slot->id,
                'day' => 'Monday', 'subject_id' => $subject->id,
            ])->assertOk()->assertJson(['ok' => true]);
        }
        $this->assertSame(2, TimeTable::where('ttr_id', $record->id)->where('day', 'Monday')->count());

        $report = TimetableValidator::report($record->fresh());
        $this->assertSame([], $report['errors']);
        foreach ($report['warnings'] as $warning) {
            $this->assertStringNotContainsStringIgnoringCase('Monday', $warning, 'No day-cap warning exists in the report');
            $this->assertStringNotContainsStringIgnoringCase('daily cap', $warning);
        }
    }
}
