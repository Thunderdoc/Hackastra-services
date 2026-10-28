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
 * Agent D round 23 — broken cells on the two remaining admin surfaces.
 *
 * Manage proved the broken flag in round 22; Validate + index pill
 * were proved for STREAM errors in round 10 but never for BROKEN
 * cells. Fixture (all fresh, rolled back): a whole-class record
 * with ONE broken cell (direct data — Manage store refuses
 * end <= start) + one clean cell, and a fully clean control
 * record, both in the current session so tt.index lists them.
 *
 * (a) Validate page renders the exact broken-cell error text and
 * the "Not validated" banner, with NO green "Validated" banner
 * for the dirty record; the clean record gets the green banner.
 * (b) tt.index withholds the green Validated pill from the dirty
 * record's card (segment-anchored, round-10 method — the pill
 * must be absent from that record's card segment, not merely
 * absent from the whole page) while the clean record's card
 * KEEPS its green pill. A green banner/pill on the dirty record
 * would mean the validator and the pill computation disagree;
 * both surfaces agree with report() here, so these tests lock
 * current behaviour.
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableValidatePillRound23Test extends TestCase
{
    use DatabaseTransactions;

    protected const BROKEN_ERROR = 'Broken cell on Monday (10:20 AM - 9:40 AM): the period ends at or before it starts — fix or delete it on the Manage page.';

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R23 Teacher ' . $tag,
            'email' => 'r23-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R23' . $tag . uniqid(),
        ]);
    }

    /** Fresh whole-class record + slot + subject; $broken adds the inverted Monday cell. */
    protected function fixture(bool $broken): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'R23 Class ' . $u]);
        $teacher = $this->teacher($u);
        $subject = Subject::create([
            'name' => 'R23Subj' . $u, 'slug' => 'r23-subj-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $record = TimeTableRecord::create([
            'name' => 'R23 Record ' . $u, 'my_class_id' => $class->id,
            'section_id' => null, 'exam_id' => null, 'year' => Qs::getCurrentSession(),
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        if ($broken) {
            // Broken cell by direct data: Monday 2026-11-02, 10:20 AM -> 9:40 AM.
            $cell = TimeTable::create([
                'ttr_id' => $record->id, 'ts_id' => $slot->id,
                'day' => 'Monday', 'day_num' => 1, 'subject_id' => $subject->id,
                'timestamp_from' => strtotime('2026-11-02 10:20 AM'),
                'timestamp_to' => strtotime('2026-11-02 9:40 AM'),
            ]);
            $this->assertTrue(TimetableValidator::isBrokenCell($cell->fresh()));
        }
        // Clean control cell: same slot, Tuesday, valid times.
        TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot->id,
            'day' => 'Tuesday', 'day_num' => 2, 'subject_id' => $subject->id,
            'timestamp_from' => strtotime('2026-11-03 9:00 AM'),
            'timestamp_to' => strtotime('2026-11-03 9:40 AM'),
        ]);

        $report = TimetableValidator::report($record->fresh());
        if ($broken) {
            $this->assertSame([self::BROKEN_ERROR], $report['errors'], 'Fixture shape: exactly one error, the broken cell');
            $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()));
        } else {
            $this->assertSame([], $report['errors'], 'Fixture shape: clean record, zero errors');
            $this->assertContains($record->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()));
        }

        return [$record, $subject, $teacher];
    }

    /** Card segment for one record on tt.index: from its name to the next card (or page end). */
    protected function cardSegment(string $html, string $recordName): string
    {
        $start = strpos($html, $recordName);
        $this->assertNotFalse($start, "Record {$recordName} listed on tt.index");
        $next = strpos($html, '<div class="ttc-record">', $start + 1);
        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    /** (a) Validate page: exact broken error + "Not validated" banner, no green banner. */
    public function test_validate_page_renders_broken_cell_error_and_not_validated_banner()
    {
        [$record] = $this->fixture(true);

        $html = $this->actingAs($this->admin())
            ->get(route('ttr.validate', $record->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::BROKEN_ERROR, $html);
        $this->assertStringContainsString('Not validated — 1 blocking error(s) below.', $html);
        $this->assertStringNotContainsString('Validated — this timetable is clash-free.', $html);
    }

    /** (a control) Validate page: clean record gets the green banner, no red banner. */
    public function test_validate_page_clean_record_shows_validated_banner()
    {
        [$record] = $this->fixture(false);

        $html = $this->actingAs($this->admin())
            ->get(route('ttr.validate', $record->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Validated — this timetable is clash-free.', $html);
        $this->assertStringNotContainsString('Not validated —', $html);
    }

    /** (b) tt.index: pill withheld for the broken record's card, kept for the clean record's card. */
    public function test_index_pill_withheld_for_broken_record_and_kept_for_clean()
    {
        [$dirty] = $this->fixture(true);
        [$clean] = $this->fixture(false);

        $html = $this->actingAs($this->admin())
            ->get(route('tt.index'))
            ->assertOk()
            ->getContent();

        $dirtySegment = $this->cardSegment($html, $dirty->name);
        $this->assertStringNotContainsString(
            'l81-status-validated', $dirtySegment,
            'Dirty record card must not carry the green Validated pill'
        );

        $cleanSegment = $this->cardSegment($html, $clean->name);
        $this->assertStringContainsString(
            'l81-status-validated', $cleanSegment,
            'Clean record card keeps the green Validated pill'
        );
    }
}
