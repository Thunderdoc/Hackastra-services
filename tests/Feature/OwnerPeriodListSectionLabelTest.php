<?php

namespace Tests\Feature;

use App\User;
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
 */
class OwnerPeriodListSectionLabelTest extends TestCase
{
    public function test_whole_class_periods_are_labelled_all_sections()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        // 2026-10-30 is a Friday; Ananya has whole-class English
        // periods (e.g. Class 3) and section periods (Class 12).
        $page = $this->actingAs($teacher)->get(route('attendance.periods.index') . '?date=2026-10-30');
        $page->assertOk();
        $page->assertSee('All sections');
    }

    public function test_section_period_shows_its_section_strength_not_the_class_total()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $page = $this->actingAs($teacher)->get(route('attendance.periods.index') . '?date=2026-10-30');
        $page->assertOk();
        // A Class 12 section row exists on her Friday list and its
        // Students figure is the section's 40, never the class's 520.
        $page->assertSee('Class 12');
        $this->assertStringNotContainsString('>520<', $page->getContent());
    }
}
