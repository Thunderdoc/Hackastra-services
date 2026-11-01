<?php

namespace Tests\Feature;

use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner report 2026-10-30: the period list showed "Class 9 — 240
 * students" with no hint that the period's timetable record is a
 * WHOLE-CLASS record (Prep–Class 10 in the demo data), while
 * section records (Class 11/12) correctly showed their section
 * and its 40. The count was never wrong — the section counts
 * come from attachStudentCounts — but a whole-class row now
 * says so ("· All sections") instead of looking like a section
 * row that lost its letter. Per-section strength everywhere
 * arrives with the per-section timetable records themselves.
 *
 * Section segment (2026-10-30): the seed's records are ALL
 * per-section now, so the whole-class labelling property is
 * exercised against a fixture record built in-test — the label
 * logic itself is unchanged.
 */
class OwnerPeriodListSectionLabelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_whole_class_periods_are_labelled_all_sections()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();

        // A whole-class (sectionless) approved record holding one
        // Friday cell of Ananya's direct Class 3 English (19).
        $record = TimeTableRecord::create([
            'name' => 'Class 3 Whole-Class Probe (Period Label)',
            'my_class_id' => 4,
            'section_id' => null,
            'exam_id' => null,
            'year' => '2026-2027',
            'approval_status' => 'approved',
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'timestamp_from' => strtotime('9:00 AM'),
            'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
            'time_from' => '9:00 AM',
            'time_to' => '9:40 AM',
            'hour_from' => '9', 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => '9', 'min_to' => '40', 'meridian_to' => 'AM',
        ]);
        TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'day' => 'Friday',
            'subject_id' => 19,
            'timestamp_from' => (string) strtotime('9:00 AM'),
            'timestamp_to' => (string) strtotime('9:40 AM'),
        ]);

        // 2026-10-30 is a Friday.
        $page = $this->actingAs($teacher)->get(route('attendance.periods.index') . '?date=2026-10-30');
        $page->assertOk();
        $page->assertSee('All sections');
    }

    public function test_section_period_shows_its_section_strength_not_the_class_total()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        // 2026-10-12 is a Monday: her own grid carries a Class 12
        // section period (section 76 English, where she is the
        // effective teacher), and the date is one of the seed's
        // session-free probe days, so the row always renders. The
        // old version used Friday 2026-10-30, where her own grid
        // has NO Class 12 cell — the row it asserted used to be
        // supplied accidentally by another test's substitution
        // fixture, and vanished whenever suite order shifted
        // (standalone it could never pass at all).
        $page = $this->actingAs($teacher)->get(route('attendance.periods.index') . '?date=2026-10-12');
        $page->assertOk();
        // A Class 12 section row exists on her Monday list and its
        // Students figure is the section's 40, never the class's 520.
        $page->assertSee('Class 12');
        $this->assertStringNotContainsString('>520<', $page->getContent());
    }
}
