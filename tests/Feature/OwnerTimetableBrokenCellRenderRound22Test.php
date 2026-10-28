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
 * Agent D round 22 — broken cells on the RENDERED surfaces.
 *
 * Rounds 3/21 proved report() + slot-sync behaviour; the rendered
 * pages with a broken cell were never probed. Fixture (all fresh,
 * rolled back): a whole-class record with ONE broken cell (direct
 * data — Manage store refuses end <= start) + one clean cell, both
 * on the same slot, different days, subject taught directly by the
 * probe teacher so teacherWeekData picks both up.
 *
 * (a) Manage page (Edit tab) flags the broken row: banner +
 * table-danger row + "Broken period" badge, exactly once each.
 * The row shows the SLOT time (time_slot->full), not the cell's
 * broken timestamps — the badge is the marker, and it is present.
 *
 * (b)/(c) Teacher week view (screen + print) renders the broken
 * cell as a NORMAL period at its slot time with NO broken marker:
 * teacherWeekData keys the grid by slot->full, so the cell's own
 * inverted timestamps never surface there. This is an OBSERVATION,
 * not a defect, and NOT fixed: the week views are slot-driven
 * (the slot IS the scheduled period), the cell renders (not
 * dropped, not mislabelled — subject + class + slot time are all
 * correct), and the broken flag lives where repair happens
 * (Manage + Validate, admin surfaces). If the owner ever wants a
 * teacher-facing broken marker, these tests must be updated.
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableBrokenCellRenderRound22Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R22 Teacher ' . $tag,
            'email' => 'r22-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R22' . $tag . uniqid(),
        ]);
    }

    /** Fresh whole-class record + slot + subject taught by the probe teacher + broken/clean cells. */
    protected function fixture(): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'R22 Class ' . $u]);
        $teacher = $this->teacher($u);
        $subject = Subject::create([
            'name' => 'R22Subj' . $u, 'slug' => 'r22-subj-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $record = TimeTableRecord::create([
            'name' => 'R22 Record ' . $u, 'my_class_id' => $class->id,
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
        // Broken cell by direct data: Monday 2026-11-02, 10:20 AM -> 9:40 AM.
        $broken = TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot->id,
            'day' => 'Monday', 'day_num' => 1, 'subject_id' => $subject->id,
            'timestamp_from' => strtotime('2026-11-02 10:20 AM'),
            'timestamp_to' => strtotime('2026-11-02 9:40 AM'),
        ]);
        // Clean control cell: same slot, Tuesday, valid times.
        TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot->id,
            'day' => 'Tuesday', 'day_num' => 2, 'subject_id' => $subject->id,
            'timestamp_from' => strtotime('2026-11-03 9:00 AM'),
            'timestamp_to' => strtotime('2026-11-03 9:40 AM'),
        ]);

        $this->assertTrue(TimetableValidator::isBrokenCell($broken->fresh()));
        $report = TimetableValidator::report($record->fresh());
        $this->assertCount(1, $report['errors'], 'Fixture shape: exactly one error, the broken cell');
        $this->assertSame(
            'Broken cell on Monday (10:20 AM - 9:40 AM): the period ends at or before it starts — fix or delete it on the Manage page.',
            $report['errors'][0]
        );

        return [$record, $subject, $teacher];
    }

    /** (a) Manage page flags exactly the broken row: banner + danger row + badge, once each. */
    public function test_manage_page_flags_broken_cell_row()
    {
        [$record, $subject, $teacher] = $this->fixture();

        $html = $this->actingAs($this->admin())
            ->get(route('ttr.manage', $record->id))
            ->assertOk()
            ->getContent();

        // Banner (Edit tab): exact wording from subjects/edit.blade.php.
        $this->assertStringContainsString('broken period(s) in this record', $html);
        $this->assertStringContainsString('so it can never be taught', $html);
        // Badge + danger row: exactly one each — the clean Tuesday row is unflagged.
        // ('Broken period' capital-B occurs only in the badge; the banner is lowercase.)
        $this->assertSame(1, substr_count($html, 'Broken period'), 'Badge on exactly one row');
        $this->assertSame(1, substr_count($html, 'table-danger'), 'Danger class on exactly one row');
        $this->assertStringContainsString($subject->name, $html);
    }

    /** (b) Teacher week screen renders the broken cell as a normal slot-time period, no marker. */
    public function test_teacher_week_view_renders_broken_cell_without_marker()
    {
        [$record, $subject, $teacher] = $this->fixture();

        $html = $this->actingAs($teacher)
            ->get(route('tt.mine'))
            ->assertOk()
            ->getContent();

        // The cell is present (not dropped): subject + slot time render.
        $this->assertStringContainsString($subject->name, $html);
        $this->assertStringContainsString('9:00 AM - 9:40 AM', $html);
        // …but no broken marker anywhere: week views are slot-driven
        // (teacherWeekData keys the grid by slot->full), so the cell's
        // own inverted timestamps never surface here. Observation, not
        // a defect — see the class docblock.
        $this->assertStringNotContainsString('Broken period', $html);
        $this->assertStringNotContainsString('table-danger', $html);
    }

    /** (c) Teacher week print renders the same way: present, normal, no marker. */
    public function test_teacher_week_print_renders_broken_cell_without_marker()
    {
        [$record, $subject, $teacher] = $this->fixture();

        $html = $this->actingAs($teacher)
            ->get(route('tt.mine_print'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($subject->name, $html);
        $this->assertStringContainsString('9:00 AM - 9:40 AM', $html);
        $this->assertStringNotContainsString('Broken period', $html);
        $this->assertStringNotContainsString('table-danger', $html);
    }
}
