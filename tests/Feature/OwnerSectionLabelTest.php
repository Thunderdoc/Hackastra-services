<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\LeaveCover;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Section labels (owner, 2026-11-06: "bahut jagah par section nahi
 * dikh raha"): a whole-class timetable row must read "All sections"
 * — the label the timetable print/show pages already use — never a
 * bare dash or a blank. Pins the shared weekGrid enumeration (which
 * feeds Workload Analysis and the leave queue), the workload page
 * itself, and the teacher home today table (whose always-dash Room
 * column was hardcoded and is now gone).
 */
class OwnerSectionLabelTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /** One whole-class cell for a subject the demo teacher teaches. */
    protected function wholeClassCellForTeacher(): array
    {
        $teacher = $this->teacher();
        $subject = Subject::where('teacher_id', $teacher->id)->orderBy('id')->firstOrFail();

        $ttr = TimeTableRecord::create([
            'name' => 'Section Label Probe (whole class)',
            'my_class_id' => $subject->my_class_id,
            'section_id' => null,
            'exam_id' => null,
            'year' => '2026-2027',
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $ttr->id,
            'timestamp_from' => strtotime('9:00 AM'),
            'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
            'time_from' => '9:00 AM',
            'time_to' => '9:40 AM',
            'hour_from' => '9', 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => '9', 'min_to' => '40', 'meridian_to' => 'AM',
        ]);
        TimeTable::create([
            'ttr_id' => $ttr->id,
            'ts_id' => $slot->id,
            'day' => 'Monday',
            'subject_id' => $subject->id,
            'timestamp_from' => (string) strtotime('9:00 AM'),
            'timestamp_to' => (string) strtotime('9:40 AM'),
        ]);

        return [$subject, $ttr];
    }

    public function test_week_grid_labels_whole_class_rows_all_sections()
    {
        [$subject] = $this->wholeClassCellForTeacher();

        $rows = collect(LeaveCover::weekGrid($this->teacher()))
            ->where('subject', $subject->name)
            ->where('day', 'Monday');

        $this->assertTrue(
            $rows->contains(function ($row) {
                return $row['section'] === 'All sections';
            }),
            'a whole-class row must be labelled All sections in the week grid'
        );
        $this->assertFalse(
            $rows->contains(function ($row) {
                return $row['section'] === '—';
            }),
            'no timetable row may carry a bare dash as its section'
        );
    }

    public function test_workload_page_shows_all_sections_for_whole_class_rows()
    {
        $this->wholeClassCellForTeacher();

        $this->actingAs($this->teacher())->get(route('tt.workload'))
            ->assertOk()
            ->assertSee('All sections')
            ->assertDontSee('Section All sections')
            ->assertDontSee('Section —');
    }

    public function test_teacher_home_today_table_has_no_fake_room_column()
    {
        $blade = file_get_contents(resource_path('views/pages/teacher/home.blade.php'));
        $this->assertStringNotContainsString('<td>—</td>', $blade);
        $this->assertStringContainsString('All sections', $blade);
    }
}
